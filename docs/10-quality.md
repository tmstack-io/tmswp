# 10. QA・CI・開発ツーリング設計書

## 目的

TMStack WordPress Framework の monorepo 全体に適用する品質保証体制を確定する。
本書は以下を「人間・CI・AI エージェントが同一コマンドで実行できる」形で定義する(DR-12)。

- PHPStan(level max・baseline なし)の完全な設定と運用規律
- php-cs-fixer(PER-CS 2.0 + strict_types + final_class)の完全な設定
- PHPUnit による 2 層テスト(ユニット = PHPUnit 11・WP 非依存 / 統合 = wp-env + wp-env が取得する WordPress のテストライブラリ・PHPUnit 9.6 隔離。§4.2 で確定)の構成
- composer スクリプト体系(`test` / `lint` / `fix` / `analyse` / `qa` ほか)
- GitHub Actionsワークフロー(`qa.yml` / `split.yml`)と、同期先でプルリクエストを閉じるワークフローの正本(`.github/split/close-pull-request.yml`)
- monorepo-builderによるパッケージ間バージョン整合と、リリースのワーカー(`tools/release/`)
- 旧 iwf2b テスト資産の「仕様」移植対応表
- カバレッジ方針と開発環境セットアップ手順

## スコープ(対象パッケージ)

monorepo ルート(リポジトリ `tmswp`)直下の QA 設定ファイル一式と、`packages/*` 全パッケージ・`starters/*` のテスト/解析対象規定。
個々のテストケースの中身(何を検証するか)は各機能文書が定義し、本書は「テストをどこに置き、どう実行し、どう CI に載せるか」と「旧資産からの仕様移植リスト」を定義する。

本書が定義するファイル(すべて monorepo ルート基準):

```
composer.json                     … ルート(手で維持する。1.1)
phpstan.neon.dist
.php-cs-fixer.dist.php
phpunit.unit.xml.dist
phpunit.integration.xml.dist
.wp-env.json
monorepo-builder.php
tools/release/                    … リリースのワーカー(6.1)
vendor-bin/wp-tests/composer.json … 統合テスト用 PHPUnit 9.6 隔離環境
tests/bootstrap-unit.php
tests/bootstrap-integration.php
tests/wp-tests-config.php
bin/coverage-check.php
.github/workflows/qa.yml
.github/workflows/split.yml
.github/split/close-pull-request.yml … 同期先でプルリクエストを閉じるワークフローの正本(5.2)
tools/ci/split-check.sh           … split相当の確認(5.1)
packages/*/phpunit.xml.dist          … パッケージを単独で取り出したときのユニットテスト(4.3)
packages/*/.gitattributes            … 配布物から外すファイル(6.2)
packages/core/tests/bootstrap.php
packages/testing/src/UnitTestCase.php (TMSWP\Testing\UnitTestCase)
```

## 依存文書

- 00 全体アーキテクチャ(monorepo レイアウト、パッケージ一覧、DR 一覧)
- 01 Kernel / Discovery(統合テストでの Kernel ブート方法)
- 04 meta/option 定義層 — **FW 同梱 PHPStan 拡張(`packages/core/phpstan/extension.neon` とその services 定義)の正準仕様**。本書は登録方法のみを定める
- 05 フォーム層(Rule 群の正準クラス名。移植対応表のテスト対象名はこれに従う)
- 06 Model 層(QueryBuilder / Migrator の新規テスト要件)
- 07〜 各拡張パッケージ文書(thumbnail / settings-page / acf-bridge / ja-toolkit)
- DR-13 担当の AI 資料・スターター文書(スターター側 QA 設定の正準。本書は最小要件のみ規定)

---

# 設計本体

## 1. QA コマンド体系(正準)

人間・CI・AI が使うコマンドは composer スクリプトに一本化する。**CI も AI も、ここに列挙したコマンド以外の方法で QA を実行してはならない。**

| コマンド | 内容 | Docker 要否 |
|---|---|---|
| `composer lint` | php-cs-fixer dry-run(差分表示、変更なし)。下限の PHP 8.3 で実行する(1.1 補足) | 不要 |
| `composer fix` | php-cs-fixer 適用 | 不要 |
| `composer analyse` | PHPStan level max | 不要 |
| `composer test:unit` | ユニットテスト(PHPUnit 11 + Brain Monkey) | 不要 |
| `composer test:coverage` | `test:coverage:unit` → `test:coverage:integration` → `test:coverage:check`(wp-env を `wp:start:coverage` で起動しておく) | **必要** |
| `composer test:coverage:unit` | ユニットテスト + pcov で clover を `var/coverage/unit.xml` に出力 | 不要 |
| `composer test:coverage:integration` | 統合テスト + Xdebug で clover を `var/coverage/integration.xml` に出力(wp-env の `cli` コンテナ内) | **必要** |
| `composer test:coverage:check` | 2つのcloverを行単位で合算し、全体とパッケージ別の閾値を判定(`bin/coverage-check.php`。4.8) | 不要 |
| `composer test:integration` | 統合テスト(wp-env の `cli` コンテナ内で PHPUnit 9.6 実行) | **必要** |
| `composer test` | `test:unit` → `test:integration` | 必要 |
| `composer test:e2e` | E2E(Playwright。`tests/E2E/`)。examples/sample-siteのwp-env(ポート8882)を、sample-siteの`composer wp:start`・`composer wp:setup`で用意しておく(4.10) | **必要** |
| `composer discover:build` | fixtureのテーマ・プラグインの探索結果ファイルを`wp tmswp discover --kernels=2`で生成する(wp-envの`cli`コンテナ内。起動したKernelが2つでなければ非0。4.6) | **必要** |
| `composer discover:check` | fixtureの探索結果ファイルが最新かを`wp tmswp discover --check --kernels=2`で照合する(古い、または起動したKernelが2つでなければ非0) | **必要** |
| `composer qa:fast` | `lint` → `analyse` → `test:unit`(Docker 不要の全 QA) | 不要 |
| `composer acf:check:fixtures` | fixtureのテーマ・プラグインのacf-bridgeの生成物を、WordPressを起動しない`tms-acf check --strict`で照合する(1.2) | 不要 |
| `composer qa` | `lint` → `analyse` → `acf:check:fixtures` → `discover:check` → `test`(全QA) | 必要 |
| `composer monorepo:validate` | パッケージ間バージョン整合検査 | 不要 |
| `composer wp:start` / `wp:stop` | wp-env の起動 / 停止(`npm ci` で入れたローカルの @wordpress/env を使う。1.1 補足) | 必要 |
| `composer wp:start:coverage` | Xdebug をカバレッジのモードで有効にして wp-env を起動(`--xdebug=coverage`。4.8) | 必要 |

### 1.1 ルート composer.json(完全版)

ルート`composer.json`は手で維持する。`replace`・`autoload`・`autoload-dev`はpackages/*のcomposer.jsonに合わせ(新しいパッケージを足す手順は6.2)、依存の版の制約がパッケージと食い違わないことを`composer monorepo:validate`で検査する。`monorepo-builder merge`は使わず、composerのスクリプトにも置かない(starters/*のautoloadと、tmswp/testingのrequireのPHPUnit・Brain Monkey・Mockeryをルートの`autoload`・`require`に入れるため)。完全形:

```json
{
  "name": "tmswp/tmswp",
  "description": "TMStack WordPress Framework (monorepo)",
  "license": "GPL-2.0-or-later",
  "type": "library",
  "require": {
    "php": ">=8.3",
    "ext-gd": "*",
    "ext-intl": "*",
    "ext-mbstring": "*",
    "psr/container": "^2.0",
    "psr/log": "^3.0"
  },
  "require-dev": {
    "bamarni/composer-bin-plugin": "^1.8",
    "brain/monkey": "^2.6",
    "friendsofphp/php-cs-fixer": "^3.88",
    "mikey179/vfsstream": "^1.6.12",
    "mockery/mockery": "^1.6",
    "php-stubs/acf-pro-stubs": "^6.0",
    "php-stubs/wordpress-tests-stubs": "7.1.0",
    "phpstan/phpstan": "^2.1",
    "phpstan/phpstan-deprecation-rules": "^2.0",
    "phpstan/phpstan-phpunit": "^2.0",
    "phpstan/phpstan-strict-rules": "^2.0",
    "phpunit/phpunit": "^11.5",
    "symplify/monorepo-builder": "^12.7",
    "szepeviktor/phpstan-wordpress": "^2.0"
  },
  "replace": {
    "tmswp/acf-bridge": "self.version",
    "tmswp/core": "self.version",
    "tmswp/ja-toolkit": "self.version",
    "tmswp/settings-page": "self.version",
    "tmswp/testing": "self.version",
    "tmswp/thumbnail": "self.version"
  },
  "autoload": {
    "psr-4": {
      "TMSWP\\AcfBridge\\": "packages/acf-bridge/src/",
      "TMSWP\\Core\\": "packages/core/src/",
      "TMSWP\\Core\\PHPStan\\": "packages/core/phpstan/src/",
      "TMSWP\\JaToolkit\\": "packages/ja-toolkit/src/",
      "TMSWP\\SettingsPage\\": "packages/settings-page/src/",
      "TMSWP\\Thumbnail\\": "packages/thumbnail/src/"
    },
    "files": [
      "packages/core/src/functions.php",
      "packages/thumbnail/src/functions.php"
    ]
  },
  "autoload-dev": {
    "psr-4": {
      "TMSWP\\AcfBridge\\Tests\\": "packages/acf-bridge/tests/",
      "TMSWP\\Core\\Tests\\": "packages/core/tests/",
      "TMSWP\\JaToolkit\\Tests\\": "packages/ja-toolkit/tests/",
      "TMSWP\\SettingsPage\\Tests\\": "packages/settings-page/tests/",
      "TMSWP\\Testing\\": "packages/testing/src/",
      "TMSWP\\Testing\\Tests\\": "packages/testing/tests/",
      "TMSWP\\Thumbnail\\Tests\\": "packages/thumbnail/tests/",
      "TMSWP\\Tools\\Release\\": "tools/release/"
    }
  },
  "scripts": {
    "lint": "php-cs-fixer fix --dry-run --diff --show-progress=none",
    "fix": "php-cs-fixer fix",
    "analyse": "phpstan analyse -c phpstan.neon.dist --memory-limit=2G",
    "i18n:build": [
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-pot packages/core/src packages/core/languages/tmswp-core.pot --domain=tmswp --package-name=tmswp-core --headers='{\"POT-Creation-Date\":\"\"}'",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n update-po packages/core/languages/tmswp-core.pot",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-mo packages/core/languages",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-php packages/core/languages",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-pot packages/ja-toolkit/src packages/ja-toolkit/languages/tmswp-ja-toolkit.pot --domain=tmswp --package-name=tmswp-ja-toolkit --headers='{\"POT-Creation-Date\":\"\"}'",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n update-po packages/ja-toolkit/languages/tmswp-ja-toolkit.pot",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-mo packages/ja-toolkit/languages",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-php packages/ja-toolkit/languages",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-pot packages/settings-page/src packages/settings-page/languages/tmswp-settings-page.pot --domain=tmswp --package-name=tmswp-settings-page --headers='{\"POT-Creation-Date\":\"\"}'",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n update-po packages/settings-page/languages/tmswp-settings-page.pot",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-mo packages/settings-page/languages",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-php packages/settings-page/languages",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-pot packages/thumbnail/src packages/thumbnail/languages/tmswp-thumbnail.pot --domain=tmswp --package-name=tmswp-thumbnail --headers='{\"POT-Creation-Date\":\"\"}'",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n update-po packages/thumbnail/languages/tmswp-thumbnail.pot",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-mo packages/thumbnail/languages",
      "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp i18n make-php packages/thumbnail/languages"
    ],
    "discover:build": "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp --require=tests/fixtures/_wp-cli.php tmswp discover --kernels=2",
    "discover:check": "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp wp --require=tests/fixtures/_wp-cli.php tmswp discover --check --kernels=2",
    "test:unit": "phpunit -c phpunit.unit.xml.dist",
    "test:coverage": [
      "@test:coverage:unit",
      "@test:coverage:integration",
      "@test:coverage:check"
    ],
    "test:coverage:unit": [
      "@putenv XDEBUG_MODE=off",
      "phpunit -c phpunit.unit.xml.dist --coverage-clover=var/coverage/unit.xml"
    ],
    "test:coverage:integration": "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp vendor-bin/wp-tests/vendor/bin/phpunit -c phpunit.integration.xml.dist --coverage-clover=var/coverage/integration.xml",
    "test:coverage:check": "php bin/coverage-check.php 85 --package=acf-bridge:80 --package=core:80 --package=ja-toolkit:80 --package=settings-page:80 --package=testing:80 --package=thumbnail:80 var/coverage/unit.xml var/coverage/integration.xml",
    "test:integration": "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp vendor-bin/wp-tests/vendor/bin/phpunit -c phpunit.integration.xml.dist",
    "test:integration:acf-pro": [
      "Composer\\Config::disableProcessTimeout",
      "bash tools/acf-pro-integration.sh"
    ],
    "test:e2e": [
      "Composer\\Config::disableProcessTimeout",
      "node node_modules/@playwright/test/cli.js test --config=playwright.config.mjs"
    ],
    "test": [
      "@test:unit",
      "@test:integration"
    ],
    "qa:fast": [
      "@lint",
      "@analyse",
      "@test:unit"
    ],
    "acf:check:fixtures": [
      "php packages/acf-bridge/bin/tms-acf check --strict --root=tests/fixtures/themes/tmswp-fixture-theme",
      "php packages/acf-bridge/bin/tms-acf check --strict --root=tests/fixtures/plugins/tmswp-fixture-plugin"
    ],
    "qa": [
      "@lint",
      "@analyse",
      "@acf:check:fixtures",
      "@discover:check",
      "@test"
    ],
    "monorepo:validate": "monorepo-builder validate",
    "wp:start": "node node_modules/@wordpress/env/bin/wp-env start",
    "wp:start:coverage": "node node_modules/@wordpress/env/bin/wp-env start --xdebug=coverage",
    "wp:stop": "node node_modules/@wordpress/env/bin/wp-env stop"
  },
  "config": {
    "sort-packages": true,
    "platform": {
      "php": "8.3.0"
    },
    "allow-plugins": {
      "bamarni/composer-bin-plugin": true
    }
  },
  "extra": {
    "bamarni-bin": {
      "bin-links": false,
      "forward-command": true,
      "target-directory": "vendor-bin"
    }
  },
  "minimum-stability": "stable"
}
```

補足:

- コア(`tmswp/core`)の runtime 依存は `psr/log` と `psr/container` のみ(DR-14)。
- テストの支援コード(`tmswp/testing`の`src/`。名前空間`TMSWP\Testing\`)は、ランタイムのコードではないため、`autoload`ではなく`autoload-dev`に置く。リリースのワーカー(`tools/release/`。名前空間`TMSWP\Tools\Release\`。6.1)も、monorepoの開発でだけ使うため`autoload-dev`に置く。`replace`には全6パッケージを列挙する。
- `extra.bamarni-bin.forward-command: true` により、`composer install` 一発で後述の `vendor-bin/wp-tests` 隔離環境も同時にインストールされる。
- `php-stubs/wordpress-stubs` は `szepeviktor/phpstan-wordpress` の依存として自動導入される(明示 require 不要)。
- `config.platform.php` を下限の `8.3.0` に固定し、依存を下限の PHP で解決する(上位の PHP で `composer update` しても、8.3 で動かない版が lock に入らない)。`vendor-bin/wp-tests/composer.json` も同じ値を持つ(§4.2)。
- php-cs-fixer は composer.json の PHP 下限より新しい PHP で実行すると、下限で使えない構文を生成しうるとして警告を出す。`composer lint` / `fix` は下限の PHP 8.3 で実行する(CI の `lint` ジョブも 8.3)。
- wp-env は npm の開発依存として monorepo ルートの `package.json` で版を固定し(`@wordpress/env` を完全一致で指定。`package-lock.json` もコミットする)、`npm ci` で導入する。composer スクリプトと qa.yml は、固定したパッケージの実行ファイルを `node node_modules/@wordpress/env/bin/wp-env …` とパスで直接実行する。未導入ならファイルが無いため node がエラーで止まる。`npx` や npm の `exec` / `run` のようにコマンド名 `wp-env` で解決する呼び出しは、ローカルに実行ファイルが無いときに npm 上の第三者パッケージ `wp-env` やグローバルの同名コマンドを実行しうるため使わない。
- E2Eの`@playwright/test`も、ルートの`package.json`で版を完全一致で固定する(4.10)。`test:e2e`は`node node_modules/@playwright/test/cli.js`をパスで呼ぶ。ブラウザは`npm ci`では入らないため、`node node_modules/@playwright/test/cli.js install chromium`で入れる。`test:e2e`は、Composerのプロセスの時間制限(300秒)を外す。

`package.json`(完全版):

```json
{
  "name": "tmswp",
  "private": true,
  "description": "TMStack WordPress Framework (monorepo) development tooling",
  "license": "GPL-2.0-or-later",
  "devDependencies": {
    "@playwright/test": "1.64.1",
    "@wordpress/env": "12.0.0"
  },
  "allowScripts": {
    "fs-ext-extra-prebuilt": false
  }
}
```

### 1.2 `acf:sync`・`acf:check`スクリプト

`acf:sync`・`acf:check`はacf-bridgeのコードジェネレータ(DR-06)を実行するスクリプトで、**アプリ(テーマ/プラグイン)側のcomposer.jsonに定義する**。WordPressを起動しない`vendor/bin/tms-acf`を呼び、テーマ・プラグインのルートの`tmswp-kernel.php`の構成で生成・照合する。コマンドの正準仕様(WP-CLIの`wp tmswp acf sync|check`との違いを含む)はacf-bridge文書 §5.1が定義する。

acf-bridgeを入れたアプリのcomposer.json scriptsに足す定義(スターター(starters/theme, starters/plugin)はacf-bridgeに依存しないため、既定では含めない。手順は11-ai-docs §8.2):

```json
{
    "scripts": {
        "acf:check": "tms-acf check",
        "acf:sync": "tms-acf sync"
    }
}
```

`tms-acf`はWordPressを起動しないため、wp-envは要らない。`#[MetaSchema]`との重複(E101)は`tms-acf`では検査しないため、WordPressの環境で`wp tmswp acf check`を実行する(wp-env利用時は`node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/themes/<theme> wp tmswp acf check`。wp-envはプロジェクトのpackage.jsonで版を固定して導入する。1.1補足)。monorepoルートには`acf:sync`を置かない(acf-jsonはアプリ資産でありFWリポジトリには存在しないため)。acf-bridge自身のジェネレータ検証は`packages/acf-bridge/tests/`のフィクスチャacf-jsonに対するユニット/統合テストで行い、fixtureのテーマ・プラグインの生成物は`composer acf:check:fixtures`(`tms-acf check --strict`。`composer qa`に含む)で照合する。

---

## 2. PHPStan 設定

### 2.1 phpstan.neon.dist(完全版)

```neon
includes:
  - vendor/szepeviktor/phpstan-wordpress/extension.neon
  - vendor/phpstan/phpstan-phpunit/extension.neon
  - vendor/phpstan/phpstan-phpunit/rules.neon
  - vendor/phpstan/phpstan-strict-rules/rules.neon
  - vendor/phpstan/phpstan-deprecation-rules/rules.neon
  - packages/core/phpstan/extension.neon

parameters:
  level: max

  phpVersion:
    min: 80300
    max: 80500

  paths:
    - packages/core/src
    - packages/core/phpstan/src
    - packages/core/tests
    - packages/thumbnail/src
    - packages/thumbnail/tests
    - packages/settings-page/src
    - packages/settings-page/tests
    - packages/acf-bridge/src
    - packages/acf-bridge/bin/tms-acf
    - packages/acf-bridge/tests
    - packages/ja-toolkit/src
    - packages/ja-toolkit/tests
    - packages/testing/src
    - packages/testing/tests
    - starters/theme/app
    - starters/plugin/app
    - starters/theme/bin/setup.php
    - bin
    - tools
    - tests/Unit
    - tests/Integration

  excludePaths:
    - packages/core/tests/fixtures/*
    - tests/Integration/fixtures/*

  scanDirectories:
    - vendor-bin/wp-tests/vendor/yoast/phpunit-polyfills/src

  scanFiles:
    - vendor/php-stubs/acf-pro-stubs/acf-pro-stubs.php
    - vendor/php-stubs/wordpress-tests-stubs/wordpress-tests-stubs.php

  treatPhpDocTypesAsCertain: false
  reportUnmatchedIgnoredErrors: true

  dynamicConstantNames:
    - WP_DEBUG
    - WP_DEBUG_LOG
    - WP_DEBUG_DISPLAY

  tmpDir: var/phpstan

  ignoreErrors: []
```

### 2.2 運用規律(必須)

1. **baseline 全面禁止**(DR-12)。`phpstan-baseline.neon` をリポジトリに置いてはならない。CI はその存在自体を fail 条件とする(qa.yml 参照)。
2. `parameters.ignoreErrors` は原則空を維持する。例外的な抑止は**行内 `@phpstan-ignore`(識別子指定 + 理由コメント)のみ**許可する。

    ```php
    // wpdb::$insert_id は stubs 上 int|string のため(理由を必ず書く)
    /** @phpstan-ignore cast.int */
    $id = (int) $wpdb->insert_id;
    ```

   ファイル全体・ディレクトリ単位の抑止は禁止。
3. FW 同梱拡張(`packages/core/phpstan/extension.neon`)の services 定義・拡張クラス(`TMSWP\Core\PHPStan\` 名前空間)の正準仕様は 04 文書。本書はルート設定への登録(includes 行)とパッケージ配布形のみを定める。
4. パッケージ配布形: `packages/core/composer.json` に以下を持たせ、**アプリ側では `phpstan/extension-installer` により自動登録**されるようにする。

    ```json
    "extra": {
        "phpstan": {
            "includes": ["phpstan/extension.neon"]
        }
    }
    ```

   monorepo ルートでは `packages/core` が vendor 配下に存在しない(replace 運用)ため extension-installer の対象外であり、上記のとおり**明示 includes** で同じ `packages/core/phpstan/extension.neon` を登録する。拡張の設定ファイルはこの 1 枚だけで、別の neon から include し直す 2 段構成にはしない(01 文書 §3.4・§3.5)。
5. 統合テストコードも解析対象に含める。`WP_UnitTestCase` 等のシンボルは `php-stubs/wordpress-tests-stubs`(ルートの require-dev)から、Yoast Polyfills は `vendor-bin/wp-tests` の実体から解決する。テストライブラリの実体は wp-env のコンテナ内にしか無い(§4.2)ため、解析はスタブで行う。スタブの版は確認できた最新版に固定し、WordPress の新しい版に合わせて上げる。`vendor-bin/wp-tests` が未インストールの状態で `composer analyse` を実行した場合は scanDirectories 不在のエラーになるため、セットアップ手順(後述)どおり `composer install` を先に完了させること。
6. **ワーカーのメモリの上限**。上限はcomposer.jsonの`analyse`の`--memory-limit`の1か所で定義する(現在2G)。PHPStanはファイルを分けて複数のワーカー(別のプロセス)で解析し、上限はワーカーごとに効く。どのワーカーが重いファイルを受け持つかで最大量が変わり、上限に達するとCIのStatic Analysisだけが失敗しうるため、次の手順で測る。

    ```bash
    php tools/phpstan-memory.php
    ```

   - CIと同じ条件(4ワーカー・結果のキャッシュなし)で`phpstan.neon.dist`を解析し、プロセスごとのメモリの最大量(`memory_get_peak_usage(true)`)と、上限に対する余裕を出す。PHPStanはワーカーごとのメモリを出力しないため、ワーカーにも引き継がれる環境変数`PHP_INI_SCAN_DIR`で`auto_prepend_file`を足し、各プロセスの終了時に記録させる。作業ファイルは`var/phpstan-memory/`に置く。解析の失敗(エラーの検出・上限への到達)は非0で終わる。
   - ファイルの受け持ちは実行ごとに変わるため、CIと同じPHP 8.3で3回以上測り、その最大値を各版の受け入れ記録に、上限に対する余裕とともに書く。
   - 最大量が上限の80%を超えたら、上限を上げる前に、`phpstan.neon.dist`の解析の分割(重いパスを別の設定に分け、`analyse`で順に実行する)を検討する。

---

## 3. php-cs-fixer 設定

### 3.1 .php-cs-fixer.dist.php(完全版)

```php
<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = (new Finder())
    ->in([
        __DIR__ . '/packages',
        __DIR__ . '/tests',
        __DIR__ . '/bin',
        __DIR__ . '/tools',
        // スターターは、スターター自身の.php-cs-fixer.dist.phpと同じ対象(app/・tests/・テンプレートでないルートのPHP)と
        // bin/setup.phpに限る。テンプレート(テーマのテンプレート階層のファイル・parts/・ブロックのrender.php、
        // プラグインのtemplates/)と、ビルドの生成物(build/)は含めない
        __DIR__ . '/starters/theme/app',
        __DIR__ . '/starters/theme/tests',
        __DIR__ . '/starters/plugin/app',
        __DIR__ . '/starters/plugin/tests',
    ])
    ->name('*.php')
    // 例外の階層は、具体クラス(RuntimeException・LogicException等)をFWの例外が継承するため、final_classを適用できない
    ->notPath('#^core/src/Exception/#')
    // coreのテストのfixtureは、外部のコードの写し(古いpsr/log・psr/container)と、PHPStanの拡張の検査の入力を含む
    ->notPath('#^core/tests/fixtures/#')
    // packages/*/languages/ の .l10n.php は `wp i18n make-php` の生成物で、出力をそのままコミットする
    ->notPath('#^[^/]+/languages/#')
    ->append([
        __FILE__,
        __DIR__ . '/monorepo-builder.php',
        __DIR__ . '/packages/acf-bridge/bin/tms-acf',
        __DIR__ . '/starters/theme/functions.php',
        __DIR__ . '/starters/theme/tmswp-kernel.php',
        __DIR__ . '/starters/theme/bin/setup.php',
        __DIR__ . '/starters/plugin/plugin.php',
        __DIR__ . '/starters/plugin/tmswp-kernel.php',
        __DIR__ . '/starters/plugin/bin/setup.php',
    ]);

return (new Config())
    ->setRiskyAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        // PER Coding Style 2.0(PSR-12の後継規格)。php-cs-fixerではドット表記の
        // @PER-CS2.0識別子が非推奨(4.0で削除予定)のため'x'表記を用いる
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,

        // --- 厳密性(コーディング規約の機械的強制) ---
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'void_return' => true,
        'final_class' => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // --- import規律 ---
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'fully_qualified_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            // `\call_user_func_array()` はコンパイル時に直接の呼び出しへ置き換わり、呼び出し元の strict_types が
            // コールバックの引数に効く。Kernel はフックメソッドを WordPress と同じ弱い型変換で呼ぶため、名前空間を付けない
            'exclude' => ['call_user_func_array'],
            'scope' => 'namespaced',
        ],

        // --- モダン構文 ---
        'array_syntax' => ['syntax' => 'short'],
        'list_syntax' => ['syntax' => 'short'],
        'modernize_strpos' => true,
        'modernize_types_casting' => true,
        'get_class_to_class_keyword' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,

        // --- 表記統一 ---
        // concat_space(one)/ cast_spaces(single)/ trailing_comma_in_multilineは
        // @PER-CS2x0基底に内包されるため手動指定しない。trailing commaの適用対象は
        // 基底の既定(array_destructuringを含む)へ拡張される(意図した挙動確定)
        'single_quote' => true,
        'binary_operator_spaces' => true,
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
        'class_attributes_separation' => ['elements' => ['method' => 'one']],

        // --- PHPDoc ---
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'phpdoc_separation' => true,

        // --- PHPUnit ---
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'php_unit_data_provider_static' => true,
        'php_unit_data_provider_return_type' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
    ]);
```

### 3.2 補足規律

- `final_class` は非 abstract クラスを一律 final 化する。FW の拡張ポイント(`PostType` / `Taxonomy` / `UserRole` / `OptionGroup` / `Model` / `Hook` / `Form` の各基底)は **abstract で宣言する**ことで両立する(コーディング規約「final 優先」と一致)。
- final クラスは Mockery でモックできない。テストで差し替えが必要な協調オブジェクトは**インターフェース経由で注入する**(`MetaStorageInterface` 等)。final を外す回避は禁止。
- `packages/core/src/Exception/`はFinderから除く。例外の階層は、具体クラス(`RuntimeException`・`LogicException`・`ContainerException`)をFWの他の例外が継承するため、`final_class`を適用できない。`packages/core/tests/fixtures/`も除く(外部のコードの写しである古いpsr/log・psr/containerと、PHPStanの拡張の検査の入力を含む)。他のパッケージのfixture(acf-bridgeの生成物の見本等)は対象に含める。
- ビューテンプレート(スターターのテーマテンプレート、`View\Template`が読む`template-parts/`等)はFinderの対象に**含めない**。WordPress標準のテーマ構築手法を壊さないという絶対原則(要件3)に基づき、テンプレートファイルにはFWのスタイル規約を強制しない(§7項4)。
  - monorepoのFinderのstartersの対象は、各スターター自身の`.php-cs-fixer.dist.php`の対象と同じもの(`app/`・`tests/`・テンプレートでないルートのPHP。テーマは`functions.php`・`tmswp-kernel.php`、プラグインは本体ファイル`plugin.php`・`tmswp-kernel.php`)と、`bin/setup.php`に限る。テンプレート(テーマのテンプレート階層のファイル・`parts/`・ブロックの`render.php`、プラグインの`templates/`)と、ビルドの生成物(`build/`)は含めない。
- スターター自身の`.php-cs-fixer.dist.php`は、monorepoと同じ規則(§3.1の`setRules()`。`native_function_invocation`の`exclude`だけはFWのKernelの事情のため持たない)を使う。作ったプロジェクトにacf-bridgeの生成物(`app/Acf/*Fields.php`。PER-CSの`fn(`の書き方)を置いても、そのままlintを通る。
  - プラグインのスターターは、本体ファイルを`__DIR__ . '/plugin.php'`で参照する。`bin/setup.php`は本体ファイルの名前を`<スラッグ>.php`に変え、同時にこの参照も書き換えるため、setup.phpの実行の前と後のどちらでも、実在するファイルだけを参照する。

---

## 3A. テンプレート前処理の検査

テンプレート前処理(DR-19。正準仕様は 07 文書 §1A)は、テンプレートの先頭で呼ぶ app 層の前処理クラスに書く。前処理クラスは `app/Page/` に置くため、アプリ側の本則の PHPStan(`paths: [app]`・level max)と php-cs-fixer(`app/` の Finder)の対象になり、テンプレートが読む値の型は前処理クラスのプロパティの型で検査される。前処理のための専用の検査設定・スクリプトは持たない。

- テンプレートファイル自体は php-cs-fixer / PHPStan(型検査)の対象に含めない(§3.2・§7 項 4。要件 3)。
- rules/page.md の前処理クラスのコード例は、rules/ のコード例の抽出・検査(`php tools/extract-rules-code.php && php tools/verify-rules-code.php`。`App\` のクラスは PHPStan max)で確かめる。

---

## 4. テスト構成(PHPUnit 2 層)

### 4.1 層の定義

| 層 | 配置 | 基盤 | 実行環境 | 目的 |
|---|---|---|---|---|
| ユニット | `packages/<pkg>/tests/Unit/`(bin/・tools/ のテストはリポジトリルートの `tests/Unit/`) | PHPUnit **11** + Brain Monkey(+ Mockery / vfsStream) | 素の PHP(WP 不要) | ロジックの仕様確定。高速・常時実行 |
| 統合 | `packages/<pkg>/tests/Integration/` | PHPUnit **9.6** + WordPress のテストライブラリ(WP_TESTS_DIR)+ Yoast Polyfills | wp-env(Docker) | WP 実環境での登録系・DB 系の検証 |

この2層のほかに、examples/sample-siteの画面をブラウザで確かめるE2E(Playwright。リポジトリルートの`tests/E2E/`)がある(4.10)。

ディレクトリ規約(全パッケージ共通):

```
packages/core/
├── src/
└── tests/
    ├── Unit/            # 名前空間 TMSWP\Core\Tests\Unit\ — src/ をミラーする
    │   ├── Support/ArrTest.php            # src/Support/Arr.phpのテスト
    │   ├── Form/Rule/EmailTest.php        # src/Form/Rule/Email.phpのテスト
    │   ├── Form/FormValidationTest.php    # src/Form/Form.phpの検証の観点のテスト
    │   ├── PHPStan/MetaKeyOwnershipRuleTest.php   # phpstan/src/MetaKeyOwnershipRule.phpのテスト
    │   └── EntityQueryTest.php …          # Post・Tax・Userのクエリを同じ観点で確かめるテスト
    ├── Integration/     # 名前空間 TMSWP\Core\Tests\Integration\
    │   └── EntityRegistrationIntegrationTest.php …
    ├── Support/         # パッケージ専用のテスト支援クラス(UnitTestCase・IntegrationTestCaseはtmswp/testing)
    └── fixtures/        # ユニット層のフィクスチャ(QA 対象外)
```

パッケージの `tests/Unit/` は `src/` のディレクトリ構造をミラーする(旧 iwf2b の `tests/TestCase` 規約を踏襲)。対象はpackages/の6パッケージ(core・acf-bridge・settings-page・thumbnail・ja-toolkit・testing)。

- `packages/<pkg>/src/<Dir>/<Class>.php`のテストは`packages/<pkg>/tests/Unit/<Dir>/<Class>Test.php`に置き、名前空間を`TMSWP\<Pkg>\Tests\Unit\<Dir>\`にする。
- 1つのクラスのテストが大きい場合は、同じディレクトリに`<Class><観点>Test.php`として分けてよい(例: `Form/FormRuntimeTest.php`・`Form/FormValidationTest.php`)。
- 複数のクラスにまたがるテスト(結合寄りのユニットテスト)は、主な対象のクラスの位置に置く(例: `TokenSigner`を使う`ConfirmToken`のテストは`Form/ConfirmTokenTest.php`)。
- 主な対象のクラスを決められないテスト(複数のクラスを同じ観点で確かめるもの)は、それらのクラスのディレクトリに共通する最も近い親のディレクトリに`<観点>Test.php`として置く(例: 同梱の規則のコンストラクタの検査は`Form/Rule/ConstructorArgumentsTest.php`、Post・Tax・Userのクエリは`tests/Unit/`の直下の`EntityQueryTest.php`)。
- `src/`の外にあるPSR-4のクラス(coreの`phpstan/src/`の`TMSWP\Core\PHPStan\`、`tests/Support/`の`TMSWP\Core\Tests\Support\`)のテストは、名前空間の`TMSWP\<Pkg>\`より下の部分をミラーする(例: `PHPStan/MetaKeyOwnershipRuleTest.php`・`Tests/Support/IntegrationEnvironmentGuardTest.php`)。
- 仕様の境界(正常系と異常系の境目)は、データプロバイダに1ケースずつ並べ、1つのテストのメソッドに複数の規則の境界を詰め込まない(例: 同梱の規則は`Form/Rule/<Rule>Test.php`に規則ごとのデータプロバイダを持つ)。

統合層の fixture(テスト用のテーマ・プラグイン)はパッケージごとではなく、リポジトリルートの `tests/fixtures/` に置く。全パッケージの統合テストが同じ fixture を共有する(4.6)。どのパッケージにも属さない `bin/`・`tools/` のユニットテストは、リポジトリルートの `tests/Unit/` に置く(4.3 の testsuite `tools`)。

```
tests/
├── bootstrap-integration.php
├── wp-tests-config.php
├── Unit/                                  # bin/・tools/とスターターのbin/setup.phpのユニットテスト(testsuite tools。名前空間TMSWP\Tests\Unit\)
├── Integration/                           # スターターの統合テスト(testsuite starters。名前空間TMSWP\Tests\Integration\。4.6)
│   └── fixtures/starter-site/             # スターターから作ったテーマ・プラグインを別のプロセスで有効にする(site.php・wp-cli.php・router.php)
└── fixtures/
    ├── _activate.php                      # fixture と無料版 ACF を有効にするフィルタ(_boot.php と _wp-cli.php が共有)
    ├── _boot.php                          # 統合テストで fixture と無料版 ACF を有効化する
    ├── _wp-cli.php                        # wp tmswp discover で fixture を有効化する(WP-CLI の --require)
    ├── themes/tmswp-fixture-theme/        # Kernel 名前空間 TmswpFixtureTheme
    │   ├── style.css / functions.php      # functions.php の直下で Kernel::boot()
    │   ├── tmswp-discovery.php            # 探索結果ファイル(composer discover:build で生成してコミット)
    │   ├── index.php                      # テーマのテンプレート
    │   ├── parts/                         # Template の確認用
    │   ├── blocks/                        # 独自ブロックのソース(card・layout/notice。探索の対象外)
    │   ├── build/blocks/                  # ブロックのビルドの出力(探索・登録の対象。Nodeのビルドを使わずに手で置いたもの)
    │   └── app/                           # Hook・Post・Tax・User・Option・Model・Acf 等
    └── plugins/tmswp-fixture-plugin/      # Kernel 名前空間 TmswpFixturePlugin
        ├── tmswp-fixture-plugin.php       # 本体ファイルの直下で Kernel::boot()
        ├── tmswp-discovery.php            # 探索結果ファイル(composer discover:build で生成してコミット)
        ├── app/                           # Hook・Post(allowedBlocksの確認用)・Model・Option・Acf
        └── templates/                     # プラグインのテンプレートのフォールバック確認用
```

### 4.2 統合層の PHPUnit バージョン制約と隔離(確定事項)

WordPress コアテストスイート(wordpress-develop の `tests/phpunit/includes/bootstrap.php` / `WP_UnitTestCase`)は **PHPUnit 9.6 が上限**であり(WP 7.x の制約。bootstrap が PHPUnit 10+ を明示拒否する)、PHPUnit 11 とは併用できない。よって DR-12 の「PHPUnit 11」は**ユニット層に適用**し、統合層は次のとおり隔離した PHPUnit 9.6 で実行する。これは本書で確定する技術判断である。

- `bamarni/composer-bin-plugin` の名前空間 `wp-tests` に統合テスト専用ツールチェーンを隔離インストールする。
- ルート vendor(PHPUnit 11)と `vendor-bin/wp-tests/vendor`(PHPUnit 9.6)は衝突しない。
- 統合テストコードは Yoast Polyfills 流儀(`set_up()` / `tear_down()`)で書き、PHPUnit 9.6 で動く assertion のみ使用する。WP コアが将来 PHPUnit 11 対応した時点で隔離を解除しユニット層と統一する(その際もテストコードの変更は最小で済む)。

`vendor-bin/wp-tests/composer.json`(完全版):

```json
{
  "require": {
    "php": ">=8.3",
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^2.0"
  },
  "config": {
    "sort-packages": true,
    "platform": {
      "php": "8.3.0"
    }
  }
}
```

- テストライブラリ(`WP_UnitTestCase` 等)は vendor-bin に入れない。wp-env は、起動した WordPress の版と同じタグの wordpress-develop を取得し、その `tests/phpunit` をコンテナの `/wordpress-phpunit` にマウントして、環境変数 `WP_TESTS_DIR` で示す(core が最新版なら最新版のタグ、`wordpress-7.0.zip` なら `7.0.0`)。統合テストはこれを使うため、テストライブラリの版はパッチ版まで WordPress と一致する。CI でテストライブラリの版を固定する手順は要らない。
- `WP_TESTS_DIR` の直下には wp-env が `wp-tests-config.php` を置く。WordPress のテストライブラリは設定ファイルの指定が無いとこれを使うが、テーブル接頭辞が開発用サイトと同じ `wp_` である。これで動くとテストの準備が開発用サイトのテーブルを消すため、bootstrap は必ず `WP_TESTS_CONFIG_FILE_PATH` で tests/wp-tests-config.php を指定する(§4.6)。
- PHPStan はテストライブラリの実体を参照できないため、`php-stubs/wordpress-tests-stubs` で型を得る(§2.2)。
- 統合テストはコンテナ内の PHP(8.3 または 8.5)で実行するため、ホストの PHP で依存を解決しても下限 8.3 で動く版に限る(`config.platform.php`)。

### 4.3 phpunit.unit.xml.dist(PHPUnit 11、完全版)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap-unit.php"
         cacheDirectory="var/phpunit"
         executionOrder="depends,defects"
         colors="true"
         failOnRisky="true"
         failOnWarning="true"
         failOnDeprecation="true"
         failOnNotice="true"
         beStrictAboutOutputDuringTests="true"
         displayDetailsOnTestsThatTriggerDeprecations="true"
         displayDetailsOnTestsThatTriggerNotices="true"
         displayDetailsOnTestsThatTriggerWarnings="true">
    <testsuites>
        <testsuite name="core">
            <directory>packages/core/tests/Unit</directory>
        </testsuite>
        <testsuite name="thumbnail">
            <directory>packages/thumbnail/tests/Unit</directory>
        </testsuite>
        <testsuite name="settings-page">
            <directory>packages/settings-page/tests/Unit</directory>
        </testsuite>
        <testsuite name="acf-bridge">
            <directory>packages/acf-bridge/tests/Unit</directory>
        </testsuite>
        <testsuite name="ja-toolkit">
            <directory>packages/ja-toolkit/tests/Unit</directory>
        </testsuite>
        <testsuite name="testing">
            <directory>packages/testing/tests/Unit</directory>
        </testsuite>
        <testsuite name="tools">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>packages/core/src</directory>
            <directory>packages/thumbnail/src</directory>
            <directory>packages/settings-page/src</directory>
            <directory>packages/acf-bridge/src</directory>
            <directory>packages/ja-toolkit/src</directory>
            <directory>packages/testing/src</directory>
        </include>
    </source>
</phpunit>
```

`--testsuite core`のようにパッケージ単位の実行が可能(AIが単一パッケージを反復開発する際の推奨形)。testsuite `tools`(ルートの`tests/Unit/`)は、ルートの道具(bin/・スターターのsetup.php等)のテストと、複数のパッケージにまたがる検査(全パッケージの例外の階層を確かめる`ExceptionHierarchyTest`、同梱翻訳の`L10nTest`、`composer analyse`の設定で解析する`PHPStanRawFragmentLiteralStringTest`)を置く。これらはmonorepoの構成に依存するため、パッケージのtests/には置かない。

各パッケージ(packages/*)には、splitで単独で取り出したときの`composer test`が使う`phpunit.xml.dist`を置く(`.gitattributes`の`export-ignore`で配布物からは外す)。そのパッケージの`tests/Unit/`だけを走らせ、統合テストはmonorepoのルートでだけ走らせる。coreの例(ほかのパッケージはtestsuiteの名前とbootstrap(`vendor/autoload.php`)だけが違う):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!--このパッケージを単独で取り出したときのユニットテスト(composer test)。統合テストはmonorepoのルートで走らせる-->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         executionOrder="depends"
         colors="true"
         failOnRisky="true"
         failOnWarning="true"
         failOnDeprecation="true"
         failOnNotice="true"
         beStrictAboutOutputDuringTests="true"
         displayDetailsOnTestsThatTriggerDeprecations="true"
         displayDetailsOnTestsThatTriggerNotices="true"
         displayDetailsOnTestsThatTriggerWarnings="true">
    <testsuites>
        <testsuite name="core">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <ini name="date.timezone" value="UTC"/>
    </php>
</phpunit>
```

coreのbootstrap(`packages/core/tests/bootstrap.php`)は、Composerのオートローダを読み込んだ後、Brain MonkeyのPatchworkが`phar://`のストリームを差し替える前に、PHPStanのテストのコンテナ(`PHPStanTestCase::getContainer()`)を作る。PHPStan 2.3のpharは、内部のクラスを最初に読み込むときに同じファイルを`vendor/composer/../`を含むパスと含まないパスから読み込み、差し替えた後ではPHPが同じファイルと判定できずに、PHPStanの拡張のテストがクラスの再宣言で止まるためである。

### 4.4 tests/bootstrap-unit.php と UnitTestCase 基底

`tests/bootstrap-unit.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
```

WP 定数(`ABSPATH` 等)は**意図的に定義しない**。ユニット層のテスト対象が WP 定数や WP 関数へ直接到達した場合、未定義エラー / Brain Monkey の例外として顕在化させ、「WP 関数呼び出しは薄い境界に寄せる」規約(共通前提)の違反を検出する。

全ユニットテストの基底クラス`TMSWP\Testing\UnitTestCase`(`tmswp/testing`。完全版):

```php
<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Composer\Autoload\ClassLoader;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ユニット層の共通基底。Brain MonkeyとMockeryの検証を全テストに保証する。
 *
 * FWのソースは翻訳関数(`__()`等)を常に呼ぶため、翻訳関数はソース文字列をそのまま返すスタブにする。
 */
abstract class UnitTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;
    use UsesTempDirectories;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\stubTranslationFunctions();
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * テストを走らせているComposerの`vendor/autoload.php`の絶対パスを返す。
     *
     * 別のPHPのプロセスでテストのコードを動かすときに渡す。monorepoではルートのvendor/、パッケージを単独で
     * 取り出したときはそのパッケージのvendor/を指す。
     */
    protected static function composerAutoloadFile(): string
    {
        $classLoaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();
        self::assertIsString($classLoaderFile);

        return \dirname($classLoaderFile, 2) . '/autoload.php';
    }
}
```

- 全パッケージのユニットテストはこれを継承する(`abstract`のためfinal_classルールと両立)。各パッケージは`tmswp/testing`をrequire-devで入れる。
- `createTempDirectory()`(`UsesTempDirectories`)は、テストごとに`sys_get_temp_dir()`の下に専用の親ディレクトリを作り、その中に一時ディレクトリを作る。`KernelConfig`がappDirの親に書く探索結果ファイル(`tmswp-discovery.php`)も親ディレクトリに収まり、`tearDown()`で親ディレクトリごと消える。PHP 8.3と8.5のユニットテストを並列に実行しても干渉しない。
- 別のPHPのプロセスでテストのコードを動かすときは、`composerAutoloadFile()`が返す`vendor/autoload.php`を渡す(monorepoではルート、単独で取り出したパッケージではそのパッケージのvendor/を指す)。
- WP 関数のモックは Brain Monkey の `Functions\when()` / `Functions\expect()` を使用する。`Filters\expectAdded()` / `Actions\expectAdded()` でフック登録を検証する。
- ファイル I/O を伴うテスト(SimpleLogger / TempFileStore)は vfsStream を使用する(旧 iwf2b の作法を踏襲)。探索結果ファイル(DiscoveryFile)は `include` で読み込み、`rename` で置き換えるため、一時ディレクトリの実ファイルで確かめる。

### 4.5 phpunit.integration.xml.dist(PHPUnit 9.6 スキーマ、完全版)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor-bin/wp-tests/vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap-integration.php"
         backupGlobals="false"
         colors="true"
         convertDeprecationsToExceptions="true"
         failOnWarning="true"
         failOnRisky="true"
         failOnSkipped="true"
         failOnIncomplete="true"
         failOnEmptyTestSuite="true"
         beStrictAboutTestsThatDoNotTestAnything="true"
         testdox="true">
    <testsuites>
        <testsuite name="core">
            <directory>packages/core/tests/Integration</directory>
        </testsuite>
        <testsuite name="thumbnail">
            <directory>packages/thumbnail/tests/Integration</directory>
        </testsuite>
        <testsuite name="settings-page">
            <directory>packages/settings-page/tests/Integration</directory>
        </testsuite>
        <testsuite name="acf-bridge">
            <directory>packages/acf-bridge/tests/Integration</directory>
        </testsuite>
        <testsuite name="ja-toolkit">
            <directory>packages/ja-toolkit/tests/Integration</directory>
        </testsuite>
        <testsuite name="testing">
            <directory>packages/testing/tests/Integration</directory>
        </testsuite>
        <testsuite name="starters">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <!-- ACF Pro専用のテストは、ACF Proを載せた隔離環境(composer test:integration:acf-pro)だけで走らせる -->
    <groups>
        <exclude>
            <group>acf-pro</group>
        </exclude>
    </groups>
    <coverage>
        <include>
            <directory suffix=".php">packages/core/src</directory>
            <directory suffix=".php">packages/thumbnail/src</directory>
            <directory suffix=".php">packages/settings-page/src</directory>
            <directory suffix=".php">packages/acf-bridge/src</directory>
            <directory suffix=".php">packages/ja-toolkit/src</directory>
            <directory suffix=".php">packages/testing/src</directory>
        </include>
    </coverage>
</phpunit>
```

testsuite `testing`(`packages/testing/tests/Integration/`)は、ユニット層の`WpdbTestDouble`が実際の`wpdb`と同じSQLを返すことを確かめる。testsuite `starters`(`tests/Integration/`)は、スターターから作ったテーマとプラグインを開発用サイトで動かす統合テストである(4.6の「スターターの統合テスト」)。

`<groups>`の除外により、ACF Pro専用のテスト(グループ`acf-pro`)は`composer test:integration`・カバレッジの計測・CIでは走らない(4.9)。

`<coverage>` の計測対象は、4.3 の `<source>` と同じ6パッケージのsrcである(4.8)。`--coverage-clover` を付けない通常の実行(`composer test:integration`)では計測しない。

`failOnRisky`・`failOnSkipped`・`failOnIncomplete`・`failOnEmptyTestSuite`により、アサーションの無いテスト(risky)・skip・incompleteのテストがあるとき、またはテストが1件も実行されなかったときは、終了コードが失敗になる。統合テストが空の実行やskipのまま成功しないこと(実装計画のv0.1.0の受け入れ条件1)を、CIで常に確かめるためである。

`convertDeprecationsToExceptions="true"` により、テスト中に出た非推奨(`E_DEPRECATED` / `E_USER_DEPRECATED`)はテストの失敗になる。ユニット層の `failOnDeprecation` と同じ方針である。Notice(`E_NOTICE` / `E_USER_NOTICE`)は、PHPUnit 9.6の`convertNoticesToExceptions`の既定値(true)でテストのエラーになるため、ユニット層の`failOnNotice`に当たる設定は書かない。発生元が FW か WordPress 本体かは区別しない。FW が WordPress や PHP の関数に不正な値を渡した結果の非推奨は、呼び出された側のファイルで報告されるため、発生元のパスでは FW 由来かどうかを判定できないからである。WordPress 本体や ACF が出す非推奨で失敗した場合は、その版の組み合わせと非推奨の内容を確かめてから対処を決める。

### 4.6 tests/bootstrap-integration.php と fixture(完全版)

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Tests\Support\IntegrationEnvironmentGuard;

$rootLoader = require dirname(__DIR__) . '/vendor/autoload.php';
$wpTestsLoader = require dirname(__DIR__) . '/vendor-bin/wp-tests/vendor/autoload.php';

$rootLoader->unregister();
$wpTestsLoader->unregister();
$rootLoader->register(false);
$wpTestsLoader->register(true);

/*
 * WordPress とテストライブラリを読み込む前に、テストのテーブルが tests/wp-tests-config.php の wptests_ になる
 * 状態かを検査し、そうでなければ止める(IntegrationEnvironmentGuard)。テストライブラリは、wp-env が core と
 * 同じタグの wordpress-develop から取得して WP_TESTS_DIR に置くものを使う。WP_TESTS_DIR の直下に wp-env が
 * 置く wp-tests-config.php は開発用サイトと同じ接頭辞 wp_ のため、設定ファイルは必ず自前のものを指定する。
 */
$wpTestsDir = IntegrationEnvironmentGuard::assertSafe(
    getenv('WP_TESTS_DIR'),
    get_defined_constants(true)['user'] ?? [],
    $GLOBALS,
);

define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');
define(
    'WP_TESTS_PHPUNIT_POLYFILLS_PATH',
    dirname(__DIR__) . '/vendor-bin/wp-tests/vendor/yoast/phpunit-polyfills',
);

require $wpTestsDir . '/includes/functions.php';

// fixture のテーマ・プラグインと無料版 ACF を有効化する(boot は WordPress が fixture を読み込むときに行われる)
require __DIR__ . '/fixtures/_boot.php';

/*
 * 非推奨は失敗として扱う(テスト中は phpunit.integration.xml.dist の convertDeprecationsToExceptions)。
 * fixture の boot と init での登録は WordPress の読み込み中、つまり PHPUnit のエラーハンドラーが働く前に
 * 動くため、読み込みの間も非推奨を例外にする。
 */
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): never {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}, E_DEPRECATED | E_USER_DEPRECATED);

require $wpTestsDir . '/includes/bootstrap.php';

restore_error_handler();
```

- ルートの autoload を後ろ、隔離ツールチェーンの autoload を先に登録し直す(`register(true)` で先頭に置く)。統合層の PHPUnit 9.6 が、ルートの PHPUnit 11 より先に解決されるようにするためである。
- WordPress とテストライブラリを読み込む前に、`TMSWP\Core\Tests\Support\IntegrationEnvironmentGuard::assertSafe()` が、テストのテーブルが tests/wp-tests-config.php の `wptests_` になる状態かを検査し、そうでなければ例外で止める。テストライブラリは起動時にテーブルを作り直して投稿・ユーザー等の行を削除するため、接頭辞やテーブル名が開発用サイト(`wp_`)に向いたまま起動すると開発用サイトのデータを消す。検査する状態は次のとおり。
  - `WP_TESTS_DIR`(§4.2)が無い、または `includes/bootstrap.php` を含まない。統合テストは wp-env の `cli` コンテナ内(`composer test:integration`)でだけ動く。
  - `WP_TESTS_CONFIG_FILE_PATH` が定義済み。別の設定ファイル(wp-env が `WP_TESTS_DIR` の直下に置く `wp_` 接頭辞のもの等)で動く恐れがある。bootstrap はこれを必ず tests/wp-tests-config.php に定義する。
  - `WP_TESTS_TABLE_PREFIX`・`CUSTOM_USER_TABLE`・`CUSTOM_USER_META_TABLE` が定義済み。後の2つは接頭辞に関係なくユーザー表を差し替える(`wpdb::tables()`)。
  - tests/wp-tests-config.php が定義する定数(`ABSPATH`・`DB_*`・`WP_TESTS_DOMAIN` 等・`WP_DEBUG`)が定義済み。設定ファイルの `define()` が効かず、先の値が使われる。
  - グローバル変数 `$table_prefix` または `$wpdb` が既にある。PHPUnit は bootstrap をメソッド内で読み込むため、設定ファイルの `$table_prefix = 'wptests_'` はローカル変数になる。`wp-settings.php` はグローバルの `$table_prefix` が既にあればそちらを使い、`require_wp_db()` は既存の `$wpdb` を使い続ける。どちらも無ければ、接頭辞は設定ファイルの `wptests_` だけから決まる。
- 検査は引数だけを見る副作用の無い関数で、各状態で例外になることをユニット層(`IntegrationEnvironmentGuardTest`)で確かめる。
- 読み込みの間の非推奨は `ErrorException` となり、bootstrap のエラーとして実行全体が止まる。テスト中の非推奨は 4.5 のとおりテストの失敗になる。

#### fixture の起動経路

`tests/fixtures/_boot.php` は、WordPress の読み込み前に次を登録するだけで、`Kernel::boot()` を自分では呼ばない。

1. `tests/fixtures/_activate.php` のフィルタを `tests_add_filter()` で登録する。`pre_option_active_plugins` で、無料版 ACF(`advanced-custom-fields/acf.php`)と fixture のプラグインを有効なプラグインにし、`muplugins_loaded` で `tests/fixtures/themes` をテーマの置き場に登録し、`pre_option_template` / `pre_option_stylesheet` で fixture のテーマを有効なテーマにする。
2. `init`(priority 0)で 2 つの Kernel が boot 済みかを確かめ、欠けていれば例外にする。
3. `wp_loaded` で登録済みメタキーを控える(下記の IntegrationTestCase が使う)。

boot は WordPress 自身が行う。fixture のプラグイン本体ファイルはプラグインの読み込み時(`plugins_loaded` の前)に、fixture のテーマの `functions.php` は `after_setup_theme` の前に、それぞれ直下で `Kernel::boot()` を呼ぶ。どちらも `init` より前であり、実際のテーマ・プラグインと同じ経路である。fixture の Kernel は debug の既定値(`WP_DEBUG` = true)で boot する。debug 時は遅延 boot が例外になる(02 文書 §3.3)ため、`init` 発火後に boot する経路は統合テストの起動では通らない。fixture の app/ は、各本体ファイルが Composer の `ClassLoader` で PSR-4 登録する。debug 時の boot は毎回 app/ を走査し、探索結果ファイルを読まない(02 文書 §4)。

#### fixture の探索結果ファイル

fixture のテーマとプラグインの探索結果ファイル(`tmswp-discovery.php`。02 文書 §6)は、利用者と同じ `wp tmswp discover` で生成してコミットする。

- `composer discover:build` / `discover:check` は、wp-env の `cli` コンテナで `wp --require=tests/fixtures/_wp-cli.php tmswp discover --kernels=2`(照合は`--check`付き)を実行する。`--kernels=2`は、fixtureのテーマとプラグインの2つのKernelが起動したことを確かめる(02文書§3.6。どちらかが起動しないと、照合はそのKernelを比べないまま成功するため)。`_wp-cli.php`は、有効なテーマの置き場を`template_root`・`stylesheet_root`のオプションの読み出しの上書きでfixtureのテーマの置き場に固定し、開発用サイトのsite transient `theme_roots`(fixtureのテーマの置き場を含まない形で作られうる)に左右されないようにする。WP-CLI は wp-env の開発用サイト(接頭辞 `wp_`)を読み込み、統合テストのテーブル(`wptests_`)には触れない。
- `_wp-cli.php` は `_activate.php` のフィルタを `WP_CLI::add_wp_hook()` で登録し、そのプロセスの中だけ fixture のテーマとプラグインを有効にする。有効化はオプションの読み出しの上書きで、これ自体は DB に書かない。boot は WordPress が fixture を読み込むときに fixture 自身が行い、コマンドは利用者と同じ `Kernel::all()` の Kernel を走査する。
- fixture は利用者と同じ経路で起動するため、fixture のコンポーネントの実行時の登録(`#[AsUserRole]` の `add_role` / `add_cap` 等)は開発用サイトの `wp_` のテーブルに反映されうる。開発用サイトはこのリポジトリの開発専用のため、これを許容し、隔離はしない。
- ルートの Composer のオートローダは、実際のテーマ・プラグインと同じくプラグインの読み込み時(`muplugins_loaded`)に登録する。WP-CLI の phar は `--require` の後に自身の依存(古い psr/container を含む)のオートローダを先頭に登録するため、`--require` の時点で登録すると psr/container が phar 側から読まれ、`Container` の宣言と合わずに止まる。
- fixture の app/ を変えるタスクは `composer discover:build` で探索結果ファイルを作り直してコミットする。`composer qa` と CI の `coverage` ジョブは `discover:check` で照合し、古ければ失敗する(§5)。統合テスト(`KernelBootIntegrationTest`)も、コミット済みの探索結果ファイルが走査結果と一致することを確かめる。

#### スターターの統合テスト

`tests/Integration/StarterProjectsIntegrationTest.php`(testsuite `starters`)は、スターター(`starters/theme`・`starters/plugin`)を開発用サイトで動かして、次を確かめる。

- コミット済みのスターターの探索結果ファイル(`starters/*/tmswp-discovery.php`)が、スターターの`app/`と`build/blocks/`から`wp tmswp discover`で作るものと一致する。食い違う場合は、作り直したファイルを`var/starter-site/tmswp-discovery.<theme|plugin>.php`に残し、スターターへ写すよう失敗のメッセージで示す。
- create-projectと同じ順(依存を入れてオートローダを作った後で、`composer run-script post-create-project-cmd`で`bin/setup.php`と`composer dump-autoload`を実行する)で作ったテーマ1つとプラグイン2本(スラッグ`client-theme`・`shop-tools`・`member_area`)が、Composerが作り直したオートローダで新しい名前空間を持ち、`composer validate`が通り、同じサイトで別々の名前空間(`ClientTheme`・`ShopTools`・`MemberArea`)のKernelとして起動する。`wp tmswp discover`で3つの探索結果ファイルを作れて、`--check`が通る。フロント(トップページ)が200を返し、テーマの出力とテーマのブロックの登録があり、PHPのエラー・警告・非推奨の表示が無い。

fixtureとの共存は次のとおり。

- スターターの写しは、リポジトリの`var/starter-site/`の下に作る。リポジトリ全体が`wp-content/plugins/tmswp`にマウントされているため(4.7)、写しのプラグインはWordPressのプラグインの置き場の中にあり、マウントを足さなくてよい。写しはテストごとに消す。
- WordPressは別のプロセスで動かす。WP-CLI(`wp --require=tests/Integration/fixtures/starter-site/wp-cli.php`)と、PHPの組み込みサーバ(`php -S`。ルーターは`router.php`)である。どちらも`site.php`が、そのプロセスの中だけ写しのテーマとプラグインを有効にする(fixtureの`_activate.php`と同じく、オプションの読み出しの上書きで、DBには書かない)。テーマの置き場は`template_root`・`stylesheet_root`の読み出しも上書きして決め、プロセスの終わりに`theme_roots`のサイトのtransientを消す(ほかのプロセスが自分のテーマの置き場で作り直すようにする)。
- このテストのプロセス(fixtureのテーマとプラグインが起動済みで、テーブルは`wptests_`)では、写しのKernelをbootしない。別のプロセスは開発用サイト(`wp_`)を読む。組み込みサーバのルーターは、正規のURLへのリダイレクトとWP-Cronの起動を止める。
- 写しの依存は、cliコンテナのComposerで写しの`vendor/`に入れる。ネットワークに依存しないよう、写しのcomposer.jsonに限って、Packagistを使わず、tmswp/coreを`packages/core`から、psr/log・psr/containerをリポジトリの`vendor/`から入れ(pathリポジトリ)、require-devを外す。

#### IntegrationTestCase

全パッケージの統合テストは`TMSWP\Testing\IntegrationTestCase`(`tmswp/testing`。`WP_UnitTestCase`を継承する抽象クラス)を継承し、テストの中で`Kernel::boot()`を呼ばない。例外は`KernelBootIntegrationTest`の本番相当(debug=false)の起動の確認だけで、一時ディレクトリに作ったプラグイン形態のKernelを、fixtureと重ならない名前空間でbootする(テーマ形態にしないのは、他のテストが発火させるテーマ切替のフックにそのKernelを登録しないため)。`init`の後のbootになるため、遅延bootのログはLogの出力先を一時的に差し替えて捕まえる。

- `themeKernel()` / `pluginKernel()` で fixture の Kernel を得る。
- fixture のクラスは PHPStan の解析対象外のため、テストからは FQCN の文字列で参照し、`fixtureClass($fqcn, $parent)` で親クラスを確かめた class-string として使う。
- WP のテストライブラリは、各テストの `tear_down` で登録済みメタキーをすべて解除する(`unregister_all_meta_keys()`)。`IntegrationTestCase::set_up()` は、`_boot.php` が `wp_loaded` で控えた登録を毎回戻す。
- DBの変更は`WP_UnitTestCase`がテストごとにロールバックする。テスト中の`CREATE TABLE`はテストライブラリが一時テーブルに書き換え、接続が続く限り残るため、作ったテーブルは`tear_down`で削除する。Migratorを使うテスト等、一時テーブルでは確かめられないテストは、`useRealTables()`に作るテーブルを宣言して、実際のテーブルを作る(宣言したテーブルは`tear_down`で消える。DDLの暗黙のコミットと後始末は4.11)。フックはテストライブラリが最初のテストの前の状態に戻すため、テスト中の`add_filter` / `remove_action`は後片付けが要らない。
- WordPress の契機は WordPress の関数・フックで再現する。テーマの有効化は、`switch_theme()` が前のリクエストで残す `theme_switched` を置いてから `check_theme_switched()` を呼ぶ(`after_switch_theme` が発火する)。管理画面のリクエストは `wp-admin/includes/admin.php` を読み込み、`set_current_screen()` で管理画面にしてから `admin_init` を発火する。FW 自身のフック(`tmswp/activated` 等)を直接発火して契機を代用しない。

### 4.7 tests/wp-tests-config.php と .wp-env.json(完全版)

`tests/wp-tests-config.php`(wp-env の `cli` コンテナ内で読まれる):

```php
<?php

declare(strict_types=1);

define('ABSPATH', '/var/www/html/');

define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: 'password');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: 'mysql');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'TMSWP Test Suite');
define('WP_PHP_BINARY', 'php');
define('WP_DEBUG', true);
```

- DBの接続先はwp-envの環境(mysqlコンテナの`wordpress`データベース。中身はMariaDB)を既定値とし、環境変数で上書きできる。MySQLでの実行は4.11のとおり`WP_TESTS_DB_HOST`で接続先を変える。テーブル接頭辞`wptests_`で開発用サイトのテーブル(`wp_`)と分ける。
- `error_reporting()` はここで設定しない。`WP_DEBUG` が true のとき WordPress の `wp_debug_mode()` が `error_reporting(E_ALL)` に設定し直すため、ここでの設定はテストの実行時には効かない。非推奨の扱いは 4.5・4.6 のとおりである。

`.wp-env.json`(リポジトリ既定。CI では `.wp-env.override.json` で core / phpVersion を差し替える):

```json
{
  "core": null,
  "phpVersion": "8.3",
  "testsEnvironment": false,
  "plugins": [
    "https://downloads.wordpress.org/plugin/advanced-custom-fields.6.8.10.zip"
  ],
  "mappings": {
    "wp-content/plugins/tmswp": "."
  },
  "config": {
    "WP_DEBUG": true,
    "SCRIPT_DEBUG": true
  }
}
```

- `core: null` は最新安定版。特定バージョンは `"core": "https://wordpress.org/wordpress-7.0.zip"` 形式で override する。`phpVersion` の既定は下限の 8.3。
- `testsEnvironment: false` により、wp-env は開発用の環境(`wordpress` / `cli` / `mysql`)だけを作る。@wordpress/env では11から(package.jsonで固定した12.0.0も同じ)、development と tests の 2 環境を同時に作る既定の動作が非推奨のため、統合テストはこの環境の `cli` コンテナで実行し、テーブル接頭辞 `wptests_` で開発用サイトのテーブルと分ける。
- `plugins` に wordpress.org の無料版 ACF(本家 WP Engine の `advanced-custom-fields`)を、版を固定した zip で入れる(D-11)。ACF Pro 限定機能のテストは CI では行わず、手元で隔離した環境で走らせる(4.9)。
- `mappings` により monorepo 全体が `wp-content/plugins/tmswp` にマウントされ、コンテナ内からルートの `vendor/` / `vendor-bin/` / 各設定ファイルにそのままアクセスできる。これが `composer test:integration` の `--env-cwd=wp-content/plugins/tmswp` の前提。
- スターターから作ったテーマ・プラグインのためのマウントは足さない。スターターの統合テスト(4.6)は、写しを`var/starter-site/`(このマウントの中)に作り、別のプロセスの中だけ有効にするため、開発用サイトの設定とマウントを変えずに済む。
- `.wp-env.override.json` は `.gitignore` に追加する。

### 4.8 カバレッジ方針

- 計測対象は6パッケージ(core・acf-bridge・settings-page・thumbnail・ja-toolkit・testing)のsrcである。4.3の`<source>`と4.5の`<coverage><include>`は同じディレクトリを指す。testing(テストの支援コード)も、案件のプロジェクトのテストが使う配布物なので計測する。
- ユニット層と統合層のカバレッジを合算して判定する(DR-12)。WordPress に依存するクラス(PostType・Taxonomy・UserRole・PageRegistrar・AcfFieldAccess・AcfCommand・thumbnail の RestController 等)は、モックを多用したユニットテストではなく、実物の WordPress での統合テストで確かめる。
- 計測の方法:

| 層 | 実行環境 | ドライバ | コマンド | 出力 |
|---|---|---|---|---|
| ユニット | ホストの PHPUnit 11 | pcov(ローカル: `pecl install pcov`、CI: `setup-php` の `coverage: pcov`) | `composer test:coverage:unit`(`XDEBUG_MODE=off` で Xdebug を止める) | `var/coverage/unit.xml` |
| 統合 | wp-env の `cli` コンテナ内の PHPUnit 9.6 | Xdebug(`composer wp:start:coverage` で wp-env を `--xdebug=coverage` 付きで起動して有効にする) | `composer test:coverage:integration` | `var/coverage/integration.xml` |

- `composer wp:start` で起動し直すと、wp-env の Xdebug は無効に戻る。
- 統合層で数えるのは、PHPUnit がテストを実行している間に通った行である。WordPress の読み込み中(fixture の Kernel の boot、init での登録)に通った行は数えない。登録の処理を数えるには、テストの中で登録の処理を呼ぶ。
- 合算の方式: PHPUnit 9 系と 11 系では、内部のカバレッジ形式(`.cov`)に互換が無い。そのため、両層の clover を `bin/coverage-check.php` が行単位で合わせる。
  - ファイルのパスは、リポジトリからの相対パスに正規化する。ホストのパスはリポジトリのルートを、コンテナ内のパスは `/var/www/html/wp-content/plugins/tmswp`(4.7 の `mappings`)を除く。
  - 同じファイルの同じ行は、どちらかの層で実行されていれば実行済みとする。行の集合は両層の和集合である(PHPUnit の版によって、実行可能と判定する行がわずかに異なるため)。
- CIゲート: 次の2つをどちらも満たすこと。判定はqa.ymlの`coverage`ジョブ(PHP 8.3 / WordPress最新版の1セル)で行う(5.1)。
  - **6パッケージ全体の行カバレッジ ≥ 版ごとの閾値**。`12-implementation-plan.md` §0.7の版ごとの表と一致させる。v0.9.0(導入できる版)の閾値は85%。
  - **パッケージ別の行カバレッジ ≥ パッケージ別の閾値**。全体の値に、薄いパッケージの不足が埋もれないようにする。閾値は6パッケージ(core・acf-bridge・settings-page・thumbnail・ja-toolkit・testing)とも80%(v0.8.0で決めた。下表の「拡張パッケージ各src」の目標と同じ値)。
  - どちらの値も、unitとintegrationを合算した値である。閾値はcomposer.jsonの`test:coverage:check`の引数の1か所で定義する(全体は第1引数、パッケージ別は`--package=<名前>:<閾値>`)。
- モジュール別の目標値(レビュー指針。合算の行カバレッジで見る。CI自動ゲートは全体とパッケージ別の閾値のみ):

| モジュール | 目標(行) | 根拠 |
|---|---|---|
| `Core\Support`(Arr/Str/Html) | 95% | 純関数群。旧 ArrTest/TextTest の移植で大半を充足 |
| `Core\Form\Rule` | 95% | バリデーションは仕様の核。旧 Rule テスト移植 + 未テスト 13 Rule の新規作成 |
| `Core\Form`(ライフサイクル/ConfirmToken/TempFileStore) | 90% | セキュリティ境界(HMAC・CSRF・一時ファイル) |
| `Core\Model`(QueryBuilder/Migrator/TableDefinition) | 90% | 旧 FW で完全未テストだった領域(新規 TDD 必須) |
| `Core\Meta` / `Core\Option` | 90% | MetaBag 型変換・スキーマ登録 |
| `Core\Log` | 90% | 旧 LogTest/SimpleLoggerTest 移植 |
| `Core\Kernel` / `Core\Container` | 85% | Discovery はファイル走査をユニット化(vfsStream) |
| `Core\View` | 85% | パス解決ロジックのユニット化 |
| 拡張パッケージ各 src | 80% | WP 依存が濃い部分は統合層で補完 |

`bin/coverage-check.php` の仕様(完全):

- 引数: `argv[1]` =全体の最低カバレッジ(0〜100の数値、%)、`argv[2]`以降= `--package=<名前>:<最低カバレッジ>`(0個以上。名前は`packages/`の直下のディレクトリ名で、英小文字・数字・`-`)とcloverのパス(1つ以上)。個数・型・範囲が不正な場合、同じパッケージの`--package`が重なる場合は、usageをSTDERRに出してexit(2)。
- 手順:
  1. 各 clover を `simplexml_load_file()` で読む。ファイルが無い・XML として読めない場合、ルート要素が `coverage` でない・`project` が無い場合、`file` が 1 つも無い場合、`type="stmt"` の行が 0 の場合は、STDERR にメッセージを出し exit(2)。
  2. 各 `file` の `name` をリポジトリからの相対パスに正規化する。まずパスの要素の `.`・`..`・重複したスラッシュを解消し(同じファイルの別表記を同じ計測対象にする)、そのうえでホストのルート(`bin/` の親を realpath したもの)とコンテナ内のルートのどちらかの下にあるかを確かめる。絶対パスでない、ファイルシステムのルートより上を指す、どちらのルートの下にも無い(`<ホストのルート>/../outside.php` を含む)パスは exit(2)。
  3. 各 `file` の `type="stmt"` の行を集める。`num` が 1 以上、`count` が 0 以上の、int で表せる範囲(`PHP_INT_MAX` 以下)の整数でなければ exit(2)。受け付ける表記は、先頭に 0 が付かない 10 進表記(`0` は可。`01`・`00` は不可)だけである(PHPUnit の clover はこれ以外を出力しないため、誤りを早く検出する)。ファイルと行番号の組ごとに、どれかの clover で `count > 0` なら実行済みとする。
  4. cloverごとの行数と実行済みの行数をSTDOUTに出す。
  5. `--package`があれば、合算したファイルを相対パスの`packages/<名前>/`でパッケージに分ける。`--package`の無いパッケージのファイル(`packages/`の外のファイルを含む)があれば`No package minimum for <相対パス> …`、ファイルが1つも無いパッケージの`--package`があれば`Coverage reports contain no files of package <名前>`をSTDERRに出しexit(2)(判定から漏れたパッケージや、何も比べない判定を通さない)。
  6. パッケージ(名前の順)ごと、続いて全体について、`実行済みの行数/行数* 100`を閾値と比べる。比べるのは**丸める前の値**である(`実行済みの行数* 100 >=閾値*行数`。77.995%は閾値78を満たさない)。表示する値は小数2桁で**切り捨てる**(閾値を満たさない値を、満たすように見せない)。閾値以上なら`Package core coverage 91.81% (5286/5757 lines in 172 files) (>= 80%)`・`Combined coverage 92.90% (7951/8558 lines in 236 files) (>= 78%)`の形式でSTDOUTに、閾値未満なら`Combined coverage 77.99% (15599/20000 lines in 1 files) is below required 78%`の形式でSTDERRに出す。すべての結果を出したうえで、どれか1つでも閾値未満ならexit(1)、すべて閾値以上ならexit(0)。
- clover は、実行可能な行がメソッドの開始行と重なる場合、その行を `type="method"`(メソッド全体の集計値)として出力し、行単位の実行有無を持たない。合算は `type="stmt"` の行だけで行うため、行数は PHPUnit が表示する `statements` よりわずかに少ない。

### 4.9 ACF Proでの統合テスト(手元だけ)

CIは無料版ACFで確かめる。ACF Pro専用の型(repeater・flexible_content・gallery・clone)とPro限定機能のテストは、ACF Proのzipのパスを環境変数`TMSWP_ACF_PRO_ZIP`で渡したときだけ、手元で走らせる(D-49)。

```bash
TMSWP_ACF_PRO_ZIP=/path/to/advanced-custom-fields-pro.zip composer test:integration:acf-pro
# phpunitの引数を足すとき
TMSWP_ACF_PRO_ZIP=/path/to/advanced-custom-fields-pro.zip composer test:integration:acf-pro -- --filter AcfFieldTypesIntegrationTest
```

- `tools/acf-pro-integration.sh`が行う。`TMSWP_ACF_PRO_ZIP`が無い・ファイルでない・読めない・`advanced-custom-fields-pro/acf.php`の版のヘッダを持たない場合は、理由を出してexit 1で止まる。
- zipは、リポジトリの外の作業ディレクトリ(`${TMPDIR}/tmswp-acf-pro/`)に展開し、成功しても失敗しても終了時に消す。リポジトリにはProの本体もそのパスも残らない。
- wp-envは、作業ディレクトリに書いた別の設定ファイル(`wp-env --config=...`)で起動する。wp-envの作業ディレクトリ(`~/.wp-env/`の下)・コンテナ・DBは設定ファイルのパスごとに分かれるため、本体の環境(`.wp-env.json`)と共有しない。ポートは8898にする。WordPressとPHPの版は`.wp-env.json`と同じにする。
- Proは無料版のスラッグ(`wp-content/plugins/advanced-custom-fields`)にマウントするため、fixtureの有効化(`tests/fixtures/_activate.php`)はそのまま使える。
- 走らせるのはacf-bridgeの統合テストの全体で、グループ`acf-free`(無料版であることを確かめるテスト)を外す。Pro専用のテストはグループ`acf-pro`とし、`acf_is_pro()`が偽なら失敗にする(skipにしない)。通常の`composer test:integration`は4.5のXMLでこのグループを除外する。
- ライセンスキーは使わない。Proのzipは、ACFの公式のcomposerリポジトリ(`connect.advancedcustomfields.com`。ライセンスキーをusernameにしたHTTP basic認証)からも取得できる。スクリプトはこの取得を行わない。
- 手元の確認に使う版は、CIの無料版と同じ版(6.8.10)にする。結果は、その版の受け入れの記録に残す。

### 4.10 E2E(Playwright。examples/sample-site)

FWの主な機能を、examples/sample-site(専用のwp-env、ポート8882)の画面とHTTPの応答で確かめる(D-55)。ユニット・統合の2層とは別の確認で、カバレッジの計測には含めない。

```bash
cd examples/sample-site
composer wp:start   # sample-siteのwp-env(http://localhost:8882)
composer wp:setup   # テーマを有効にし、dev/seed.phpで確認用のページ・製品・設定を作る
cd ../..
node node_modules/@playwright/test/cli.js install chromium   # 初回と、@playwright/testの版を上げたとき
composer test:e2e
# ファイルや名前で絞るとき
composer test:e2e -- tests/E2E/contact-form.spec.mjs
composer test:e2e -- --grep Thumbnail
```

- 道具は`@playwright/test`だけを使う(ルートの`package.json`で版を完全一致で固定する。1.1補足)。`@wordpress/e2e-test-utils-playwright`は、依存が重くNode.jsの要件が上がるため入れない。ログイン(`wp-login.php`へのPOST)とwp-envのcliの呼び出しは、`tests/E2E/support/`の小さな補助で書く。
- JavaScript(ESM。拡張子`.mjs`)で書き、型はJSDocで書く。TypeScriptもPlaywrightがそのまま実行できるが、型の検査には`typescript`の依存が要る。検査しない型の注釈は誤りを見逃すため、TypeScriptは使わない。
- 設定は`playwright.config.mjs`である。baseURLは`http://localhost:8882`、workersは1、retriesは0(CIでも)、ブラウザはChromiumだけにする。webServerは使わない(wp-envの起動はcomposerのスクリプトで行い、コマンド名でwp-envを解決しない。1.1補足)。失敗したテストのtraceを`var/e2e/test-results/`に残す。
- wp-envのcliは、`tests/E2E/support/wp-env.mjs`が`node node_modules/@wordpress/env/bin/wp-env run cli ...`を、sample-siteのディレクトリをcwdにしてnodeの子プロセスで呼ぶ(sample-siteのcomposerのスクリプトと同じ)。値は、PHPの式へbase64のJSONで渡す(wp-envの引数の解析を通さない)。
- すべてのテストで、ページ本体の文書(リダイレクトを除く)にPHPのエラーの表示(`Fatal error`・`Parse error`・`Warning:`・`Notice:`・`Deprecated:`、WordPressの致命的なエラーの画面)が無いことと、ページの未捕捉の例外(pageerror)が0件であることを、テストの終わりに確かめる(`tests/E2E/support/test.mjs`)。
- テストは、seed(`composer wp:setup`)の状態に依存してよい。状態を変えるテスト(設定・ACFの値・作ったユーザー・エディタの下書き)は、テストの終わりに元に戻す。記録したメール(`sample_site_captured_mails`。直近の10通だけが残る)は件数で数えず、テストごとに本文へ入れる一意の印で数える。2回続けて実行しても通る。
- 確かめる機能は、examples/sample-site/README.mdの「確認の方法」の1〜5である。

| ファイル | 確かめること |
|---|---|
| `pages.spec.mjs` | 主なページ(公開側と管理画面)が200を返す |
| `contact-form.spec.mjs` | フォームの入力・確認・完了と`/contact/thanks/`への移動、必須の項目が空のときのエラー、メールの記録、完了画面の再読み込みと確認画面の再送でメールが増えないこと、`/contact/`の`Cache-Control`の`no-store` |
| `settings-page.spec.mjs` | 「設定 > Sample Site」の保存(PRG。成功の通知がちょうど1つで、再読み込みでは出ない)とトップページの説明文、メールアドレスの形でない通知先の拒否、`manage_options`の無いユーザーへの403 |
| `thumbnail.spec.mjs` | 生成の前のRESTのURLの302(`Cache-Control`と`Location`)と移った先の画像、生成の後の静的なURL、署名を変えたURL・署名の無いURLの403 |
| `ja-toolkit.spec.mjs` | 日本語のタイトルの製品のスラッグ`product-<ID>`、`/?p=<ID>`の301、フリガナのひらがな・半角カナの全角カタカナへの変換 |
| `acf.spec.mjs` | 製品の詳細の価格・仕様(ACFの値)と、編集画面で値を変えたときの表示の変化(無料版のACF) |
| `blocks.spec.mjs` | `/blocks/`のブロック`sample-site/latest-products`、固定ページの編集画面のカテゴリ「Sample Site」からの挿入、製品の編集画面の`allowedBlocks()`による制限 |

- CIでは、`sample-site`ジョブ(5.1)が`composer qa`の後に、ブラウザを入れて(`node node_modules/@playwright/test/cli.js install --with-deps chromium`。キャッシュしない)`composer test:e2e`を実行する。失敗したときだけ、`var/e2e/test-results/`を成果物`e2e-traces`に保存する。
- 対象のWordPressは、sample-siteの`.wp-env.json`の`core: null`(wp-envの既定の最新版)である。エディタの画面の変化(ボタンの名前等)で失敗したときは、補助かテストの要素の指定を直す。

### 4.11 MySQLでの統合テスト(DBの構成)

wp-env(12)は、開発用サイトのDB(`mysql`のサービス)をMariaDBに固定し、設定でMySQLにできない。統合テストの既定の接続先はこのMariaDBである(4.7)。MySQL 8.4 LTSと9.7 LTSでの確認は、MySQLのコンテナを別に立て、wp-envのcliから`WP_TESTS_DB_HOST`(tests/wp-tests-config.php)で接続して、統合テストの全体を実行する(D-56)。CIでは`mysql`ジョブ(5.1。2セル)が毎回行う。

| 接続先 | 使う場面 | 版 |
|---|---|---|
| wp-envのMariaDB(ホスト`mysql`・DB`wordpress`) | `composer test:integration`、CIの`integration`・`coverage`、`http://wordpress/`を叩くテストのサイト側 | wp-envの既定(手元の確認では12.3.3) |
| servicesのMySQL(別名`mysql-under-test`・DB`wordpress`) | CIの`mysql`ジョブの統合テスト(WordPressのテストライブラリと、テストのプロセスのwpdb) | `mysql:8.4`・`mysql:9.7`のイメージ(LTSの系列のタグ) |

- `mysql`ジョブのPHPは8.3、WordPressは7.0に固定する(DBの違いを確かめるジョブのため、PHP・WordPressの版の軸は`integration`の4セルに任せる)。
- `http://wordpress/`を叩くテスト(Formのページ・一時ファイルのURL等。`FormIntegrationTest`・`FormFileIntegrationTest`・`FormTempFileIntegrationTest`)のサイト側は、wp-envの`wordpress`のコンテナで動き、開発用サイトのMariaDBを使う。本物のWP-CLIを別のプロセスで実行するテスト(`MigrateCommandIntegrationTest`・`DiscoverCommandIntegrationTest`等。開発用サイトに対して実行する)も同じである。`mysql`ジョブでも、これらの部分はMySQLで確かめていない。テストのプロセス(cli)の側のwpdbだけがMySQLに接続する。
- 一時テーブルとMySQLの違い: WordPressのテストライブラリは、テストの中のCREATE TABLE・DROP TABLEを一時テーブルの文に書き換える。MySQLのSHOW TABLES・SHOW TABLE STATUSは一時テーブルを返さず(MariaDBは11.2から返す)、MySQLは1つの文で同じ一時テーブルを2回参照できない。Migratorは適用の後にSHOW TABLESでテーブルの有無を確かめるため、Migratorを使う統合テスト(`ModelIntegrationTest`・`MigratorIntegrationTest`・`MigratorColumnTypeIntegrationTest`)は、`IntegrationTestCase::useRealTables()`(tmswp/testingの`RealTables`)で実際のテーブルを作る。作るテーブル(接頭辞を除いた名前)は`useRealTables()`に宣言し、そのテストのtear_downで、宣言したテーブルのうち`useRealTables()`の時点に無かったものだけを名前で消す。DB全体の差分は使わない(ほかの接続が作ったテーブルを消さないため)。
- DDLは暗黙にコミットする。DDLより前にテストが書いた行(Migratorのハッシュのoption、利用者、WordPressが保存したtransient等)はロールバックで戻らないため、`useRealTables()`が返す`RealTables`の`onTearDown()`で、作ったIDや名前を控えて消す(ロールバックの後・テーブルの削除の前に動く)。統合テストの全体の実行で、各テストの前後に、別の接続で`wptests_`の主なテーブル(users・usermeta・terms・term_taxonomy・term_relationships・posts・postmeta・options・comments・termmeta)の件数・optionの名前・実際のテーブルの一覧が変わらないことを、MariaDBとMySQL 8.4・9.7で確かめた(v0.9.0-T3a)。
- 接続先の取り違えを防ぐため、`mysql`ジョブは統合テストの前に、tests/wp-tests-config.phpと同じ設定で接続して`SELECT VERSION()`を実行し、版がそのセルのMySQL(`8.4.`・`9.7.`で始まる)でなければ失敗する。
- MySQLのコンテナは、servicesのネットワークからwp-envのネットワークへ、`docker network connect --alias mysql-under-test`で参加させる。servicesで公開したポートへ`host.docker.internal`(wp-envの`extra_hosts`のhost-gateway)経由で接続する方法は、手元(macOSのDocker Desktop)では届くが、Linuxのrunnerでは届き方がDockerのネットワークの分離とファイアウォールの設定に依存し、手元で確かめられないため使わない。
- MySQL 8.4・9.7の既定の認証(`caching_sha2_password`)で、wp-envのPHP(mysqli)から接続できる。9.7には`mysql_native_password`が無い。

手元で再現する手順(ルートのwp-envを起動した状態。コンテナ名とポートは手元の他の環境と重ねない):

```bash
docker run -d --name tmswp-mysql84 -e MYSQL_ROOT_PASSWORD=password -e MYSQL_DATABASE=wordpress mysql:8.4
docker network connect --alias mysql-under-test "<wp-envのネットワーク(docker network lsのwp-env-<名前>-<ハッシュ>_default)>" tmswp-mysql84
node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp \
  env WP_TESTS_DB_HOST=mysql-under-test vendor-bin/wp-tests/vendor/bin/phpunit -c phpunit.integration.xml.dist
docker rm -f tmswp-mysql84   # 終わったら消す
```

---

## 5. GitHub Actions

### 5.1 .github/workflows/qa.yml(完全版)

```yaml
name: QA

on:
  push:
    branches: [main]
  pull_request:

concurrency:
  group: qa-${{ github.ref }}
  cancel-in-progress: true

jobs:
  lint:
    name: Coding Standards
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.3'
          coverage: none
      - uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0
      - name: Forbid PHPStan baseline
        run: |
          if ls phpstan-baseline* 2>/dev/null; then
            echo '::error::PHPStan baseline files are forbidden (DR-12)'
            exit 1
          fi
      - run: composer lint

  analyse:
    name: Static Analysis
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.3'
          coverage: none
      - uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0
      - run: composer analyse
      # WordPressを起動しないtms-acfで、fixtureのテーマとプラグインの生成物とacf-jsonの一致を確かめる(--strict)
      - run: composer acf:check:fixtures

  monorepo:
    name: Monorepo Validate
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.3'
          coverage: none
      - uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0
      - run: composer monorepo:validate

  rules-verify:
    name: Rules Code Examples
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.3'
          coverage: none
      - uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0
      - name: Extract rules code examples
        run: php tools/extract-rules-code.php
      - name: Verify extracted examples
        run: php tools/verify-rules-code.php

  unit:
    name: Unit (PHP ${{ matrix.php }})
    runs-on: ubuntu-24.04
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.5']
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0
      # --testdoxで、ログにテストの名前を出す
      - run: composer test:unit -- --testdox

  integration:
    name: Integration (PHP ${{ matrix.php }} / WP ${{ matrix.wp }})
    runs-on: ubuntu-24.04
    needs: [unit]
    timeout-minutes: 20
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.5']
        wp: ['7.0', 'latest']
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '24'
          cache: npm
      - run: npm ci
      - uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0
      # 検証する WordPress の版を確定する。latest は WordPress.org の版情報 API から最新版を取得する。
      # テストライブラリは、wp-env がこの版と同じタグの wordpress-develop から取得する(WP_TESTS_DIR)。
      - name: Resolve WordPress version
        id: wp
        # bash を明示して pipefail を有効にし、curl の失敗をパイプ先の成功で隠さない
        shell: bash
        run: |
          if [ "${{ matrix.wp }}" = "latest" ]; then
            version=$(curl -fsS https://api.wordpress.org/core/version-check/1.7/ | jq -er '.offers[0].current')
          else
            version='${{ matrix.wp }}'
          fi
          echo "version=${version}" >> "$GITHUB_OUTPUT"
      - name: Create wp-env override
        run: |
          printf '{"core": "https://wordpress.org/wordpress-%s.zip", "phpVersion": "%s"}\n' \
            "${{ steps.wp.outputs.version }}" "${{ matrix.php }}" > .wp-env.override.json
      # Docker イメージ取得の一時的な失敗に備えて 3 回まで再試行する
      - name: Start wp-env
        run: |
          for attempt in 1 2 3; do
            if composer wp:start; then
              exit 0
            fi
            if [ "$attempt" -eq 3 ]; then
              exit 1
            fi
            sleep 10
          done
      - name: Run integration tests
        run: composer test:integration
      - name: Show wp-env logs on failure
        if: failure()
        run: node node_modules/@wordpress/env/bin/wp-env logs development --no-watch

  # MySQL 8.4 LTS・9.7 LTSでの統合テスト(D-56)。wp-env 12はDBをMariaDBに固定するため、servicesでMySQLを立て、
  # wp-envのネットワークに別名mysql-under-testで参加させ、wp-envのcliからWP_TESTS_DB_HOSTで接続する。
  # PHPとWordPressは8.3・7.0に固定する。http://wordpress/を叩くテストは、このジョブでも開発用サイト(wp-envのMariaDB)を使う。
  mysql:
    name: Integration (MySQL ${{ matrix.mysql }} / PHP 8.3 / WP 7.0)
    runs-on: ubuntu-24.04
    needs: [unit]
    timeout-minutes: 20
    strategy:
      fail-fast: false
      matrix:
        mysql: ['8.4', '9.7']
    services:
      mysql:
        image: mysql:${{ matrix.mysql }}
        env:
          MYSQL_ROOT_PASSWORD: password
          MYSQL_DATABASE: wordpress
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -ppassword"
          --health-interval=5s
          --health-timeout=5s
          --health-retries=30
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.3'
          coverage: none
      - uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '24'
          cache: npm
      - run: npm ci
      - uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0
      - name: Create wp-env override
        run: |
          printf '{"core": "https://wordpress.org/wordpress-7.0.zip", "phpVersion": "8.3"}\n' > .wp-env.override.json
      - name: Start wp-env
        run: |
          for attempt in 1 2 3; do
            if composer wp:start; then
              exit 0
            fi
            if [ "$attempt" -eq 3 ]; then
              exit 1
            fi
            sleep 10
          done
      # servicesのMySQLは別のネットワークにあるため、wp-envのネットワークに参加させる
      - name: Connect MySQL to the wp-env network
        run: |
          containers=$(docker ps -q --filter label=com.docker.compose.service=wordpress)
          if [ "$(printf '%s\n' "${containers}" | grep -c .)" -ne 1 ]; then
            echo "::error::expected exactly one wp-env wordpress container: ${containers}"
            exit 1
          fi
          networks=$(docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' "${containers}" | grep .)
          if [ "$(printf '%s\n' "${networks}" | grep -c .)" -ne 1 ]; then
            echo "::error::expected exactly one wp-env network: ${networks}"
            exit 1
          fi
          docker network connect --alias mysql-under-test "${networks}" "${{ job.services.mysql.id }}"
      # tests/wp-tests-config.phpと同じ設定で接続し、wp-envのMariaDBではなくこのセルのMySQLであることを確かめる
      - name: Check the database version
        run: |
          # PHPのコードの$はシェルで展開しない
          # shellcheck disable=SC2016
          version=$(node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp \
            env WP_TESTS_DB_HOST=mysql-under-test \
            php -r 'require "tests/wp-tests-config.php"; $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME); echo $db->query("SELECT VERSION()")->fetch_row()[0];')
          echo "database version: ${version}"
          case "${version}" in
            "${{ matrix.mysql }}."*) ;;
            *)
              echo "::error::the tests would connect to ${version}, not MySQL ${{ matrix.mysql }}"
              exit 1
              ;;
          esac
      - name: Run integration tests
        run: |
          node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp \
            env WP_TESTS_DB_HOST=mysql-under-test \
            vendor-bin/wp-tests/vendor/bin/phpunit -c phpunit.integration.xml.dist
      - name: Show logs on failure
        if: failure()
        run: |
          node node_modules/@wordpress/env/bin/wp-env logs development --no-watch
          docker logs "${{ job.services.mysql.id }}"

  # 合算カバレッジ(unit と integration)の判定。PHP 8.3 / WordPress 最新版の 1 セルで行う。
  # unit は pcov、integration は wp-env の Xdebug(--xdebug=coverage)で計測し、clover を行単位で合算する。
  coverage:
    name: Coverage (PHP 8.3 / WP latest)
    runs-on: ubuntu-24.04
    needs: [unit]
    timeout-minutes: 20
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.3'
          coverage: pcov
      - uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '24'
          cache: npm
      - run: npm ci
      - uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0
      - name: Resolve WordPress version
        id: wp
        shell: bash
        run: |
          version=$(curl -fsS https://api.wordpress.org/core/version-check/1.7/ | jq -er '.offers[0].current')
          echo "version=${version}" >> "$GITHUB_OUTPUT"
      - name: Create wp-env override
        run: |
          printf '{"core": "https://wordpress.org/wordpress-%s.zip", "phpVersion": "8.3"}\n' \
            "${{ steps.wp.outputs.version }}" > .wp-env.override.json
      - name: Start wp-env with Xdebug
        run: |
          for attempt in 1 2 3; do
            if composer wp:start:coverage; then
              exit 0
            fi
            if [ "$attempt" -eq 3 ]; then
              exit 1
            fi
            sleep 10
          done
      # コミット済みの翻訳ファイルが、ソースと .po から WP-CLI で作り直したものと一致するかを確かめる
      - name: Check translation files are up to date
        run: |
          composer i18n:build
          git diff --exit-code -- 'packages/*/languages/*'
          untracked=$(git ls-files --others --exclude-standard -- 'packages/*/languages/*')
          if [ -n "$untracked" ]; then
            echo "::error::untracked translation files: ${untracked}"
            exit 1
          fi
      # コミット済みの探索結果ファイル(fixture のテーマ・プラグイン)が、wp tmswp discover で走査した結果と一致するかを確かめる
      - name: Check discovery files are up to date
        run: composer discover:check
      - name: Run unit tests with coverage
        run: composer test:coverage:unit -- --testdox
      - name: Run integration tests with coverage
        run: composer test:coverage:integration
      - name: Upload coverage reports
        uses: actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9 # v7.0.2
        with:
          name: coverage-clover
          path: |
            var/coverage/unit.xml
            var/coverage/integration.xml
          if-no-files-found: error
      - name: Check combined coverage
        run: composer test:coverage:check
      - name: Show wp-env logs on failure
        if: failure()
        run: node node_modules/@wordpress/env/bin/wp-env logs development --no-watch

  # examples/sample-siteのcomposer qa(lint・analyse・tms-acf check --strict・discover:check・test)と、E2E(tests/E2E/)。
  # discover:checkとE2Eはsample-siteのwp-env(examples/sample-site/.wp-env.json)で、composer wp:setup(テーマを有効にし、
  # dev/seed.phpで確認用のページ・製品・設定を作る)の後に行う。
  sample-site:
    name: Sample Site QA
    runs-on: ubuntu-24.04
    needs: [unit]
    timeout-minutes: 30
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.3'
          coverage: none
      - uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '24'
          cache: npm
      - run: npm ci
      - uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0
        with:
          working-directory: examples/sample-site
      # pathリポジトリのversionsのtmswp/coreが、Kernel::VERSIONと同じかを確かめる(リリースの道具はsample-siteを書き換えないため)
      - name: Check the tmswp/core version of the path repository
        working-directory: examples/sample-site
        run: |
          expected=$(php -r 'require "vendor/autoload.php"; echo TMSWP\Core\Kernel\Kernel::VERSION;')
          actual=$(jq -er '.repositories[] | select(.type == "path") | .options.versions["tmswp/core"]' composer.json)
          if [ "${actual}" != "${expected}" ]; then
            echo "::error file=examples/sample-site/composer.json::the path repository has tmswp/core ${actual}, but Kernel::VERSION is ${expected}"
            exit 1
          fi
      - name: Start wp-env
        working-directory: examples/sample-site
        run: |
          for attempt in 1 2 3; do
            if composer wp:start; then
              exit 0
            fi
            if [ "$attempt" -eq 3 ]; then
              exit 1
            fi
            sleep 10
          done
      - name: Set up the site
        working-directory: examples/sample-site
        run: composer wp:setup
      - name: Run QA
        working-directory: examples/sample-site
        run: composer qa
      # ブラウザはキャッシュせず、毎回@playwright/testの版に合うものを入れる
      - name: Install the browser for E2E
        run: node node_modules/@playwright/test/cli.js install --with-deps chromium
      - name: Run E2E
        run: composer test:e2e
      - name: Upload E2E traces on failure
        if: failure()
        uses: actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9 # v7.0.2
        with:
          name: e2e-traces
          path: var/e2e/test-results
          if-no-files-found: ignore
      - name: Show wp-env logs on failure
        if: failure()
        working-directory: examples/sample-site
        run: node ../../node_modules/@wordpress/env/bin/wp-env logs development --no-watch

  # スターターから、composer create-projectでプロジェクトを作って確かめる(tools/ci/create-starter-project.sh)。
  # 作るのは、スターターのテーマをそのまま写したもの(tmswp-starter-theme)と、bin/setup.phpで初期化したテーマ(client-theme)・
  # プラグイン(shop-tools)の3つ。tmswp/coreはmonorepoのpackages/coreをpathリポジトリで入れる。
  # 3つを1つのwp-envのサイトに置き、プロジェクトのスクリプトの`wp`(WP-CLI)は、tools/ci/bin/wpがwp-envのcliコンテナへ渡す。
  starters:
    name: Starter Projects
    runs-on: ubuntu-24.04
    needs: [unit]
    timeout-minutes: 20
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.3'
          coverage: none
      - uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0
        with:
          node-version: '24'
          cache: npm
          cache-dependency-path: |
            package-lock.json
            starters/theme/package-lock.json
      - run: npm ci
      - name: Create projects from the starters
        run: |
          site="${RUNNER_TEMP}/starter-site"
          tools/ci/create-starter-project.sh theme "${site}/tmswp-starter-theme"
          tools/ci/create-starter-project.sh theme "${site}/client-theme" client-theme 'Client Theme'
          tools/ci/create-starter-project.sh plugin "${site}/shop-tools" shop-tools 'Shop Tools'
          printf '%s\n' '{"core": null, "phpVersion": "8.3", "testsEnvironment": false, "themes": ["./tmswp-starter-theme", "./client-theme"], "plugins": ["./shop-tools"]}' > "${site}/.wp-env.json"
          echo "TMSWP_WP_ENV_DIR=${site}" >> "$GITHUB_ENV"
          echo "${GITHUB_WORKSPACE}/tools/ci/bin" >> "$GITHUB_PATH"
      - name: Start wp-env
        working-directory: ${{ env.TMSWP_WP_ENV_DIR }}
        run: |
          for attempt in 1 2 3; do
            if node "${GITHUB_WORKSPACE}/node_modules/@wordpress/env/bin/wp-env" start; then
              exit 0
            fi
            if [ "$attempt" -eq 3 ]; then
              exit 1
            fi
            sleep 10
          done
      # スターターのテーマのblocks/をビルドし(npm run buildは、wp-scriptsのビルドとwp tmswp discover)、
      # ビルドの出力と探索結果ファイルが、コミット済みのstarters/theme/build/blocks/・tmswp-discovery.phpと同じかを確かめる
      - name: Build the blocks of the starter theme
        working-directory: ${{ env.TMSWP_WP_ENV_DIR }}/tmswp-starter-theme
        run: |
          wp theme activate tmswp-starter-theme
          npm ci
          npm run build
          diff -r build/blocks "${GITHUB_WORKSPACE}/starters/theme/build/blocks"
          diff tmswp-discovery.php "${GITHUB_WORKSPACE}/starters/theme/tmswp-discovery.php"
      # 作ったテーマとプラグインは、README.mdの「セットアップの後」の手順で探索結果ファイルを作ってから、composer qaを実行する
      - name: Run QA of the theme created from the starter
        working-directory: ${{ env.TMSWP_WP_ENV_DIR }}/client-theme
        run: |
          wp theme activate client-theme
          npm ci
          npm run build
          composer qa
      - name: Run QA of the plugin created from the starter
        working-directory: ${{ env.TMSWP_WP_ENV_DIR }}/shop-tools
        run: |
          composer discover
          composer qa
      - name: Show wp-env logs on failure
        if: failure()
        working-directory: ${{ env.TMSWP_WP_ENV_DIR }}
        run: node "${GITHUB_WORKSPACE}/node_modules/@wordpress/env/bin/wp-env" logs development --no-watch

  # packages/*・starters/*の8つを、split.ymlの同期と同じ形の一時のリポジトリへ取り出して確かめる(tools/ci/split-check.sh)。
  # packages/*の6つは配布物(git archive)から開発用のファイルと.github/が外れることと、単独のcomposer install・composer test、
  # starters/*の2つは配布物から.github/・.DS_Store・node_modules/が外れ、tests/とphpunit.xml.distが入ることを確かめる。
  split-check:
    name: Split Packages Check
    runs-on: ubuntu-24.04
    timeout-minutes: 20
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.3'
          coverage: none
      - name: Check the split packages
        run: tools/ci/split-check.sh
```

設計上の要点:

- マトリクスは D-09 のとおり、ユニットが PHP 8.3 / 8.5、統合が **PHP 8.3 / 8.5 × WP 7.0 / 最新版** の 4 セル。ユニットは WP 非依存のため PHP 軸のみ。
- 統合の各セルは、検証する WP の版を先に確定し(WP 7.0 のセルは 7.0、最新版のセルは WordPress.org の版情報 API の最新版)、wp-env の `core` をその版の zip にする。テストライブラリは wp-env がその版と同じタグから取得する(§4.2)。API の取得に失敗したセルは、その時点で失敗する。
- `mysql`ジョブは、MySQL 8.4 LTSと9.7 LTSの2セルで統合テストを実行する(D-56。4.11)。PHPは8.3、WordPressは7.0に固定する(DBの違いを確かめるジョブなので、PHP・WordPressの軸は`integration`に任せる)。MySQLはservicesのイメージ`mysql:8.4`・`mysql:9.7`(LTSの系列のタグ。`latest`は使わない)で立て、ヘルスチェック(`mysqladmin ping`)が通ってから段を始める。wp-envの起動の後に、MySQLのコンテナをwp-envのネットワークに別名`mysql-under-test`で参加させ(`docker network connect`)、wp-envのcliから`WP_TESTS_DB_HOST=mysql-under-test`で統合テストの全体を実行する。テストの前に、tests/wp-tests-config.phpと同じ設定で接続して`SELECT VERSION()`を実行し、版がそのセルのMySQL(`8.4.`・`9.7.`で始まる)でなければ失敗させる(wp-envのMariaDBに接続して成功してしまうことを防ぐ)。
- wp-env は `npm ci` で package-lock.json どおりに導入し(`actions/setup-node` の npm キャッシュを使う)、composer スクリプト経由でローカルの wp-env だけを呼ぶ(§1.1 補足)。
- npm(11.19以降)は、依存のインストールスクリプトを、package.jsonの`allowScripts`に無いかぎり実行せず、警告を出す。ルートのpackage.jsonは、@wordpress/envの依存の`fs-ext-extra-prebuilt`(@wp-playground/cliが使う)のインストールスクリプトを`false`で拒否する(`npm install-scripts deny fs-ext-extra-prebuilt`で書く)。このパッケージは各OS・Node.jsの版のビルド済みのバイナリを同梱し、インストールスクリプトはそのバイナリの確認かnode-gypでのビルドだけを行う。wp-envのDockerの実行環境はこのパッケージを使わない。@wordpress/envを更新して、インストールスクリプトを持つ依存が増えた場合は、中身を確かめてから`allowScripts`に許可か拒否を書く。
- `lint` / `analyse` / `monorepo` / `rules-verify` は下限の PHP 8.3 で実行する(php-cs-fixer の推奨と依存解決の下限に合わせる。§1.1 補足)。PHP 8.5 での解析は PHPStan の `phpVersion`(8.3〜8.5。§2.1)が担う。
- 全ジョブのrunnerを`ubuntu-24.04`に固定する(`ubuntu-latest`は使わない)。`ubuntu-latest`の指す版は予告のうえで新しいUbuntuへ移るため、名前で指定すると、ワークフローを変えなくてもrunnerの版が変わりうる。runnerの版を上げる場合は、全ジョブが新しい版で通ることを確かめてから、qa.yml・split.ymlの`runs-on`と5.1・5.2の完全版を同時に改訂する。
- wp-envを起動するジョブは、`integration`・`mysql`・`coverage`・`starters`を`timeout-minutes: 20`、E2Eを含む`sample-site`を`timeout-minutes: 30`で打ち切る。wp-envの起動は、Dockerイメージの取得の一時的な失敗に備えて10秒の間隔で3回まで再試行し、3回目の失敗でジョブを失敗させる。失敗時はwp-envのログ(`logs development --no-watch`)を出力する(`mysql`はMySQLのコンテナのログも出力する)。
- `lint` ジョブが baseline ファイルの不存在を機械的に強制する。
- `unit`ジョブと`coverage`ジョブのユニットテストの段は、`--testdox`(`composer test:unit -- --testdox`・`composer test:coverage:unit -- --testdox`)で実行し、ログにテストの名前を出す。
- 合算カバレッジの判定は `coverage` ジョブ(PHP 8.3 / WordPress 最新版の 1 セル)だけで行う(4.8)。このジョブは pcov で unit を、`wp:start:coverage` で起動した wp-env の Xdebug で integration を計測し、2 つの clover を artifact `coverage-clover` に保存してから `test:coverage:check` で判定する。clover はジョブ内で生成して使うため、ジョブ間の受け渡しは無い。`unit` と `integration` の各セルはカバレッジを計測しない。
- `coverage` ジョブは wp-env の起動後に `composer i18n:build` を実行し、`packages/*/languages` に差分や未追跡のファイルが出ないことを確かめる(コミット済みの翻訳ファイルが、ソースと .po から作り直したものと一致すること。01 文書 §7.2)。WP-CLI は wp-env の cli コンテナのものを使うため、このジョブで行う。
- 同じく `coverage` ジョブは `composer discover:check` で、コミット済みの fixture の探索結果ファイルが `wp tmswp discover` で走査した結果とバイト単位で一致するかを照合する(02 文書 §3.6・§6)。ファイルが無い、または古い Kernel があれば、その名前空間とファイルパスを出して失敗する。探索結果ファイルは絶対パスを含まないため、手元(ホスト)と CI のどちらで生成しても内容は同じになる。
- `sample-site`ジョブは、examples/sample-siteの`composer qa`(lint・analyse・`tms-acf check --strict`・`discover:check`・test)を実行する。依存はsample-siteのcomposer.jsonのpathリポジトリ(`../../packages/*`)で入れ、pathリポジトリの`versions`の`tmswp/core`が`Kernel::VERSION`と同じかを先に確かめる(リリースの道具はsample-siteを書き換えないため、版を上げたときの直し忘れをここで止める)。wp-envはsample-siteの`.wp-env.json`で`composer wp:start`で起動し、`composer wp:setup`(テーマ`sample-site`を有効にし、`dev/seed.php`で確認用のページ・製品・設定を作る)の後に`composer qa`を実行する。続けて、ブラウザ(Chromium)を`node node_modules/@playwright/test/cli.js install --with-deps chromium`で入れて(キャッシュしない)、E2E(`composer test:e2e`。4.10)を実行する。失敗したときだけ、E2Eのtrace(`var/e2e/test-results/`)を成果物`e2e-traces`に保存する。
- `starters`ジョブは、スターターから`composer create-project`でプロジェクトを作り(`tools/ci/create-starter-project.sh`)、スターターとプロジェクトの手順が通ることを確かめる。スターターと`tmswp/core`は、monorepoの`starters/*`・`packages/core`をpathリポジトリ(シンボリックリンクではなく写し)で入れ、版は`Kernel::VERSION`にする。それ以外の依存はPackagistから入れる。作るのは次の3つで、1つのwp-envのサイト(`$RUNNER_TEMP/starter-site/.wp-env.json`)にテーマ2つとプラグイン1つとして置く。
  - `tmswp-starter-theme`: テーマのスターターをそのまま写したもの(`--no-scripts`)。テーマを有効にして`npm ci`と`npm run build`(wp-scriptsでblocks/をビルドし、`wp tmswp discover`で探索結果ファイルを作る)を実行し、ビルドの出力と探索結果ファイルが、コミット済みの`starters/theme/build/blocks/`・`starters/theme/tmswp-discovery.php`と同じかを`diff`で確かめる(§1.4・§1.11)。
  - `client-theme`・`shop-tools`: `bin/setup.php`(post-create-project-cmd)を、スラッグと表示名を環境変数で渡して非対話で実行したテーマとプラグイン。作った直後に、`{{`が残っていない(`grep -rn "{{" <プロジェクト> --exclude-dir=vendor --exclude-dir=node_modules`が一致なし)こと、`bin/setup.php`が消えていること、プラグインの本体ファイルが`shop-tools.php`になり`plugin.php`が無いことを確かめる。続けて各README.mdの「セットアップの後」の手順で探索結果ファイルを作り(テーマは`npm ci`と`npm run build`、プラグインは`composer discover`)、`composer qa`を実行する。
  - プロジェクトのスクリプトは、WP-CLIがサイトに届く場所で`wp`を実行する前提である。このジョブは`tools/ci/bin`をPATHの先頭に足し、`tools/ci/bin/wp`が引数をそのままwp-envのcliコンテナのWP-CLIへ渡す(wp-envの設定のあるディレクトリは環境変数`TMSWP_WP_ENV_DIR`で渡す)。lint・analyse・testはホストのPHP 8.3で実行する。
- `split-check`ジョブは、packages/*・starters/*の8つを、split.ymlの同期と同じ形の一時のgitのリポジトリへ取り出して確かめる(`tools/ci/split-check.sh`。`docs/RELEASING.md`の「split相当の確認」と同じ手順)。HEADの各パッケージのディレクトリの中身だけを持つコミットを作り、split.ymlと同じく`.github/split/close-pull-request.yml`を`.github/workflows/close-pull-request.yml`に置いてコミットする。packages/*の6つは、`git archive HEAD`に`tests/`・`phpunit.xml.dist`・`.gitattributes`・`.DS_Store`・`.github/`が入らないことを確かめ、tmswp/core・tmswp/testingを、同じく取り出したcore・testingを指すpathリポジトリ(写し。版は`Kernel::VERSION`)で入れて、`composer install`と`composer test`を実行する。starters/*の2つは、install・testを行わず、`git archive HEAD`に`.github/`・`.DS_Store`・`node_modules/`が入らず、作ったプロジェクトが使う`tests/`と`phpunit.xml.dist`が入ることを確かめる(6.2・§7)。取り出し・照合・install・testのどれかが失敗すれば、ジョブが失敗する。Dockerを使わないため、`unit`を待たずに起動する。
- `integration`・`mysql`・`coverage`・`sample-site`・`starters`は`unit`の成功の後に起動し、失敗時にDockerの起動のコストを省く。
- 外部アクションはコミットの SHA で固定する(5.3)。

### 5.2 .github/workflows/split.yml(完全版)

read-only split配布(DR-11)。各パッケージを個別リポジトリ(`tmstack-io/tmswp-core`等の8件。01 §4.4)へpushし、Packagistはそのsplitリポジトリを`tmswp/*`の名前で購読する。split先リポジトリはアーカイブせず(splitが書き込めなくなるため)、IssueとWikiを無効にし、説明文でmonorepoへ案内する。誤って送られたプルリクエストは、同期先で動くワークフローが自動で閉じて案内する(01 §4.4)。

```yaml
name: Split Packages

on:
  push:
    branches: [main]
    tags: ['v*']

env:
  GITHUB_ORG: tmstack-io

jobs:
  split:
    name: Split ${{ matrix.package.local }}
    runs-on: ubuntu-24.04
    # 同じ同期先への同期(mainとタグ)を並行させない。先の実行は取り消さず、後の実行は待たせる(queue: maxで待ちを1つに限らない)
    concurrency:
      group: split-${{ matrix.package.repo }}
      cancel-in-progress: false
      queue: max
    strategy:
      fail-fast: false
      matrix:
        package:
          - { local: 'packages/core', repo: 'tmswp-core' }
          - { local: 'packages/thumbnail', repo: 'tmswp-thumbnail' }
          - { local: 'packages/settings-page', repo: 'tmswp-settings-page' }
          - { local: 'packages/acf-bridge', repo: 'tmswp-acf-bridge' }
          - { local: 'packages/ja-toolkit', repo: 'tmswp-ja-toolkit' }
          - { local: 'packages/testing', repo: 'tmswp-testing' }
          - { local: 'starters/theme', repo: 'tmswp-starter-theme' }
          - { local: 'starters/plugin', repo: 'tmswp-starter-plugin' }
    steps:
      # トークンが無ければ同期できないため、成功で終わらせずに失敗にする
      - name: Require SPLIT_ACCESS_TOKEN
        env:
          SPLIT_ACCESS_TOKEN: ${{ secrets.SPLIT_ACCESS_TOKEN }}
        run: |
          if [ -z "${SPLIT_ACCESS_TOKEN}" ]; then
            echo "::error::SPLIT_ACCESS_TOKEN is not set; cannot split ${{ matrix.package.local }} to ${GITHUB_ORG}/${{ matrix.package.repo }}"
            exit 1
          fi
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - name: Validate split source
        run: test -d "${{ matrix.package.local }}"
      # 同期先でプルリクエストを閉じるワークフローの正本(.github/split/close-pull-request.yml)を、同期するディレクトリへ写す
      - name: Add the workflow that closes pull requests
        run: |
          mkdir -p "${{ matrix.package.local }}/.github/workflows"
          cp .github/split/close-pull-request.yml "${{ matrix.package.local }}/.github/workflows/close-pull-request.yml"
      - name: Split branch
        if: "!startsWith(github.ref, 'refs/tags/')"
        uses: danharrin/monorepo-split-github-action@14e42e2437f674b8987c1f50ca3689116aea1893 # v2.4.5
        # このアクションに access_token 入力は無く、push 用トークンは環境変数 GITHUB_TOKEN から読む
        env:
          GITHUB_TOKEN: ${{ secrets.SPLIT_ACCESS_TOKEN }}
        with:
          package_directory: ${{ matrix.package.local }}
          repository_organization: ${{ env.GITHUB_ORG }}
          repository_name: ${{ matrix.package.repo }}
          user_name: tmstack-io
          user_email: tommy@tmstack.io
      - name: Split tag
        if: startsWith(github.ref, 'refs/tags/')
        uses: danharrin/monorepo-split-github-action@14e42e2437f674b8987c1f50ca3689116aea1893 # v2.4.5
        env:
          GITHUB_TOKEN: ${{ secrets.SPLIT_ACCESS_TOKEN }}
        with:
          tag: ${{ github.ref_name }}
          package_directory: ${{ matrix.package.local }}
          repository_organization: ${{ env.GITHUB_ORG }}
          repository_name: ${{ matrix.package.repo }}
          user_name: tmstack-io
          user_email: tommy@tmstack.io
```

- `SPLIT_ACCESS_TOKEN`はsplit先へのpushに使うトークンで、fine-grained PAT。対象は送り先の8リポジトリ(`tmstack-io/tmswp-*`)だけ。権限はContents: Read and writeとWorkflows: Read and writeだけ(同期先へ`.github/workflows/`のプルリクエスト自動クローズ用ワークフローも送るため)。monorepoのリポジトリSecretsに登録する。splitアクションはpush用のトークンを入力ではなく環境変数`GITHUB_TOKEN`から読むため、ステップの`env`で渡す。未登録(空)の場合は、最初のステップがエラーを出してジョブを失敗させ、同期しない(成功で終わる空実行の段を置かない)。
- 同期の前に、正本の`.github/split/close-pull-request.yml`を、同期するディレクトリの`.github/workflows/close-pull-request.yml`へ写す。splitアクションは、同期するディレクトリの中身(`.github/`を含む)をそのまま写して同期先へpushするため、同期先にはこのワークフローが入る。monorepoのpackages/*・starters/*には置かない。配布物には入れない(各パッケージの`.gitattributes`の`/.github export-ignore`。6.2)。
- ジョブの`concurrency`は、matrixの同期先(`split-<リポジトリ名>`)ごとのグループで、同じ同期先への同期(mainとタグ)を並行させない。`cancel-in-progress: false`で先の実行を取り消さず、`queue: max`で後の実行を待たせる(既定の`queue: single`は、待っている実行を新しい実行で取り消すため、タグの同期が取り消されうる)。待っている実行の順は保証されないため、順が入れ替わると、同期先のmainが一時的に前の内容になりうる(タグの同期もmainをpushする)。次の同期で直る。
- `user_name` / `user_email` は split 先に作られるコミットのコミッターで、`tmstack-io` / `tommy@tmstack.io` とする。
- split アクションは `danharrin/monorepo-split-github-action`(メンテナンス終了した symplify 版の維持後継)の v2.4.5 を正準とし、コミットの SHA で固定する(5.3)。タグは `${{ github.ref_name }}` で渡すこと。`with:` の値内では bash のパラメータ展開(`${GITHUB_REF#refs/tags/}` 等)は評価されず文字列リテラルのままタグ名として渡るため、使用してはならない。同等機能の action への差し替えは PATCH 扱いで可(01 §4.4)。

### 5.3 外部アクションの固定

qa.ymlとsplit.ymlの外部アクションは、すべてコミットのSHA(40桁)で固定し(`.github/split/close-pull-request.yml`は外部アクションを使わず、runnerに入っている`gh`でプルリクエストを閉じる)、行末にそのSHAのタグ名をコメントで書く(`uses: actions/checkout@<SHA> # v7.0.1`)。タグやブランチは付け替えられるため、名前で指定すると、同じワークフローでも実行されるコードが変わりうる。版は、採用しているメジャー版の範囲で最新のタグを使う。

| アクション | タグ | コミットの SHA | 実行環境 |
|---|---|---|---|
| `actions/checkout` | v7.0.1 | `3d3c42e5aac5ba805825da76410c181273ba90b1` | Node.js 24 |
| `actions/setup-node` | v7.1.0 | `949feb2413d6458794dcd2491c4babbbce0c15c1` | Node.js 24 |
| `actions/upload-artifact` | v7.0.2 | `cf430e030ddbb5b0abf93d22962f4752f3646cd9` | Node.js 24 |
| `shivammathur/setup-php` | 2.40.0 | `eb7c497e18156a6bbabfef1d3a82760b9eda3962` | Node.js 24 |
| `ramsey/composer-install` | 4.0.0 | `65e4f84970763564f46a70b8a54b90d033b3bdda` | composite(中で`actions/cache` v5.0.3(`cdf6c1fa76f9f475f3d7449005a359c84ca0f306`。Node.js 24)を使う) |
| `danharrin/monorepo-split-github-action` | v2.4.5 | `14e42e2437f674b8987c1f50ca3689116aea1893` | Docker |

JavaScriptのアクションは、`action.yml`の`runs.using`が`node24`の版を使う。`node20`の版は、GitHubのrunnerがNode.js 24で強制的に実行し、Node.js 20の非推奨の警告を出す。compositeのアクション(`ramsey/composer-install`)は、中で使うアクションの版も確かめる。

更新手順:

1. 採用しているメジャー版の範囲で最新のタグを確かめる(例: `gh api repos/actions/checkout/git/matching-refs/tags/v7.`)。タグ名に `v` が付かないアクション(`shivammathur/setup-php`、`ramsey/composer-install`)は、`v` を除いた接頭辞で調べる。
2. `gh api repos/<owner>/<repo>/git/ref/tags/<tag>` で SHA を取得する。`object.type` が `tag`(注釈付きタグ)の場合、その SHA はタグオブジェクトのものである。`gh api repos/<owner>/<repo>/git/tags/<sha>` の `object.sha` でコミットの SHA にたどる。
3. qa.yml と split.yml で同じアクションを使う `uses:` をすべて同じ SHA とタグ名に書き換え、5.1・5.2 の完全版と上の表を同じ内容に改訂する。
4. `grep -nE "uses:" .github/workflows/*.yml | grep -vE "uses: [^@]+@[0-9a-f]{40} # "` が 0 件であることを確かめる。

メジャー版を上げる場合は、入力・既定値・実行環境(Node.js の版等)の変更点を確かめてから行う。`runs.using`は`gh api "repos/<owner>/<repo>/contents/action.yml?ref=<tag>" --jq .content | base64 -d`で確かめる。

---

## 6. monorepo-builder 設定

### 6.1 monorepo-builder.php(完全版)

```php
<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;
use TMSWP\Tools\Release\CheckReleaseReadinessReleaseWorker;
use TMSWP\Tools\Release\CreateAnnotatedTagReleaseWorker;

return static function (MBConfig $mbConfig): void {
    $mbConfig->packageDirectories([
        __DIR__ . '/packages',
        __DIR__ . '/starters',
    ]);

    $mbConfig->defaultBranch('main');
    $mbConfig->packageAliasFormat('<major>.<minor>.x-dev');

    $mbConfig->dataToAppend([
        'require-dev' => [],
        'config' => [
            'sort-packages' => true,
        ],
    ]);

    // `monorepo-builder release vX.Y.Z`が順に実行するワーカーは、tools/release/の2つだけにする(D-54・docs/RELEASING.md)。
    // monorepo-builderが既定で登録するワーカーを外し、版の書き換え・mainへのcommitとpush・次の版への引き上げを行わない
    MBConfig::disableDefaultWorkers();

    $services = $mbConfig->services();
    // mainであること・origin/mainと同じであること・作業ツリーが空であること・タグが無いこと・版の一致・CHANGELOGの見出しを検査する
    $services->set(CheckReleaseReadinessReleaseWorker::class)->arg('$repositoryDirectory', __DIR__);
    // 注釈付きのタグvX.Y.Zを作り、git push origin refs/tags/vX.Y.Zでそのタグだけを送る(split.ymlがタグを同期先へ複製する)
    $services->set(CreateAnnotatedTagReleaseWorker::class)->arg('$repositoryDirectory', __DIR__);
};
```

### 6.2 パッケージ間依存の規律

- 各拡張パッケージとスターターのcomposer.jsonは`"tmswp/core": "^X.Y"`、packages/*のrequire-devは`"tmswp/testing": "^X.Y"`を宣言する。版の最初のタスクで、`Kernel::VERSION`・branch-alias・examples/sample-siteの版と同じコミットで、その版の`^X.Y`(PATCHが0より大きければ`^X.Y.Z`)に手で上げる(D-54)。リリースの前の検査(6.1の`CheckReleaseReadinessReleaseWorker`)が、タグの版との一致を確かめる。リリースの後に次の版へ自動で上げない。`^X.Y`は`Kernel::VERSION`と、packages/*の`branch-alias`(`dev-main` → `X.Y.x-dev`)を満たす。
- ルートcomposer.jsonの`replace`にpackages/*の全パッケージを列挙する(1.1参照)。これによりmonorepo内開発ではvendorを介さずローカル`packages/*`のコードが使われる。
- `composer monorepo:validate` が「各パッケージの require と ルートの require の整合」「相互依存バージョンの不一致」を検査する。CI 必須(qa.yml の `monorepo` ジョブ)。
- 新パッケージ追加手順: `packages/<name>/`に`composer.json`(license・require・require-devの`tmswp/testing`・`scripts.test`・`extra.branch-alias`)、`LICENSE`(ルートと同じGPL-2.0-or-later)、`README.md`、`phpunit.xml.dist`、`.gitattributes`を置く → ルートcomposer.jsonの`replace`・`autoload`・`autoload-dev`に手で足す → `phpstan.neon.dist`のpaths、`phpunit.*.xml.dist`のtestsuiteとカバレッジの計測の対象(`phpunit.unit.xml.dist`の`<source><include>`・`phpunit.integration.xml.dist`の`<coverage><include>`の`packages/<name>/src`)、`split.yml`のmatrixに追記する。計測の対象に足すのと同時に、ルートcomposer.jsonの`test:coverage:check`に`--package=<name>:80`を足す(足さないと`bin/coverage-check.php`が`No package minimum for …`でexit 2になる。4.8) → 01 §2・§3.1・§4.4、本書5.2・受け入れ基準、`RELEASING.md`のパッケージの数を改める。パッケージをsplit相当で取り出して`composer install && composer test`が通ること(`RELEASING.md`)と、`composer monorepo:validate`で確かめる。
- 各パッケージの`.gitattributes`は、`export-ignore`で`/tests`・`/phpunit.xml.dist`・`/.gitattributes`・`.DS_Store`・`/.github`を配布物から外す(`/.github`は、split.ymlが同期の前に置く、同期先でプルリクエストを閉じるワークフロー。5.2)。Composerのpathリポジトリ(`symlink: false`)の写しもこれに従う(composer.jsonの`archive.exclude`はpathリポジトリの写しに効かない)。スターターの`.gitattributes`は`/node_modules`・`.DS_Store`・`/.github`だけを外し、作ったプロジェクトが使う`tests/`・`build/blocks/`・探索結果ファイルは外さない。

### 6.3 リリース手順(正準)

手順の正本は`docs/RELEASING.md`である。mainへの反映は、ユーザーの確認を経たPR(`rewrite/v0`→`main`)のマージで行う。`vendor/bin/monorepo-builder release vX.Y.Z`は、mainをcheckoutした状態で、6.1の2つのワーカーを順に実行する。1つ目(`CheckReleaseReadinessReleaseWorker`)が、mainであること・`origin/main`と同じであること・作業ツリーが空であること・タグが手元にもoriginにも無いこと・版の一致・CHANGELOGの`## vX.Y.Z — 題名`の見出しを検査し、1つでも満たさなければ例外で止める。2つ目(`CreateAnnotatedTagReleaseWorker`)が、注釈付きのタグを作り、`git push origin refs/tags/vX.Y.Z`でそのタグだけを送る。mainへのcommit・pushと、版の書き換えは行わない。タグ付けは毎回ユーザーに確認してから実行する。

```bash
vendor/bin/monorepo-builder release vX.Y.Z --dry-run   # 実行するワーカーの説明を表示するだけ(検査も含めて何も実行しない)
vendor/bin/monorepo-builder release vX.Y.Z             # 検査に通れば、注釈付きのタグを作ってそのタグだけをpushする
# → split.ymlがタグを各read-onlyリポジトリへ複製 → Packagistが自動更新
```

バージョンは全パッケージ同一(lockstep)。SemVer に従い、0.x 期はマイナーで破壊的変更を許容する。

---

## 7. スターター/アプリ側 QA の最小要件

スターター(starter-theme / starter-plugin)同梱の QA 設定の正準仕様は DR-13 担当文書に委ねる。本書は FW として保証すべき最小要件のみ定める:

1. スターターの composer.json は `require-dev` に `phpstan/phpstan`、`szepeviktor/phpstan-wordpress`、`phpstan/extension-installer`、`friendsofphp/php-cs-fixer` を含み、`lint` / `fix` / `analyse` / `qa` スクリプトを monorepo ルートと**同名・同義**で定義する(AI が同じコマンド体系で作業できること)。
2. `phpstan/extension-installer` により `tmswp/core` 同梱の PHPStan 拡張(2.2 項 4)が**設定なしで自動有効化**されること。スターターの phpstan.neon は `level: max` + `paths: [app]` を既定とする。
3. アプリの `app/Acf/*Fields.php`(`@generated`、DR-06)は解析対象に**含める**(生成コードも型検査を通す。CI でのドリフト検知は acf-bridge 文書の管轄)。
4. テーマテンプレート(`*.php`のうちWPテンプレート階層ファイルと`template-parts/`)はphp-cs-fixer / PHPStan(型検査)の対象に含めない(要件3の絶対原則)。テンプレートの前処理クラス(`app/Page/`)は`app/`の一部として解析対象に含める(§3A)。スターターのphp-cs-fixerの設定は、monorepoと同じ規則で、テンプレートを除いたPHPを対象にする(§3.2)。
5. スターターの`composer qa`は`lint` → `analyse` → `discover:check` → `test`の順とする。`discover:check`(`wp tmswp discover --check`)は探索結果ファイルの照合で、WP-CLIがそのテーマ・プラグインを有効にしたサイトに届く場所で実行する。
6. スターターとサンプルサイト(`examples/sample-site`)の`phpunit.xml.dist`は、packages/*の`phpunit.xml.dist`(§4.3)と同じく`failOnRisky`・`failOnWarning`・`failOnDeprecation`・`failOnNotice`・`beStrictAboutOutputDuringTests`・`displayDetailsOnTestsThatTriggerDeprecations`・`displayDetailsOnTestsThatTriggerNotices`・`displayDetailsOnTestsThatTriggerWarnings`を持つ。スターターはさらに`failOnEmptyTestSuite`を持ち、テストが1件も無い`composer test`を失敗にする。
7. スターターは、`app/Hook/`の例のフック(`ThemeSetupHook`・`PluginSetupHook`)の例のユニットテストを`tests/Unit/Hook/`に1つずつ持つ。例のテストはtmswp/testingを使わず、Brain MonkeyとPHPUnitの`TestCase`だけで書く(スターターはtmswp/testingをrequire-devに入れない)。setup.phpは`tests/`の名前空間も書き換えるため、スターターから作ったプロジェクトの`composer test`は、そのまま1件以上のテストを実行する(CIのStarter Projectsが確かめる)。

---

## 8. 旧 iwf2b テスト資産の仕様移植リスト

旧テストは PHPUnit 5.7〜7 世代・全件 `WP_UnitTestCase` 継承であり**コードの流用は不可**。以下は「実行可能な仕様書」としての移植対応表である(検証ケース・データプロバイダの値リストを新テストへ書き写す)。新クラス名は各機能文書の正準名に従う(Rule 名は 05 文書、エンティティは 02/03 系文書)。

### 8.1 移植対応表

| # | 旧テスト(iwf2b/tests/) | 移植先(新 FW) | 層 | 移植する仕様 / 廃棄理由 |
|---|---|---|---|---|
| 1 | `bootstrap.php` | `tests/bootstrap-integration.php` | — | `tests_add_filter('muplugins_loaded')` でのロード方式のみ踏襲。install-wp-tests.sh 経路は廃止(wp-env の WP_TESTS_DIR へ) |
| 2 | `test-sample.php` | — | — | 廃棄(scaffold 残骸) |
| 3 | `TestCase/ArrTest.php` | `packages/core/tests/Unit/Support/ArrTest.php` | Unit | ドット記法 get/set のデータプロバイダ**全件**移植(ネスト配列)。キー配列を渡す形(配列パス)は廃止したため移植しない(07 §2.3。キーは`string\|int`だけで、一括取得は`only()`で代替) |
| 4 | `TestCase/TextTest.php` | `packages/core/tests/Unit/Support/StrTest.php` | Unit | stringify(__toString / Iterator 対応)・ケース変換のうち、新 `Support\Str` API に存在するものを移植。新 API に対応がない検証は廃棄 |
| 5 | `TestCase/UtilTest.php` | `packages/core/tests/Unit/Form/ValidatorTest.php` | Unit | `parse_phpdoc_tags` 系は廃棄(Attribute 化で機構ごと消滅)。`is_empty` の境界値(null / '' / [] / false / 空白 / 0 / '0' / Countable)は Form 層の空値判定テスト(`Validator::isEmpty()`)へ仕様移植。新しい空値は null / '' / [] だけで、旧 FW が空とした false・空白だけの文字列・要素数 0 の Countable は空ではない(05 §空値スルーセマンティクス)。`filter` / `namespace_split` は対応 API なしのため廃棄 |
| 6 | `TestCase/OptionTest.php` | `packages/core/tests/Unit/Option/OptionRepositoryTest.php` + `packages/core/tests/Integration/OptionIntegrationTest.php` | 両方 | 定数ベースの option 読み書き仕様を移植。ユニット層はフェイク `OptionStorageInterface` 実装で、統合層は実 `wp_options` で検証 |
| 7 | `TestCase/Field/FieldTest.php` | `packages/core/tests/Unit/Form/FormRuntimeTest.php` | Unit | エラー設定 / クリアの仕様を移植。項目の定義(`Field`)はエラーを持たないため、`handle()` の検証の結果が `FieldView` のエラーに出ることと、次のリクエストの `Form` に前のエラーが残らないことで確かめる。1 項目に記録するエラーは最初の 1 件だけ(05 §Validator)のため、旧 FW の複数のエラーの追加は移植しない |
| 8 | `TestCase/Field/FieldSetTest.php` | — | — | 廃棄。FieldSet のシングルトン的インスタンス管理は DR-09(AbstractSingleton 廃止)で機構ごと消滅。フィールド集合の保持は `FormDefinition` のテストとして新規作成 |
| 9 | `TestCase/Field/Rule/RuleTraitTest.php` | `packages/core/tests/Unit/Form/ValidatorTest.php`(空値スルー)+ `packages/core/tests/Unit/Form/Rule/ConstructorArgumentsTest.php`(`TypeError`) | Unit | **空値スルー**(空値は Required 系以外を素通り)の仕様を、Required 系以外の全規則で移植。必須パラメータ欠落 / 型不一致の実行時検査は PHP 8.3 のコンストラクタ型宣言 + named arguments による静的保証に置換(`TypeError` の検証のみ残す)。config 配列の注入・既定メッセージの決め方・`PHPUnit\Framework\Error\Notice` 依存の検証は機構ごと廃棄 |
| 10 | `TestCase/Field/Rule/ChoiceRuleTest.php` | `packages/core/tests/Unit/Form/Rule/ChoiceTest.php` | Unit | 選択肢一致・multiple の全ケース移植。strict の指定は廃止し、常に文字列にして厳密に比べる(05 §同梱ルール一覧)。空白区切りの文字列による選択肢の指定は廃棄 |
| 11 | `TestCase/Field/Rule/EmailRuleTest.php` | `packages/core/tests/Unit/Form/Rule/EmailTest.php` | Unit | 正常 / 異常メールアドレスのデータプロバイダ**全件**移植。期待値は `filter_var(FILTER_VALIDATE_EMAIL)` の仕様(05)に従い、旧 FW と結果が違う値(`email@[123.123.123.123]` は合格)はテストのコメントに旧 FW の結果を残す |
| 12 | `TestCase/Field/Rule/ExactLengthRuleTest.php` | `packages/core/tests/Unit/Form/Rule/MinLengthTest.php`・`packages/core/tests/Unit/Form/Rule/MaxLengthTest.php`(それぞれの境界)+ `packages/core/tests/Unit/Form/ValidatorTest.php`(同じ長さの組) | Unit | ExactLength は同梱しない(05 §同梱ルール一覧)ため、ルールとしては廃棄。文字数の境界値(ASCII とマルチバイトの 9・10・11 文字)は、同じ長さの `MinLength` と `MaxLength` の組のテストへ移植 |
| 13 | `TestCase/Field/Rule/RequiredIfRuleTest.php` | `packages/core/tests/Unit/Form/Rule/RequiredIfTest.php` | Unit | 他フィールド参照の条件付き必須の真理値表(`RequiredIf::filled()` / `RequiredIf::equals()`)を移植。FieldSet 経由参照は `ValidationContext` 経由に置換。strict の指定は廃止し、常に文字列として厳密に比べる。未登録の項目の参照は `UnknownFieldException` |
| 14 | `TestCase/Field/Rule/RequiredUnlessRuleTest.php` | — | — | 廃棄。RequiredUnless は同梱しない(05 §同梱ルール一覧) |
| 15 | `TestCase/Field/Rule/RetypeRuleTest.php` | `packages/core/tests/Unit/Form/Rule/RetypeTest.php` | Unit | 再入力一致を `ValidationContext` 経由で移植。strict の指定は廃止し、常に文字列として厳密に比べる。未登録の項目の参照は `UnknownFieldException` |
| 16 | `TestCase/Field/Rule/UrlRuleTest.php` | `packages/core/tests/Unit/Form/Rule/UrlTest.php` | Unit | 絶対 / 相対 / カスタムプロトコル URL のデータプロバイダ**全件**移植。期待値は国際化ドメイン名のホスト名を `idn_to_ascii()`(UTS #46)で変換したうえでの `filter_var(FILTER_VALIDATE_URL)` とスキームの仕様(05)に従い、旧 FW と結果が違う値(ホスト名の `_`・スキームの無い相対 URL は不合格、UTS #46 で変換できる記号のホスト名・クエリの未エスケープの文字は合格)はテストのコメントに旧 FW の結果を残す |
| 17 | `TestCase/Form/FormTest.php` | `packages/core/tests/Unit/Form/FormHtmlTest.php` | Unit | checkbox / radio の value 属性・checked 判定の境界 10 ケース(空 value・同値設定等)を `FormHtml` 純関数と `FieldView` のスナップショット検証として移植。value は常に出力する(旧 FW は空の value を省いた) |
| 18 | `TestCase/Form/FormChoiceTest.php` | `packages/core/tests/Unit/Form/FormHtmlTest.php` | Unit | 複数選択肢レンダリングの出力仕様を移植(選択肢は「値 => ラベル」。空値の hidden は出さない)。カスタムレンダラ合成(3 層コールバック)の検証は機構廃止(DR-07)により、`FieldView::options()` の `OptionView` を 1 つずつ描画する出力カスタマイズ仕様(05 文書)の検証に置換 |
| 19 | `TestCase/Log/LogTest.php` | `packages/core/tests/Unit/Log/LoggerRegistryTest.php`(チャンネル・スコープ・ロガー差し替え)・`packages/core/tests/Unit/Log/LogChannelTest.php`(チャンネルの設定)+ `packages/core/tests/Unit/Log/SimpleLoggerTest.php`(vfsStreamへの書込) | Unit | チャンネル・スコープ・ロガー差し替え・vfsStream への書込検証を移植。シングルトン検証(get_instance)は `LoggerRegistry` の登録 / 解決検証に置換 |
| 20 | `TestCase/Log/Logger/SimpleLoggerTest.php` | `packages/core/tests/Unit/Log/SimpleLoggerTest.php` | Unit | 日付フォーマット・ファイル名・ディレクトリ分割・全レベル・コンテキスト展開を vfsStream で移植(PSR-3 **v3** シグネチャで書き直し) |
| 21 | `TestCase/Post/AbstractPostTest.php` | `packages/core/tests/Integration/EntityRegistrationIntegrationTest.php`(登録) + `EntityFacadeIntegrationTest.php`(取得・URL) + `EntityQueryIntegrationTest.php`(クエリ) + `MetaIntegrationTest.php`(メタ) | Integration | CPT 自動登録・`get`/`get_id` 相当(`PostQuery`)・META 定数によるメタ読み書き(`MetaBag` 経由)・permalink / アーカイブリンクを移植。「デフォルト meta 挿入」は `MetaSchema(default:)` の仕様検証へ置換 |
| 21b | 同上(サムネイル解決) | `packages/core/tests/Integration/EntityFacadeIntegrationTest.php` | Integration | アイキャッチなし / アイキャッチあり / `tmswp/post/thumbnail_fallback` フィルタの 3 経路の解決を移植(GD 画像生成 + WP ファクトリの作法を踏襲。アイキャッチありは GD で作った JPEG を `factory()->attachment->create_upload_object()` で添付し、URL・幅・高さ・alt と中間サイズを確かめる)。ダミー画像は呼び出し側の `?? $dummyUrl` で表すため対象外。本文先頭 img の抽出はコアにも thumbnail 拡張にも持たない(03 文書 §5) |
| 22 | `TestCase/Tax/AbstractTaxTest.php` | `packages/core/tests/Integration/EntityRegistrationIntegrationTest.php`(登録・object_type の解決) + `EntityFacadeIntegrationTest.php`(term link) + `MetaIntegrationTest.php`(term meta) | Integration | タクソノミー登録(object_type の文字列 / 配列 / PostType クラス名解決)・term meta 定数操作・term link を移植 |
| 23 | `TestCase/User/AbstractUserTest.php` | `packages/core/tests/Integration/EntityRegistrationIntegrationTest.php`(ロールの登録) + `EntityRuntimeIntegrationTest.php`(冪等性・差分の通知) + `EntityFacadeIntegrationTest.php`(author link) + `MetaIntegrationTest.php`(user meta) | Integration | ロール登録・user meta 定数操作・author link を移植 |
| 24 | `bin/install-wp-tests.sh` | — | — | 廃棄(wp-env の WP_TESTS_DIR に置換) |
| 25 | `.travis.yml` / `.phpcs.xml.dist` / `Gruntfile.js` / `package.json` | — | — | 廃棄(qa.yml / php-cs-fixer / wp-env(@wordpress/env)に置換。i18n pot 生成は `wp i18n make-pot` を必要時に使用) |

### 8.2 旧 FW で未テストだった領域(新規テスト必須リスト)

旧 FW にテストが存在しない以下は、対応する機能文書の仕様から **TDD で新規作成**する(実装より先にテストを書くこと)。カバレッジ目標(4.8)の対象。

| 対象 | 層 | 最低限の検証項目 | テスト |
|---|---|---|---|
| `Model` / `QueryBuilder` / `TableDefinition` / `Migrator` | Unit + Integration | 全クエリが `wpdb->prepare` を通ること(生 SQL 流出の禁止)、dbDelta スキーマ生成、`wp tmswp migrate` によるハッシュ比較のマイグレーションと管理画面の通知 | `packages/core/tests/Unit/Model/`(`ModelLayerTest.php`・`QueryBuilderAcceptanceTest.php`・`TableDefinitionAcceptanceTest.php`・`TableDefinitionColumnAttributeTest.php`・`TableDefinitionTypeAliasTest.php`・`MigratorTest.php`・`MigrateCommandTest.php`)+ `packages/core/tests/Integration/ModelIntegrationTest.php` |
| `Form`(ライフサイクル) | Unit | バインド → 検証 → `FormStep` 遷移、CSRF 自動検証(Brain Monkey で wp_nonce モック) | `packages/core/tests/Unit/Form/FormRuntimeTest.php` |
| `ConfirmToken` | Unit | HMAC 署名生成 / 検証、改ざん検知、期限切れ | `packages/core/tests/Unit/Form/ConfirmTokenTest.php` |
| `UploadedFile` / `TempFileStore` | Unit | トークン発行 / 取出し、**ユーザー入力からのパス再構築が不可能であること**(パストラバーサル試行の拒否) | `packages/core/tests/Unit/Form/TempFileStoreTest.php` |
| `Kernel` / `Discovery` / `DiscoveryFile` | Unit + Integration | Attribute 走査、決定的ロード順、循環依存検出エラー、探索結果ファイルの読み込みの検証と照合(`wp tmswp discover --check`) | `packages/core/tests/Unit/Kernel/`(`KernelTest.php`等・`DiscoveryTest.php`・`DiscoveryFileTest.php`・`DiscoverCommandTest.php`)+ `packages/core/tests/Integration/KernelBootIntegrationTest.php` |
| `Container` | Unit | PSR-11 準拠(has/get、未登録時 `NotFoundExceptionInterface`) | `packages/core/tests/Unit/Container/ContainerTest.php` |
| `MetaBag` / `MetaSchemaRegistry` / `WpMetaStorage` | Unit + Integration | 型付きアクセサの変換規則、`has()`/`get()` 分離(DR-18)、`register_*_meta` 自動登録 | `packages/core/tests/Unit/Meta/MetaBagTest.php`・`packages/core/tests/Unit/Meta/MetaSchemaRegistryTest.php` + `packages/core/tests/Integration/MetaIntegrationTest.php`・`packages/core/tests/Integration/WpMetaStorageContractTest.php` |
| `View\Template` | Unit + Integration | パス解決(テーマ → プラグイン fallback)、`extract()` 不使用の変数受け渡し | `packages/core/tests/Unit/View/TemplateTest.php` + `packages/core/tests/Integration/ViewIntegrationTest.php` |
| `Support\Html` | Unit | 属性レンダリングのエスケープ | `packages/core/tests/Unit/Support/HtmlTest.php` |
| 各拡張パッケージ | 各文書に従う | thumbnail: 署名付き URL 検証 / settings-page: nonce / acf-bridge: 生成コードのドリフト検知 / ja-toolkit: 各モジュールのオプトイン動作 | thumbnail: `packages/thumbnail/tests/Unit/UrlSignerTest.php` + `packages/thumbnail/tests/Integration/ThumbnailIntegrationTest.php` / settings-page: `packages/settings-page/tests/Integration/SettingsPageIntegrationTest.php` / acf-bridge: `packages/acf-bridge/tests/Unit/Cli/SyncRunnerTest.php`・`packages/acf-bridge/tests/Unit/Cli/AcfCommandTest.php` / ja-toolkit: `packages/ja-toolkit/tests/Unit/JaToolkitTest.php` |

---

## 9. 開発環境セットアップ手順

前提ソフトウェア: PHP 8.3〜8.5(CLI、pcov 拡張推奨。lint は 8.3 で実行する)、Composer 2.7+、Node.js 22.13+(@wordpress/env 12.0.0の要件。CI は 24)、Docker(Desktop または Engine + Compose v2)、Git。

```bash
# 1. クローン
git clone git@github.com:tmstack-io/tmswp.git
cd tmswp

# 2. 依存インストール(vendor と vendor-bin/wp-tests の両方が入る)
composer install
#    → bamarni/composer-bin-pluginはcomposer.jsonのconfig.allow-pluginsで許可済みのため、確認は出ない
#    → vendor-bin/wp-tests/vendor/bin/phpunit が存在することを確認
npm ci                  # package-lock.json どおりに @wordpress/env を入れる

# 3. Docker 不要の QA が通ることを確認
composer qa:fast        # lint + analyse + test:unit

# 4. WordPress 環境の起動(初回はイメージ取得で数分)
composer wp:start       # = node node_modules/@wordpress/env/bin/wp-env start
#    http://localhost:8888

# 5. 統合テスト
composer test:integration

# 6. フル QA(これが通れば開発開始可能)
composer qa
```

トラブルシューティング:

| 症状 | 原因と対処 |
|---|---|
| `composer install`後に`vendor-bin/wp-tests/vendor`がない | bamarni/composer-bin-pluginが動かなかった(`--no-plugins`を付けて実行した等)。`composer bin wp-tests install` |
| `composer analyse` が scanDirectories エラー | 同上(vendor-bin 未インストール) |
| `composer wp:start` が失敗 | Docker デーモン未起動。Docker Desktop を起動して再実行 |
| ポート 8888 が使用中 | `.wp-env.override.json` に `{"port": 8890}` を作成 |
| `test:coverage:unit` で「No code coverage driver」 | pcov 未導入。`pecl install pcov` 後、php.ini に `extension=pcov` |
| `test:coverage:integration` で「No code coverage driver」 | wp-env を Xdebug 無しで起動している。`composer wp:start:coverage` で起動し直す |
| 統合テストが DB 接続エラー | wp-env 未起動、または古いコンテナ。`node node_modules/@wordpress/env/bin/wp-env destroy && composer wp:start` でリセット |
| wp-env 環境の WP/PHP を変えたい | `.wp-env.override.json` で `core` / `phpVersion` を上書き(CI と同形式) |

`.gitignore` に最低限含めるもの: `/vendor/`, `/vendor-bin/*/vendor/`, `/var/`, `/.wp-env.override.json`, `/node_modules/`。

---

## 利用例(開発者・AI の作業フロー)

```bash
# コア Form 層だけを高速に回す(AI の反復実装時の推奨ループ)
vendor/bin/phpunit -c phpunit.unit.xml.dist --testsuite core --filter 'Form'
composer analyse
composer fix

# 単一パッケージの統合テストのみ
composer test:integration -- --testsuite core

# コミット前(Docker なし環境)
composer qa:fast

# PR 前 / リリース前
composer qa && composer monorepo:validate
```

ユニットテストの書き方(app 視点ではなく FW 開発視点の例):

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use Brain\Monkey\Functions;
use TMSWP\Testing\UnitTestCase;

final class EmailTest extends UnitTestCase
{
    /** @return iterable<string, array{string}> */
    public static function provideValidEmails(): iterable
    {
        yield 'plain' => ['user@example.com'];
        // … 旧 EmailRuleTest のデータプロバイダ全件をここへ移植
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideValidEmails')]
    public function testValidEmailPasses(string $email): void
    {
        Functions\when('__')->returnArg(); // WP 翻訳関数のスタブ
        // … Rule の検証(API は 05 文書の正準形に従う)
    }
}
```

---

## 旧 iwf2b からの設計根拠(簡潔に)

- 旧 QA 体制は travis-ci.org 消滅で CI が完全に死んでおり、PHPUnit も composer 管理外(グローバルインストール前提)で**ローカル実行すら不能**だった。→ composer スクリプト一本化 + GitHub Actions(DR-12)。
- 全テストが `WP_UnitTestCase` 継承で、純ユーティリティまで MySQL + wordpress-tests-lib を要求していた。→ ユニット / 統合の 2 層分離。WP 非依存層は Brain Monkey で高速化し、AI の反復ループを秒単位にする。
- `.phpcs.xml.dist` は wp-cli scaffold テンプレ未修正のまま形骸化(PSR-4 の src/ に WPCS 命名規則は適用不能)。→ WPCS を廃し php-cs-fixer(PER-CS 2.0 + strict)へ全面転換。
- テストの実質的価値は「FW の期待仕様を実行可能な形で記述した唯一のドキュメント」(AbstractPost/Tax/UserTest、Rule 系データプロバイダ、Log 系 vfsStream)にあった。→ コードではなく**仕様を**移植する対応表(8 章)を確定。
- git タグゼロ・version フィールドなしで利用側は dev-master 参照しかできなかった。→ monorepo-builder release + SemVer タグ + split 配布(DR-11)。
- Model / View / SettingsPage / CsvImport / Thumb が完全未テストのまま 10 年運用された。→ 未テスト領域の新規テスト必須リスト(8.2)とカバレッジゲートで再発を防止。

---

## 受け入れ基準

### チェックリスト

- [ ] `composer install` 一発で `vendor/` と `vendor-bin/wp-tests/vendor/` の両方が揃う
- [ ] `composer lint` / `fix` / `analyse` / `test:unit` / `test:integration` / `test` / `test:coverage`(`:unit` / `:integration` / `:check`)/ `discover:build` / `discover:check` / `acf:check:fixtures` / `qa:fast` / `qa` / `monorepo:validate` / `wp:start:coverage`が本書1章の表どおりに動作する
- [ ] fixture のテーマ・プラグインの探索結果ファイルがコミットされ、探索対象(fixture の app/)だけを変えて探索結果ファイルを更新しない場合に `composer discover:check`・`composer qa`・CI の `coverage` ジョブが失敗する
- [ ] `phpstan.neon.dist` が level max で、`packages/core/phpstan/extension.neon`(04 文書の FW 拡張)と szepeviktor/phpstan-wordpress を include している
- [ ] リポジトリに `phpstan-baseline*` が存在せず、qa.yml がその存在を fail にする
- [ ] `parameters.ignoreErrors` が空で、`reportUnmatchedIgnoredErrors: true`
- [ ] php-cs-fixer が `@PER-CS2x0`(+ `:risky`)基底と `declare_strict_types` / `final_class` / `strict_comparison` を含む 3.1 のルール全文で構成され、`composer lint` がクリーンに通る
- [ ] ユニットテストが PHPUnit 11・`tests/bootstrap-unit.php`(WP 定数を定義しない)で、WP / MySQL / Docker なしで全件パスする
- [ ] 全ユニットテストが`TMSWP\Testing\UnitTestCase`を継承している(PHPStanの規則・型推論のテスト(`RuleTestCase`・`TypeInferenceTestCase`が基底)を除く)
- [ ] 統合テストが wp-env の `cli` コンテナ内で PHPUnit 9.6(vendor-bin 隔離)+ WP_TESTS_DIR のテストライブラリで全件パスする
- [ ] `phpunit.unit.xml.dist` / `phpunit.integration.xml.dist`に6パッケージ分のtestsuiteが定義されている
- [ ] packages/*のそれぞれに`phpunit.xml.dist`・`.gitattributes`・`LICENSE`があり、split相当で単独で取り出して`composer install && composer test`が通る
- [ ] ユニット層と統合層を合算した行カバレッジが、6パッケージ全体でその版の閾値(`12-implementation-plan.md` §0.7。v0.9.0で85%)以上、パッケージ別に各パッケージの閾値(80%)以上で、`bin/coverage-check.php`が閾値未満を丸める前の値で判定してexit 1、不正な引数・読めないclover・正規化できないパス・閾値の無いパッケージのファイル・ファイルの無いパッケージの閾値をexit 2にする
- [ ] qa.yml に lint / analyse / monorepo / rules-verify / unit(PHP 8.3 / 8.5)/ integration(PHP 8.3 / 8.5 × WP 7.0 / 最新版 = 4 セル。テストライブラリは各セルの WP と同じ系列)/ coverage(PHP 8.3 / WP 最新版)の全ジョブがある
- [ ] qa.yml と split.yml のすべての `uses:` がコミットの SHA で固定され、行末にタグ名のコメントがある(5.3)
- [ ] `npm ci` で package.json に固定した版の @wordpress/env が入り、composer スクリプト・qa.yml が wp-env を `node node_modules/@wordpress/env/bin/wp-env` のパスでだけ呼ぶ
- [ ] split.ymlがタグpushで8リポジトリ(packages 6 + starters 2)をread-onlyリポジトリへsplitし、同名タグを伝播する
- [ ] `monorepo-builder.php`がpackages/とstarters/を対象にvalidate / releaseを構成している
- [ ] ルートcomposer.jsonの`replace`に全6パッケージが列挙されている
- [ ] 8.1 の移植対応表のうち「移植」と記した全行に対応する新テストが存在する(廃棄行は存在しないこと)
- [ ] 8.2 の新規テスト必須リストの全行に対応するテストが存在する
- [ ] 9 章の手順を新規環境で実施し、`composer qa` まで成功する

### 検証コマンド

```bash
composer validate --strict                      # composer.json 構文
composer install && test -x vendor-bin/wp-tests/vendor/bin/phpunit
npm ci && npm ls @wordpress/env                 # package.json で固定した版
composer qa:fast                                # lint + analyse + unit(Docker 不要)
ls phpstan-baseline* 2>/dev/null && echo NG || echo OK   # baseline 不在
composer wp:start && composer test:integration  # 統合
composer discover:check                         # fixture の探索結果ファイルの照合
composer wp:start:coverage && composer test:coverage   # 合算カバレッジゲート(>= その版の閾値)
grep -nE "uses:" .github/workflows/*.yml | grep -vE "uses: [^@]+@[0-9a-f]{40} # "   # 0 件(SHA 固定)
composer monorepo:validate                      # パッケージ整合
act -W .github/workflows/qa.yml -l              # (任意) ワークフロー構文確認
```
