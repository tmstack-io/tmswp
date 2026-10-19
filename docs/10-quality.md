# 10. QA・CI・開発ツーリング設計書

## 目的

TMStack WordPress Framework の monorepo 全体に適用する品質保証体制を確定する。
本書は以下を「人間・CI・AI エージェントが同一コマンドで実行できる」形で定義する(DR-12)。

- PHPStan(level max・baseline なし)の完全な設定と運用規律
- php-cs-fixer(PER-CS 2.0 + strict_types + final_class)の完全な設定
- PHPUnit による 2 層テスト(ユニット = PHPUnit 11・WP 非依存 / 統合 = wp-env + wp-phpunit・PHPUnit 9.6 隔離。§4.2 で確定)の構成
- composer スクリプト体系(`test` / `lint` / `fix` / `analyse` / `qa` ほか)
- GitHub Actions ワークフロー(`qa.yml` / `split.yml`)
- monorepo-builder によるパッケージ間バージョン整合
- 旧 iwf2b テスト資産の「仕様」移植対応表
- カバレッジ方針と開発環境セットアップ手順

## スコープ(対象パッケージ)

monorepo ルート(リポジトリ `tmswp`)直下の QA 設定ファイル一式と、`packages/*` 全パッケージ・`starters/*` のテスト/解析対象規定。
個々のテストケースの中身(何を検証するか)は各機能文書が定義し、本書は「テストをどこに置き、どう実行し、どう CI に載せるか」と「旧資産からの仕様移植リスト」を定義する。

本書が定義するファイル(すべて monorepo ルート基準):

```
composer.json                     … ルート(monorepo-builder merge で維持)
phpstan.neon.dist
.php-cs-fixer.dist.php
phpunit.unit.xml.dist
phpunit.integration.xml.dist
.wp-env.json
monorepo-builder.php
vendor-bin/wp-tests/composer.json … 統合テスト用 PHPUnit 9.6 隔離環境
tests/bootstrap-unit.php
tests/bootstrap-integration.php
tests/wp-tests-config.php
bin/coverage-check.php
starters/theme/phpstan-actions.neon … actions/ 規約検査用(level 0、§3A)
.github/workflows/qa.yml
.github/workflows/split.yml
packages/core/tests/Support/UnitTestCase.php (TMSWP\Core\Tests\Support\UnitTestCase)
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
| `composer test:coverage` | ユニットテスト + pcov カバレッジ + 閾値ゲート | 不要 |
| `composer test:integration` | 統合テスト(wp-env の `cli` コンテナ内で PHPUnit 9.6 実行) | **必要** |
| `composer test` | `test:unit` → `test:integration` | 必要 |
| `composer qa:fast` | `lint` → `analyse` → `test:unit`(Docker 不要の全 QA) | 不要 |
| `composer qa` | `lint` → `analyse` → `test`(全 QA) | 必要 |
| `composer monorepo:validate` | パッケージ間バージョン整合検査 | 不要 |
| `composer wp:start` / `wp:stop` | wp-env の起動 / 停止(`npm ci` で入れたローカルの @wordpress/env を使う。1.1 補足) | 必要 |

### 1.1 ルート composer.json(完全版)

ルート `composer.json` は monorepo-builder の `merge` コマンドで各パッケージの依存をマージして維持する(手編集は scripts / config / extra のみ)。マージ結果として維持すべき完全形:

```json
{
    "name": "tmswp/tmswp",
    "description": "TMStack WordPress Framework (monorepo)",
    "license": "GPL-2.0-or-later",
    "type": "library",
    "require": {
        "php": ">=8.3",
        "ext-gd": "*",
        "ext-mbstring": "*",
        "psr/container": "^2.0",
        "psr/log": "^3.0"
    },
    "require-dev": {
        "bamarni/composer-bin-plugin": "^1.8",
        "brain/monkey": "^2.6",
        "friendsofphp/php-cs-fixer": "^3.64",
        "mikey179/vfsstream": "^1.6.12",
        "mockery/mockery": "^1.6",
        "php-stubs/acf-pro-stubs": "^6.0",
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
            "TMSWP\\Thumbnail\\Tests\\": "packages/thumbnail/tests/"
        }
    },
    "scripts": {
        "lint": "php-cs-fixer fix --dry-run --diff --show-progress=none",
        "fix": "php-cs-fixer fix",
        "analyse": "phpstan analyse -c phpstan.neon.dist --memory-limit=1G",
        "i18n:build": "php tools/build-l10n.php packages/core/languages/tmswp-ja.po packages/ja-toolkit/languages/tmswp-ja.po",
        "test:unit": "phpunit -c phpunit.unit.xml.dist",
        "test:coverage": [
            "@putenv XDEBUG_MODE=off",
            "phpunit -c phpunit.unit.xml.dist --coverage-clover=var/coverage/clover.xml --coverage-text",
            "php bin/coverage-check.php var/coverage/clover.xml 85"
        ],
        "test:integration": "node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/plugins/tmswp vendor-bin/wp-tests/vendor/bin/phpunit -c phpunit.integration.xml.dist",
        "test": ["@test:unit", "@test:integration"],
        "qa:fast": ["@lint", "@analyse", "@test:unit"],
        "qa": ["@lint", "@analyse", "@test"],
        "monorepo:validate": "monorepo-builder validate",
        "monorepo:merge": "monorepo-builder merge",
        "wp:start": "node node_modules/@wordpress/env/bin/wp-env start",
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
- `extra.bamarni-bin.forward-command: true` により、`composer install` 一発で後述の `vendor-bin/wp-tests` 隔離環境も同時にインストールされる。
- `php-stubs/wordpress-stubs` は `szepeviktor/phpstan-wordpress` の依存として自動導入される(明示 require 不要)。
- `config.platform.php` を下限の `8.3.0` に固定し、依存を下限の PHP で解決する(上位の PHP で `composer update` しても、8.3 で動かない版が lock に入らない)。`vendor-bin/wp-tests/composer.json` も同じ値を持つ(§4.2)。
- php-cs-fixer は composer.json の PHP 下限より新しい PHP で実行すると、下限で使えない構文を生成しうるとして警告を出す。`composer lint` / `fix` は下限の PHP 8.3 で実行する(CI の `lint` ジョブも 8.3)。
- wp-env は npm の開発依存として monorepo ルートの `package.json` で版を固定し(`@wordpress/env` を完全一致で指定。`package-lock.json` もコミットする)、`npm ci` で導入する。composer スクリプトと qa.yml は、固定したパッケージの実行ファイルを `node node_modules/@wordpress/env/bin/wp-env …` とパスで直接実行する。未導入ならファイルが無いため node がエラーで止まる。`npx` や npm の `exec` / `run` のようにコマンド名 `wp-env` で解決する呼び出しは、ローカルに実行ファイルが無いときに npm 上の第三者パッケージ `wp-env` やグローバルの同名コマンドを実行しうるため使わない。

`package.json`(完全版):

```json
{
  "name": "tmswp",
  "private": true,
  "description": "TMStack WordPress Framework (monorepo) development tooling",
  "license": "GPL-2.0-or-later",
  "devDependencies": {
    "@wordpress/env": "11.17.0"
  }
}
```

### 1.2 `acf:sync` スクリプト

`acf:sync` は acf-bridge のコードジェネレータ(DR-06)を実行するスクリプトで、**アプリ(テーマ/プラグイン)側の composer.json に定義する**。WP-CLI コマンド名 `wp tmswp acf sync` の正準仕様は acf-bridge 文書が定義する。

スターター(starters/theme, starters/plugin)の composer.json scripts に追加する定義:

```json
{
    "scripts": {
        "acf:sync": "wp tmswp acf sync"
    }
}
```

wp-env 利用時は `node node_modules/@wordpress/env/bin/wp-env run cli --env-cwd=wp-content/themes/<theme> wp tmswp acf sync` の形で実行する(wp-env はプロジェクトの package.json で版を固定して導入する。1.1 補足)。monorepo ルートには `acf:sync` を置かない(acf-json はアプリ資産であり FW リポジトリには存在しないため)。acf-bridge 自身のジェネレータ検証は `packages/acf-bridge/tests/` のフィクスチャ acf-json に対するユニット/統合テストで行う。

### 1.3 `tms:check` スクリプト

`tms:check` は `actions/` 規約(DR-19)の静的検査(§3A)を実行するスクリプトで、**テーマ形態のアプリ側 composer.json に定義し `qa` ゲートに含める**。検査は 2 系統からなる: (1) `actions/` 専用の PHPStan 設定 `phpstan-actions.neon`(level 0・規約検査のみ)による FW 同梱カスタムルール検査(output / set_404)、(2) `tmswp/core` 同梱の軽量スクリプト `bin/tms-check`(WP 非依存)による孤児検査。`actions/` はアプリ資産であり monorepo ルートには存在しないため、ルートの `qa` には置かない(コアでは検査ロジック自体を RuleTestCase と fixture で検証する。§3A)。

スターター(starters/theme)の composer.json scripts に追加する定義:

```json
{
    "scripts": {
        "tms:check": [
            "phpstan analyse -c phpstan-actions.neon",
            "tms-check actions ."
        ]
    }
}
```

`tms-check` は `tmswp/core` の `bin` 経由で `vendor/bin/tms-check` として解決され、PHPStan 側も含め Docker・wp-env 不要で実行できる。プラグイン形態は `actions/` 規約を持たないため定義しない(§3A.3)。

---

## 2. PHPStan 設定

### 2.1 phpstan.neon.dist(完全版)

```neon
includes:
    # WordPress コア関数のスタブと動的戻り値拡張(php-stubs/wordpress-stubs を内包)
    - vendor/szepeviktor/phpstan-wordpress/extension.neon
    # PHPUnit 拡張(データプロバイダ・モックの型解決)+ ルール
    - vendor/phpstan/phpstan-phpunit/extension.neon
    - vendor/phpstan/phpstan-phpunit/rules.neon
    # 厳格ルール(===強制・暗黙bool禁止等)
    - vendor/phpstan/phpstan-strict-rules/rules.neon
    # @deprecated 利用検出(WP バージョン更新時の検知)
    - vendor/phpstan/phpstan-deprecation-rules/rules.neon
    # FW 同梱 PHPStan 拡張(MetaSchema/OptionSchema からの型ナローイング。正準仕様は 04 文書)
    - packages/core/phpstan/extension.neon

parameters:
    level: max

    # 対応範囲 PHP 8.3〜8.5(DR-02)
    phpVersion:
        min: 80300
        max: 80500

    paths:
        - packages/core/src
        - packages/core/phpstan/src
        - packages/core/tests
        - packages/core/bin
        - packages/thumbnail/src
        - packages/thumbnail/tests
        - packages/settings-page/src
        - packages/settings-page/tests
        - packages/acf-bridge/src
        - packages/acf-bridge/tests
        - packages/ja-toolkit/src
        - packages/ja-toolkit/tests
        - starters/theme/app
        - starters/plugin/app
        - bin
        # 開発用スクリプト(rules/ のコード例の抽出・検査等)
        - tools

    excludePaths:
        # テスト用フィクスチャ(意図的な型違反・生成コードのサンプルを含む)
        - packages/*/tests/fixtures/*
        - packages/*/tests/*/fixtures/*

    scanDirectories:
        # 統合テストが参照する WP_UnitTestCase / tests_add_filter 等の実体
        - vendor-bin/wp-tests/vendor/wp-phpunit/wp-phpunit/includes
        - vendor-bin/wp-tests/vendor/yoast/phpunit-polyfills/src

    scanFiles:
        # acf-bridge が参照する get_field / update_field 等(ACF Pro スタブ)
        - vendor/php-stubs/acf-pro-stubs/acf-pro-stubs.php

    # wordpress-stubs の PHPDoc 精度に起因する誤検知を抑制しつつ、実型での検査を維持する
    treatPhpDocTypesAsCertain: false

    # ignore 規律の強制: 使われていない ignore はエラー
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
5. 統合テストコードも解析対象に含める。`WP_UnitTestCase` 等のシンボルは `scanDirectories` の wp-phpunit 実体から解決する。`vendor-bin/wp-tests` が未インストールの状態で `composer analyse` を実行した場合は scanDirectories 不在のエラーになるため、セットアップ手順(後述)どおり `composer install` を先に完了させること。

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
        __DIR__ . '/starters',
        __DIR__ . '/tests',
        __DIR__ . '/bin',
        __DIR__ . '/tools',
    ])
    ->name('*.php')
    ->notPath('#tests/(.+/)?fixtures/#')
    ->append([__FILE__, __DIR__ . '/monorepo-builder.php']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        // PER Coding Style 2.0(PSR-12 の後継規格)。php-cs-fixer ではドット表記の
        // @PER-CS2.0 識別子が非推奨(4.0 で削除予定)のため 'x' 表記を用いる
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,

        // --- 厳密性(コーディング規約の機械的強制) ---
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'void_return' => true,
        'final_class' => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // --- import 規律 ---
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
        // concat_space(one)/ cast_spaces(single)/ trailing_comma_in_multiline は
        // @PER-CS2x0 基底に内包されるため手動指定しない。trailing comma の適用対象は
        // 基底の既定(array_destructuring を含む)へ拡張される(意図した挙動確定)
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
- ビューテンプレート(スターターのテーマテンプレート、`View\Template` が読む `template-parts/` 等)は Finder の対象に**含めない**(starters の対象は `app/` のみ)。WordPress 標準のテーマ構築手法を壊さないという絶対原則(要件 3)に基づき、テンプレートファイルには FW のスタイル規約を強制しない。

---

## 3A. actions/ 静的検査(PHPStan カスタムルール + bin/tms-check)

`actions/` 規約(DR-19。テンプレート前処理を「ページテンプレートと同名の `actions/{相対パス}.php` ファイル」へ分離する仕組み。正準仕様は 07 文書)を、軽量な静的検査で守る。`actions/` ファイルは本則の PHPStan(level max・型検査)にも php-cs-fixer の Finder にも含まれない WP グローバル文脈コードであり、型解析・スタイル検査では捕捉できない誤用がある。これを次の 2 系統で補う:

| 検査 | check-id | 実現方式 |
|---|---|---|
| 出力禁止 | `output` | FW 同梱 PHPStan 拡張のカスタムルール `NoOutputRule`(§3A.1) |
| `set_404()` 直呼び禁止 | `set_404` | 同カスタムルール `NoSet404Rule`(§3A.1) |
| 孤児 actions | `orphan` | `bin/tms-check`(WP 非依存の軽量スクリプト。§3A.2) |

単一ファイル内の構文ノード照合で判定できる 2 検査は PHPStan カスタムルールとして実装し(文字列リテラル・コメント・ヒアドキュメント内の出現を誤検出しないことはパーサ = AST が構造的に保証する。静的検査エンジンを PHPStan 1 本に統一)、ディレクトリ走査とテンプレート照合を要する孤児検査のみ、Docker 不要で単体実行できるスクリプトとして残す。

### 3A.1 PHPStan カスタムルール(output / set_404)

- ルールクラスは `tmswp/core` 同梱の `TMSWP\Core\PHPStan\Actions\NoOutputRule` / `TMSWP\Core\PHPStan\Actions\NoSet404Rule`(名前空間は §2.2 項 3 と同じ。検査仕様の正準は本節)。
- **`packages/core/phpstan/extension.neon` には登録しない**(extension-installer で自動有効化されると `actions/` 以外のコードにも適用されてしまうため)。アプリ側の `actions/` 専用設定 `phpstan-actions.neon` で明示登録する。
- スターター(starters/theme)に置く `phpstan-actions.neon`(完全版):

```neon
parameters:
    # 型検査は課さない(規約検査のみ)。WordPress 標準のテーマ構築手法を
    # 壊さないという絶対原則(要件 3)に基づき actions/ には level 0 を適用する
    level: 0
    paths:
        - actions

rules:
    - TMSWP\Core\PHPStan\Actions\NoOutputRule
    - TMSWP\Core\PHPStan\Actions\NoSet404Rule
```

検査仕様:

1. **出力ノード(check-id: `output`、`NoOutputRule`)**
   `actions/` はデータ準備専用で出力を持たない。次の AST ノードを検出したら違反とする:
   - `echo` / `print` / `exit` / `die`(`exit`/`die` は後述の例外あり)
   - `<?=`(echo タグ)、インライン HTML(`?>` の外側に出た生テキスト。ただしファイル先頭の BOM・空白のみは除外)
   - `printf` / `vprintf` / `print_r($x, false)` 等の出力系関数呼び出し(関数名は出力系の固定リストで照合)
   `exit`/`die` の例外: **直前の兄弟ステートメント**が `wp_safe_redirect( ... );` の関数呼び出し文である場合のみ、その直後の `exit`/`die` を許可する(AST の兄弟ノード参照で判定。フォーム送信後の PRG。07 文書のリダイレクト規約)。それ以外の `exit`/`die` は違反。

2. **`$wp_query->set_404()` 直呼び(check-id: `set_404`、`NoSet404Rule`)**
   404 は必ず `return \TMSWP\Core\View\ActionRouter::notFound();` で表明する(07 文書)。変数 `$wp_query` に対する `set_404` メソッド呼び出しノードを検出したら違反。

- パースエラーの報告は PHPStan の標準機能に委ねる(専用の検査 ID は持たない)。
- ルールの検証は FW 同梱 PHPStan 拡張と同じ RuleTestCase 基盤(04 文書のテスト方式)を再利用し、健全な actions・各違反を含む actions の fixture で違反メッセージを固定する。

### 3A.2 bin/tms-check(孤児検査)

- `bin/tms-check` は **tmswp/core に同梱**する素の PHP スクリプト(`packages/core/bin/tms-check`)。配布時はアプリの `vendor/tmswp/core/bin/tms-check` に置かれる。
- **WP 非依存・コア autoload 非依存**で動く。標準ファイル関数のみを使い、WordPress 関数も Composer オートローダも require しない(`php bin/tms-check <dir>` 単体で動く)。PHP コードのパースは行わない(構文に依存する検査は §3A.1 のカスタムルールへ移行済み)。これにより Docker なしで常時実行できることを保証する。
- 引数:

```
php bin/tms-check <actions-dir> [<template-root> ...]
```

  - `<actions-dir>`: 検査対象の `actions/` ディレクトリ(再帰走査)。
  - `<template-root>`(任意・複数可): 孤児判定でテンプレートの存在を照合する基準ディレクトリ(通常はテーマルート。省略時は `<actions-dir>` の親ディレクトリを基準にする)。
- 検査(check-id: `orphan`): `actions/` 配下の各 `.php` の `<actions-dir>` からの相対パスを取り、`<template-root>` のいずれかに **同じ相対パスのテンプレートが存在するか**を照合する(例: `actions/page-contact.php` ↔ `<template-root>/page-contact.php`、`actions/single/product.php` ↔ `<template-root>/single/product.php`)。対応テンプレートが 1 つも無ければ違反(実行されない死にファイル)。
- 終了コード: 違反 0 件で `0`、1 件以上で `1`、引数不正・対象ディレクトリ不在で `2`。違反は `<ファイル>:<行>: <検査ID> <メッセージ>` 形式で STDERR に出力する(`orphan` はファイル単位の違反のため行番号は `1` を用いる)。違反は全件を集約して報告する(最初の 1 件で打ち切らない)。
- 実装の骨子: (a) 引数解析、(b) `<actions-dir>` の `*.php` 再帰列挙(`RecursiveDirectoryIterator`)、(c) 相対パス照合、(d) 違反集約と STDERR 出力 + 終了コード決定。

### 3A.3 composer スクリプトへの組込み

`actions/` 検査は **アプリ(スターター/利用側プロジェクト)の `composer qa` ゲートに組込む**(`actions/` はアプリ資産でありコア monorepo には存在しないため、コアルートの `qa` には含めない)。`tms:check` の名称と `qa` 内のゲート位置は従来どおり維持し、2 系統を束ねる。

スターター(starters/theme)の composer.json scripts に追加する定義(§7 のアプリ側 QA 最小要件に対応):

```json
{
    "scripts": {
        "tms:check": [
            "phpstan analyse -c phpstan-actions.neon",
            "tms-check actions ."
        ],
        "qa": ["@lint", "@analyse", "@tms:check", "@test"]
    }
}
```

- `tms-check` はコアの composer.json の `bin` に登録し(`"bin": ["bin/tms-check"]`)、アプリ側 `vendor/bin/tms-check` から呼べるようにする。
- プラグイン形態は `actions/` 規約を持たないため `tms:check` を定義しない(§7 項 5)。
- コア monorepo の qa には組込まないが、検査ロジック自体はコアで検証する: ルール 2 本は RuleTestCase(§3A.1)で、`packages/core/bin/tms-check` は `phpstan.neon.dist` の `paths`(`bin` を含む)での静的解析と `packages/core/tests/Unit/` の fixture(健全な actions・孤児 actions)で検査結果(終了コード・違反メッセージ)を固定する。

---

## 4. テスト構成(PHPUnit 2 層)

### 4.1 層の定義

| 層 | 配置 | 基盤 | 実行環境 | 目的 |
|---|---|---|---|---|
| ユニット | `packages/<pkg>/tests/Unit/` | PHPUnit **11** + Brain Monkey(+ Mockery / vfsStream) | 素の PHP(WP 不要) | ロジックの仕様確定。高速・常時実行 |
| 統合 | `packages/<pkg>/tests/Integration/` | PHPUnit **9.6** + wp-phpunit + Yoast Polyfills | wp-env(Docker) | WP 実環境での登録系・DB 系の検証 |

ディレクトリ規約(全パッケージ共通):

```
packages/core/
├── src/
└── tests/
    ├── Unit/            # 名前空間 TMSWP\Core\Tests\Unit\ — src/ をミラーする
    │   ├── Support/ArrTest.php
    │   └── Form/Rule/EmailRuleTest.php …
    ├── Integration/     # 名前空間 TMSWP\Core\Tests\Integration\
    │   ├── _boot.php    # (任意) muplugins_loaded 時に require されるブートファイル
    │   └── Post/PostTypeTest.php …
    ├── Support/         # テスト支援クラス(UnitTestCase 等)
    └── fixtures/        # フィクスチャ(QA 対象外)
```

`tests/Unit/` は `src/` のディレクトリ構造をミラーする(旧 iwf2b の `tests/TestCase` 規約を踏襲)。

### 4.2 統合層の PHPUnit バージョン制約と隔離(確定事項)

WordPress コアテストスイート(wp-phpunit が配布する `includes/bootstrap.php` / `WP_UnitTestCase`)は **PHPUnit 9.6 が上限**であり(WP 7.x の制約。bootstrap が PHPUnit 10+ を明示拒否する)、PHPUnit 11 とは併用できない。よって DR-12 の「PHPUnit 11」は**ユニット層に適用**し、統合層は次のとおり隔離した PHPUnit 9.6 で実行する。これは本書で確定する技術判断である。

- `bamarni/composer-bin-plugin` の名前空間 `wp-tests` に統合テスト専用ツールチェーンを隔離インストールする。
- ルート vendor(PHPUnit 11)と `vendor-bin/wp-tests/vendor`(PHPUnit 9.6)は衝突しない。
- 統合テストコードは Yoast Polyfills 流儀(`set_up()` / `tear_down()`)で書き、PHPUnit 9.6 で動く assertion のみ使用する。WP コアが将来 PHPUnit 11 対応した時点で隔離を解除しユニット層と統一する(その際もテストコードの変更は最小で済む)。

`vendor-bin/wp-tests/composer.json`(完全版):

```json
{
    "require": {
        "php": ">=8.3",
        "phpunit/phpunit": "^9.6",
        "wp-phpunit/wp-phpunit": "^7.0",
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

- `wp-phpunit/wp-phpunit` はテスト対象の WP と同じ系列(メジャー.マイナー)を使う。lock には最新の系列を入れ、ローカルの既定(`.wp-env.json` の `core: null` = 最新版)と揃える。
- CI は各セルで検証する WP の版を確定し、実行前に `composer bin wp-tests require "wp-phpunit/wp-phpunit:~X.Y.0" --update-with-all-dependencies` で同じ系列に固定する(WP 7.0 のセルは `~7.0.0`、最新版のセルは WordPress.org の版情報 API から得た最新版の系列。§5.1)。その系列の wp-phpunit がまだ公開されていなければ、この固定が失敗して CI が止まる。
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
         beStrictAboutOutputDuringTests="true"
         displayDetailsOnTestsThatTriggerDeprecations="true"
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
    </testsuites>
    <source>
        <include>
            <directory>packages/core/src</directory>
            <directory>packages/thumbnail/src</directory>
            <directory>packages/settings-page/src</directory>
            <directory>packages/acf-bridge/src</directory>
            <directory>packages/ja-toolkit/src</directory>
        </include>
    </source>
</phpunit>
```

`--testsuite core` のようにパッケージ単位の実行が可能(AI が単一パッケージを反復開発する際の推奨形)。

### 4.4 tests/bootstrap-unit.php と UnitTestCase 基底

`tests/bootstrap-unit.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
```

WP 定数(`ABSPATH` 等)は**意図的に定義しない**。ユニット層のテスト対象が WP 定数や WP 関数へ直接到達した場合、未定義エラー / Brain Monkey の例外として顕在化させ、「WP 関数呼び出しは薄い境界に寄せる」規約(共通前提)の違反を検出する。

全ユニットテストの基底クラス `TMSWP\Core\Tests\Support\UnitTestCase`(完全シグネチャ):

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * ユニット層の共通基底。Brain Monkey の setUp/tearDown と
 * Mockery の expectation 検証を全テストに保証する。
 */
abstract class UnitTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
```

- 全パッケージのユニットテストはこれを継承する(`abstract` のため final_class ルールと両立)。
- WP 関数のモックは Brain Monkey の `Functions\when()` / `Functions\expect()` を使用する。`Filters\expectAdded()` / `Actions\expectAdded()` でフック登録を検証する。
- ファイル I/O を伴うテスト(SimpleLogger / TempFileStore / ComponentCache)は vfsStream を使用する(旧 iwf2b の作法を踏襲)。

### 4.5 phpunit.integration.xml.dist(PHPUnit 9.6 スキーマ、完全版)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor-bin/wp-tests/vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap-integration.php"
         backupGlobals="false"
         colors="true"
         convertDeprecationsToExceptions="false"
         failOnWarning="true"
         beStrictAboutTestsThatDoNotTestAnything="true">
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
    </testsuites>
</phpunit>
```

カバレッジは統合層では計測しない(4.8 参照)ため `<coverage>` 要素は置かない。

### 4.6 tests/bootstrap-integration.php(完全版)

```php
<?php

declare(strict_types=1);

// 1. monorepo ルートの autoload(FW 本体 + テストクラス)
require dirname(__DIR__) . '/vendor/autoload.php';
// 2. 隔離ツールチェーンの autoload(Yoast Polyfills 等)
require dirname(__DIR__) . '/vendor-bin/wp-tests/vendor/autoload.php';

$wpPhpunitDir = dirname(__DIR__) . '/vendor-bin/wp-tests/vendor/wp-phpunit/wp-phpunit';

// WP テストスイートへの設定注入
putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');
define(
    'WP_TESTS_PHPUNIT_POLYFILLS_PATH',
    dirname(__DIR__) . '/vendor-bin/wp-tests/vendor/yoast/phpunit-polyfills'
);

require $wpPhpunitDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    // 各パッケージの統合テスト用ブートファイルをパッケージ名昇順(決定的順序)でロードする。
    // _boot.php は Kernel ブートやテスト用エンティティ(fixtures)の登録に使う。
    $bootFiles = glob(dirname(__DIR__) . '/packages/*/tests/Integration/_boot.php');

    foreach ($bootFiles === false ? [] : $bootFiles as $bootFile) {
        require $bootFile;
    }
});

require $wpPhpunitDir . '/includes/bootstrap.php';
```

- `_boot.php` の典型的な中身は「テスト用 app ディレクトリ(`tests/fixtures/app`)を指す `KernelConfig` で `Kernel::boot()` を呼ぶ」こと。Kernel のテスト用ブート手順の正準は 01 文書に従う。
- 統合テストクラスは `WP_UnitTestCase` を継承し、フィクスチャは `set_up()` / `tear_down()`(Polyfills 流儀)、WP ファクトリは `self::factory()->post` 等を使用する。

### 4.7 tests/wp-tests-config.php と .wp-env.json(完全版)

`tests/wp-tests-config.php`(wp-env の `cli` コンテナ内で読まれる):

```php
<?php

declare(strict_types=1);

// wp-env の環境(mysql コンテナの wordpress データベース)の既定値。環境変数で上書き可能。
// テーブル接頭辞 wptests_ で開発用サイトのテーブル(wp_)と分ける
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

// WP コア由来の deprecation を除外する
error_reporting(E_ALL & ~E_DEPRECATED);
```

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
- `testsEnvironment: false` により、wp-env は開発用の環境(`wordpress` / `cli` / `mysql`)だけを作る。@wordpress/env 11 では development と tests の 2 環境を同時に作る既定の動作が非推奨のため、統合テストはこの環境の `cli` コンテナで実行し、テーブル接頭辞 `wptests_` で開発用サイトのテーブルと分ける。
- `plugins` に wordpress.org の無料版 ACF(本家 WP Engine の `advanced-custom-fields`)を、版を固定した zip で入れる(D-11)。ACF Pro 限定機能のテストは CI では行わない。
- `mappings` により monorepo 全体が `wp-content/plugins/tmswp` にマウントされ、コンテナ内からルートの `vendor/` / `vendor-bin/` / 各設定ファイルにそのままアクセスできる。これが `composer test:integration` の `--env-cwd=wp-content/plugins/tmswp` の前提。
- `.wp-env.override.json` は `.gitignore` に追加する。

### 4.8 カバレッジ方針

- 計測対象は**ユニット層のみ**。ドライバは pcov(ローカル: `pecl install pcov`、CI: `setup-php` の `coverage: pcov`)。統合層は wp-env コンテナ内実行でありカバレッジは計測しない(実行コストと環境差異のため)。
- CI ゲート: **5パッケージ合算の line coverage ≥ 85%**。`composer test:coverage` が clover を生成し `bin/coverage-check.php` が閾値を検査する。
- モジュール別の目標値(レビュー指針。CI 自動ゲートは合算 85% のみ):

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

- 引数: `argv[1]` = clover.xml パス、`argv[2]` = 最低カバレッジ(float、%)。
- 手順:
  1. `simplexml_load_file(argv[1])`。失敗時は STDERR にメッセージを出し exit(2)。
  2. `//project/metrics` の `statements` / `coveredstatements` 属性を取得。`statements` が 0 なら exit(2)。
  3. `coverage = coveredstatements / statements * 100`(小数 2 桁丸め)。
  4. `coverage < argv[2]` なら `Coverage 83.21% is below required 85%` 形式で STDERR 出力し exit(1)。以上なら `Coverage 87.45% (>= 85%)` を STDOUT 出力し exit(0)。

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
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - uses: ramsey/composer-install@v3
      - name: Forbid PHPStan baseline
        run: |
          if ls phpstan-baseline* 2>/dev/null; then
            echo '::error::PHPStan baseline files are forbidden (DR-12)'
            exit 1
          fi
      - run: composer lint

  analyse:
    name: Static Analysis
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - uses: ramsey/composer-install@v3
      - run: composer analyse

  monorepo:
    name: Monorepo Validate
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - uses: ramsey/composer-install@v3
      - run: composer monorepo:validate

  rules-verify:
    name: Rules Code Examples
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - uses: ramsey/composer-install@v3
      - name: Extract rules code examples
        run: php tools/extract-rules-code.php
      - name: Verify extracted examples
        run: php tools/verify-rules-code.php

  unit:
    name: Unit (PHP ${{ matrix.php }})
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.5']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: ${{ matrix.php == '8.3' && 'pcov' || 'none' }}
      - uses: ramsey/composer-install@v3
      - name: Run unit tests
        if: matrix.php != '8.3'
        run: composer test:unit
      - name: Run unit tests with coverage gate
        if: matrix.php == '8.3'
        run: composer test:coverage
      - name: Upload coverage artifact
        if: matrix.php == '8.3'
        uses: actions/upload-artifact@v4
        with:
          name: coverage-clover
          path: var/coverage/clover.xml

  integration:
    name: Integration (PHP ${{ matrix.php }} / WP ${{ matrix.wp }})
    runs-on: ubuntu-latest
    needs: [unit]
    timeout-minutes: 20
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.5']
        wp: ['7.0', 'latest']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - uses: actions/setup-node@v4
        with:
          node-version: '24'
          cache: npm
      - run: npm ci
      - uses: ramsey/composer-install@v3
      # 検証する WordPress の版を確定し、テストライブラリ(wp-phpunit)を同じ系列(X.Y)に揃える。
      # latest は WordPress.org の版情報 API から最新版を取得する。
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
          echo "series=$(echo "${version}" | cut -d. -f1,2)" >> "$GITHUB_OUTPUT"
      - name: Pin wp-phpunit to WordPress ${{ steps.wp.outputs.series }}
        run: composer bin wp-tests require --no-interaction "wp-phpunit/wp-phpunit:~${{ steps.wp.outputs.series }}.0" --update-with-all-dependencies
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
```

設計上の要点:

- マトリクスは D-09 のとおり、ユニットが PHP 8.3 / 8.5、統合が **PHP 8.3 / 8.5 × WP 7.0 / 最新版** の 4 セル。ユニットは WP 非依存のため PHP 軸のみ。
- 統合の各セルは、検証する WP の版を先に確定し(WP 7.0 のセルは 7.0、最新版のセルは WordPress.org の版情報 API の最新版)、wp-env の `core` をその版の zip に、テストライブラリ(wp-phpunit)を同じ系列に固定する(§4.2)。API の取得や系列の固定に失敗したセルは、その時点で失敗する。
- wp-env は `npm ci` で package-lock.json どおりに導入し(`actions/setup-node` の npm キャッシュを使う)、composer スクリプト経由でローカルの wp-env だけを呼ぶ(§1.1 補足)。
- `lint` / `analyse` / `monorepo` / `rules-verify` は下限の PHP 8.3 で実行する(php-cs-fixer の推奨と依存解決の下限に合わせる。§1.1 補足)。PHP 8.5 での解析は PHPStan の `phpVersion`(8.3〜8.5。§2.1)が担う。
- `integration` は `timeout-minutes: 20` で打ち切る。`wp:start` は Docker イメージ取得の一時的な失敗に備えて 10 秒間隔で 3 回まで再試行し、3 回目の失敗でジョブを失敗させる。失敗時は wp-env のログ(`logs development --no-watch`)を出力する。
- `lint` ジョブが baseline ファイルの不存在を機械的に強制する。
- カバレッジゲートは PHP 8.3 セルでのみ実行(計測の重複を避ける)。
- `integration` は `unit` 成功後に起動し、Docker 起動コストを失敗時に節約する。

### 5.2 .github/workflows/split.yml(完全版)

read-only split 配布(DR-11)。各パッケージを個別リポジトリ(`<org>/core` 等)へ push し、Packagist はその split リポジトリを購読する。split 先リポジトリは Archive しないが、README に「read-only / 開発は monorepo で」と明記し PR を受け付けない。

```yaml
name: Split Packages

on:
  push:
    branches: [main]
    tags: ['v*']

env:
  GITHUB_ORG: tmswp

jobs:
  split:
    name: "Split ${{ matrix.package.local }}"
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        package:
          - { local: 'packages/core',          repo: 'core' }
          - { local: 'packages/thumbnail',     repo: 'thumbnail' }
          - { local: 'packages/settings-page', repo: 'settings-page' }
          - { local: 'packages/acf-bridge',    repo: 'acf-bridge' }
          - { local: 'packages/ja-toolkit',    repo: 'ja-toolkit' }
          - { local: 'starters/theme',         repo: 'starter-theme' }
          - { local: 'starters/plugin',        repo: 'starter-plugin' }
    steps:
      - uses: actions/checkout@v4

      # ブランチ push: 最新コミットを split
      - if: "!startsWith(github.ref, 'refs/tags/')"
        uses: danharrin/monorepo-split-github-action@v2
        with:
          access_token: ${{ secrets.SPLIT_ACCESS_TOKEN }}
          package_directory: ${{ matrix.package.local }}
          repository_organization: ${{ env.GITHUB_ORG }}
          repository_name: ${{ matrix.package.repo }}
          user_name: 'tmswp-bot'
          user_email: 'bot@tmswp.dev'

      # タグ push: 同名タグを split 先にも付与
      - if: "startsWith(github.ref, 'refs/tags/')"
        uses: danharrin/monorepo-split-github-action@v2
        with:
          tag: ${{ github.ref_name }}
          access_token: ${{ secrets.SPLIT_ACCESS_TOKEN }}
          package_directory: ${{ matrix.package.local }}
          repository_organization: ${{ env.GITHUB_ORG }}
          repository_name: ${{ matrix.package.repo }}
          user_name: 'tmswp-bot'
          user_email: 'bot@tmswp.dev'
```

- `SPLIT_ACCESS_TOKEN` は split 先リポジトリへの push 権限を持つ PAT(fine-grained、contents: write)。リポジトリ Secrets に登録する。
- `user_name` / `user_email` の実値は組織運用に合わせて差し替えてよい(機能要件ではない)。
- split アクションは `danharrin/monorepo-split-github-action@v2`(メンテナンス終了した symplify 版の維持後継)を正準とする(01 §4.4 と同一)。タグは `${{ github.ref_name }}` で渡すこと。`with:` の値内では bash のパラメータ展開(`${GITHUB_REF#refs/tags/}` 等)は評価されず文字列リテラルのままタグ名として渡るため、使用してはならない。同等機能の action への差し替えは PATCH 扱いで可(01 §4.4)。

---

## 6. monorepo-builder 設定

### 6.1 monorepo-builder.php(完全版)

```php
<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\AddTagToChangelogReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushNextDevReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushTagReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetCurrentMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetNextMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\TagVersionReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\UpdateBranchAliasReleaseWorker;

return static function (MBConfig $mbConfig): void {
    $mbConfig->packageDirectories([
        __DIR__ . '/packages',
        __DIR__ . '/starters',
    ]);

    $mbConfig->defaultBranch('main');
    $mbConfig->packageAliasFormat('<major>.<minor>.x-dev');

    // merge 時に全パッケージ composer.json へ揃える共通データ
    $mbConfig->dataToAppend([
        'require-dev' => [],
        'config' => [
            'sort-packages' => true,
        ],
    ]);

    // release ワークフロー(SemVer タグ運用: DR-11)
    $mbConfig->workers([
        SetCurrentMutualDependenciesReleaseWorker::class, // 相互依存を ^X.Y.Z に固定
        AddTagToChangelogReleaseWorker::class,            // CHANGELOG.md の Unreleased をタグ化
        TagVersionReleaseWorker::class,                   // git tag vX.Y.Z
        PushTagReleaseWorker::class,                      // タグ push → split.yml が発火
        SetNextMutualDependenciesReleaseWorker::class,    // 相互依存を次期 dev へ
        UpdateBranchAliasReleaseWorker::class,            // branch-alias 更新
        PushNextDevReleaseWorker::class,
    ]);
};
```

### 6.2 パッケージ間依存の規律

- 各拡張パッケージの composer.json は `"tmswp/core": "^X.Y"`(リリース時に `SetCurrentMutualDependenciesReleaseWorker` が同期)を宣言する。
- ルート composer.json の `replace` に全パッケージを列挙する(1.1 参照)。これにより monorepo 内開発では vendor を介さずローカル `packages/*` のコードが使われる。
- `composer monorepo:validate` が「各パッケージの require と ルートの require の整合」「相互依存バージョンの不一致」を検査する。CI 必須(qa.yml の `monorepo` ジョブ)。
- 新パッケージ追加手順: `packages/<name>/composer.json` を作成 → ルートの `autoload(-dev)` / `replace` へ `composer monorepo:merge` で反映 → `phpstan.neon.dist` の paths、`phpunit.*.xml.dist` の testsuite、`split.yml` の matrix に追記。この 5 点セットを忘れると `monorepo:validate` または受け入れ基準(後述)で検出される。

### 6.3 リリース手順(正準)

```bash
vendor/bin/monorepo-builder release v0.2.0   # 検証は事前に composer qa
git push origin main --tags                  # PushTagReleaseWorker が未 push の場合
# → split.yml がタグを各 read-only リポジトリへ伝播 → Packagist が自動更新
```

バージョンは全パッケージ同一(lockstep)。SemVer に従い、0.x 期はマイナーで破壊的変更を許容する。

---

## 7. スターター/アプリ側 QA の最小要件

スターター(starter-theme / starter-plugin)同梱の QA 設定の正準仕様は DR-13 担当文書に委ねる。本書は FW として保証すべき最小要件のみ定める:

1. スターターの composer.json は `require-dev` に `phpstan/phpstan`、`szepeviktor/phpstan-wordpress`、`phpstan/extension-installer`、`friendsofphp/php-cs-fixer` を含み、`lint` / `fix` / `analyse` / `qa` スクリプトを monorepo ルートと**同名・同義**で定義する(AI が同じコマンド体系で作業できること)。
2. `phpstan/extension-installer` により `tmswp/core` 同梱の PHPStan 拡張(2.2 項 4)が**設定なしで自動有効化**されること。スターターの phpstan.neon は `level: max` + `paths: [app]` を既定とする。
3. アプリの `app/Acf/*Fields.php`(`@generated`、DR-06)は解析対象に**含める**(生成コードも型検査を通す。CI でのドリフト検知は acf-bridge 文書の管轄)。
4. テーマテンプレート(`*.php` のうち WP テンプレート階層ファイルと `template-parts/`)は php-cs-fixer / PHPStan(型検査)の対象に含めない(要件 3 の絶対原則)。`actions/` 配下も php-cs-fixer と本則の PHPStan(`phpstan.neon` の level max)の対象には含めず、専用設定 `phpstan-actions.neon`(level 0。型検査は課さず規約検査のみのため要件 3 に抵触しない)と `bin/tms-check`(孤児検査)で検査する(§3A)。
5. テーマ形態のスターターの `composer qa` は `lint` → `analyse` → `tms:check`(= `phpstan analyse -c phpstan-actions.neon` → `tms-check actions .`)→ `test` の順とし、`tms:check` を必須ゲートに含める(§3A)。`tms-check` は `tmswp/core` の `bin` 経由で `vendor/bin/tms-check` として解決される。プラグイン形態は `actions/` 規約を持たないため `tms:check` を含めない(§3A.3・11 文書の plugin 版差分)。

---

## 8. 旧 iwf2b テスト資産の仕様移植リスト

旧テストは PHPUnit 5.7〜7 世代・全件 `WP_UnitTestCase` 継承であり**コードの流用は不可**。以下は「実行可能な仕様書」としての移植対応表である(検証ケース・データプロバイダの値リストを新テストへ書き写す)。新クラス名は各機能文書の正準名に従う(Rule 名は 05 文書、エンティティは 02/03 系文書)。

### 8.1 移植対応表

| # | 旧テスト(iwf2b/tests/) | 移植先(新 FW) | 層 | 移植する仕様 / 廃棄理由 |
|---|---|---|---|---|
| 1 | `bootstrap.php` | `tests/bootstrap-integration.php` | — | `tests_add_filter('muplugins_loaded')` でのロード方式のみ踏襲。install-wp-tests.sh 経路は廃止(wp-env + wp-phpunit へ) |
| 2 | `test-sample.php` | — | — | 廃棄(scaffold 残骸) |
| 3 | `TestCase/ArrTest.php` | `packages/core/tests/Unit/Support/ArrTest.php` | Unit | ドット記法 get/set のデータプロバイダ**全件**移植(ネスト配列・配列パス両形式) |
| 4 | `TestCase/TextTest.php` | `packages/core/tests/Unit/Support/StrTest.php` | Unit | stringify(__toString / Iterator 対応)・ケース変換のうち、新 `Support\Str` API に存在するものを移植。新 API に対応がない検証は廃棄 |
| 5 | `TestCase/UtilTest.php` | (分割) | Unit | `parse_phpdoc_tags` 系は廃棄(Attribute 化で機構ごと消滅)。`is_empty` の境界値(0 / '0' / '' / [] / Countable)は Form 層の空値判定テスト(`Unit/Form/Validator` 系)へ仕様移植。`filter` / `namespace_split` は対応 API なしのため廃棄 |
| 6 | `TestCase/OptionTest.php` | `packages/core/tests/Unit/Option/OptionRepositoryTest.php` + `Integration/Option/OptionGroupTest.php` | 両方 | 定数ベースの option 読み書き仕様を移植。ユニット層はフェイク `OptionStorageInterface` 実装で、統合層は実 `wp_options` で検証 |
| 7 | `TestCase/Field/FieldTest.php` | `packages/core/tests/Unit/Form/FieldTest.php` | Unit | エラー設定 / クリアの仕様を移植 |
| 8 | `TestCase/Field/FieldSetTest.php` | — | — | 廃棄。FieldSet のシングルトン的インスタンス管理は DR-09(AbstractSingleton 廃止)で機構ごと消滅。フィールド集合の保持は `FormDefinition` のテストとして新規作成 |
| 9 | `TestCase/Field/Rule/RuleTraitTest.php` | `packages/core/tests/Unit/Form/Rule/RuleContractTest.php` | Unit | **空値スルー**(空値は Required 系以外を素通り)の仕様を移植。必須パラメータ欠落 / 型不一致の実行時検査は PHP 8.3 のコンストラクタ型宣言 + named arguments による静的保証に置換(`TypeError` の検証のみ残す)。`PHPUnit\Framework\Error\Notice` 依存の検証は廃棄 |
| 10 | `TestCase/Field/Rule/ChoiceRuleTest.php` | `Unit/Form/Rule/ChoiceRuleTest.php` | Unit | 選択肢一致・strict・multiple の全ケース移植 |
| 11 | `TestCase/Field/Rule/EmailRuleTest.php` | `Unit/Form/Rule/EmailRuleTest.php` | Unit | 正常 / 異常メールアドレスのデータプロバイダ**全件**移植 |
| 12 | `TestCase/Field/Rule/ExactLengthRuleTest.php` | `Unit/Form/Rule/`(05 文書の対応 Rule 名) | Unit | 文字数一致の境界値を移植(マルチバイト件を追加) |
| 13 | `TestCase/Field/Rule/RequiredIfRuleTest.php` | `Unit/Form/Rule/RequiredIfRuleTest.php` | Unit | 他フィールド参照の条件付き必須の真理値表を移植。FieldSet 経由参照は `ValidationContext` 経由に置換 |
| 14 | `TestCase/Field/Rule/RequiredUnlessRuleTest.php` | `Unit/Form/Rule/RequiredUnlessRuleTest.php` | Unit | 同上(裏条件) |
| 15 | `TestCase/Field/Rule/RetypeRuleTest.php` | `Unit/Form/Rule/RetypeRuleTest.php` | Unit | 再入力一致(strict 含む)を `ValidationContext` 経由で移植 |
| 16 | `TestCase/Field/Rule/UrlRuleTest.php` | `Unit/Form/Rule/UrlRuleTest.php` | Unit | 絶対 / 相対 / カスタムプロトコル URL のデータプロバイダ**全件**移植 |
| 17 | `TestCase/Form/FormTest.php` | `packages/core/tests/Unit/Form/FormHtmlTest.php` | Unit | checkbox / radio の value 属性・checked 判定の境界 10 ケース(空 value・同値設定等)を `FormHtml` 純関数のスナップショット検証として移植 |
| 18 | `TestCase/Form/FormChoiceTest.php` | `packages/core/tests/Unit/Form/FormHtmlTest.php` / `FieldViewTest.php` | Unit | 複数選択肢レンダリングの出力仕様を移植。カスタムレンダラ合成(3 層コールバック)の検証は機構廃止(DR-07)により `FieldView` の出力カスタマイズ仕様(05 文書)の検証に置換 |
| 19 | `TestCase/Log/LogTest.php` | `packages/core/tests/Unit/Log/LogTest.php` | Unit | チャンネル・スコープ・ロガー差し替え・vfsStream への書込検証を移植。シングルトン検証(get_instance)は `LoggerRegistry` の登録 / 解決検証に置換 |
| 20 | `TestCase/Log/Logger/SimpleLoggerTest.php` | `packages/core/tests/Unit/Log/SimpleLoggerTest.php` | Unit | 日付フォーマット・ファイル名・ディレクトリ分割・全レベル・コンテキスト展開を vfsStream で移植(PSR-3 **v3** シグネチャで書き直し) |
| 21 | `TestCase/Post/AbstractPostTest.php` | `packages/core/tests/Integration/Post/PostTypeTest.php` | Integration | CPT 自動登録・`get`/`get_id` 相当(`PostQuery`)・META 定数によるメタ読み書き(`MetaBag` 経由)・permalink / アーカイブリンクを移植。「デフォルト meta 挿入」は `MetaSchema(default:)` の仕様検証へ置換 |
| 21b | 同上(サムネイル解決) | `packages/core/tests/Integration/Post/PostTypeTest.php` | Integration | アイキャッチなし / アイキャッチあり / `tmswp/post/thumbnail_fallback` フィルタの 3 経路の解決を移植(GD 画像生成 + WP ファクトリの作法を踏襲)。ダミー画像は呼び出し側の `?? $dummyUrl` で表すため対象外。本文先頭 img の抽出はコアにも thumbnail 拡張にも持たない(03 文書 §5) |
| 22 | `TestCase/Tax/AbstractTaxTest.php` | `packages/core/tests/Integration/Tax/TaxonomyTest.php` | Integration | タクソノミー登録(object_type の文字列 / 配列 / PostType クラス名解決)・term meta 定数操作・term link を移植 |
| 23 | `TestCase/User/AbstractUserTest.php` | `packages/core/tests/Integration/User/UserRoleTest.php` | Integration | ロール登録・user meta 定数操作・author link を移植 |
| 24 | `bin/install-wp-tests.sh` | — | — | 廃棄(wp-env + wp-phpunit に置換) |
| 25 | `.travis.yml` / `.phpcs.xml.dist` / `Gruntfile.js` / `package.json` | — | — | 廃棄(qa.yml / php-cs-fixer / wp-env(@wordpress/env)に置換。i18n pot 生成は `wp i18n make-pot` を必要時に使用) |

### 8.2 旧 FW で未テストだった領域(新規テスト必須リスト)

旧 FW にテストが存在しない以下は、対応する機能文書の仕様から **TDD で新規作成**する(実装より先にテストを書くこと)。カバレッジ目標(4.8)の対象。

| 対象 | 層 | 最低限の検証項目 |
|---|---|---|
| `Model` / `QueryBuilder` / `TableDefinition` / `Migrator` | Unit + Integration | 全クエリが `wpdb->prepare` を通ること(生 SQL 流出の禁止)、dbDelta スキーマ生成、バージョン比較マイグレーション |
| `Form`(ライフサイクル) | Unit | バインド → 検証 → `FormStep` 遷移、CSRF 自動検証(Brain Monkey で wp_nonce モック) |
| `ConfirmToken` | Unit | HMAC 署名生成 / 検証、改ざん検知、期限切れ |
| `UploadedFile` / `TempFileStore` | Unit | トークン発行 / 取出し、**ユーザー入力からのパス再構築が不可能であること**(パストラバーサル試行の拒否) |
| `Kernel` / `Discovery` / `ComponentCache` | Unit + Integration | Attribute 走査、決定的ロード順、循環依存検出エラー、キャッシュ無効化(vfsStream) |
| `Container` | Unit | PSR-11 準拠(has/get、未登録時 `NotFoundExceptionInterface`) |
| `MetaBag` / `MetaSchemaRegistry` / `WpMetaStorage` | Unit + Integration | 型付きアクセサの変換規則、`has()`/`get()` 分離(DR-18)、`register_*_meta` 自動登録 |
| `View\Template` | Unit + Integration | パス解決(テーマ → プラグイン fallback)、`extract()` 不使用の変数受け渡し |
| `Support\Html` | Unit | 属性レンダリングのエスケープ |
| 各拡張パッケージ | 各文書に従う | thumbnail: 署名付き URL 検証 / settings-page: nonce / acf-bridge: 生成コードのドリフト検知 / ja-toolkit: 各モジュールのオプトイン動作 |

---

## 9. 開発環境セットアップ手順

前提ソフトウェア: PHP 8.3〜8.5(CLI、pcov 拡張推奨。lint は 8.3 で実行する)、Composer 2.7+、Node.js 18.12+(@wordpress/env の要件。CI は 24)、Docker(Desktop または Engine + Compose v2)、Git。

```bash
# 1. クローン
git clone git@github.com:tmswp/tmswp.git
cd tmswp

# 2. 依存インストール(vendor と vendor-bin/wp-tests の両方が入る)
composer install
#    → bamarni/composer-bin-plugin の allow-plugins 確認には yes と答える
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
| `composer install` 後に `vendor-bin/wp-tests/vendor` がない | allow-plugins で bamarni を拒否した。`composer config allow-plugins.bamarni/composer-bin-plugin true && composer bin wp-tests install` |
| `composer analyse` が scanDirectories エラー | 同上(vendor-bin 未インストール) |
| `wp-env start` が失敗 | Docker デーモン未起動。Docker Desktop を起動して再実行 |
| ポート 8888 が使用中 | `.wp-env.override.json` に `{"port": 8890}` を作成 |
| `test:coverage` で「No code coverage driver」 | pcov 未導入。`pecl install pcov` 後、php.ini に `extension=pcov` |
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
use TMSWP\Core\Tests\Support\UnitTestCase;

final class EmailRuleTest extends UnitTestCase
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
- [ ] `composer lint` / `fix` / `analyse` / `test:unit` / `test:integration` / `test` / `test:coverage` / `qa:fast` / `qa` / `monorepo:validate` が本書 1 章の表どおりに動作する
- [ ] `phpstan.neon.dist` が level max で、`packages/core/phpstan/extension.neon`(04 文書の FW 拡張)と szepeviktor/phpstan-wordpress を include している
- [ ] リポジトリに `phpstan-baseline*` が存在せず、qa.yml がその存在を fail にする
- [ ] `parameters.ignoreErrors` が空で、`reportUnmatchedIgnoredErrors: true`
- [ ] php-cs-fixer が `@PER-CS2x0`(+ `:risky`)基底と `declare_strict_types` / `final_class` / `strict_comparison` を含む 3.1 のルール全文で構成され、`composer lint` がクリーンに通る
- [ ] ユニットテストが PHPUnit 11・`tests/bootstrap-unit.php`(WP 定数を定義しない)で、WP / MySQL / Docker なしで全件パスする
- [ ] 全ユニットテストが `TMSWP\Core\Tests\Support\UnitTestCase` を継承している
- [ ] 統合テストが wp-env の `cli` コンテナ内で PHPUnit 9.6(vendor-bin 隔離)+ wp-phpunit で全件パスする
- [ ] `phpunit.unit.xml.dist` / `phpunit.integration.xml.dist` に 5 パッケージ分の testsuite が定義されている
- [ ] ユニット層カバレッジ(5 パッケージ合算)が 85% 以上で、`bin/coverage-check.php` が閾値未満を exit 1 にする
- [ ] qa.yml に lint / analyse / monorepo / rules-verify / unit(PHP 8.3 / 8.5)/ integration(PHP 8.3 / 8.5 × WP 7.0 / 最新版 = 4 セル。テストライブラリは各セルの WP と同じ系列)の全ジョブがある
- [ ] `npm ci` で package.json に固定した版の @wordpress/env が入り、composer スクリプト・qa.yml が wp-env を `node node_modules/@wordpress/env/bin/wp-env` のパスでだけ呼ぶ
- [ ] split.yml がタグ push で 7 リポジトリ(packages 5 + starters 2)を read-only リポジトリへ split し、同名タグを伝播する
- [ ] `monorepo-builder.php` が packages/ と starters/ を対象に validate / merge / release を構成している
- [ ] ルート composer.json の `replace` に全 5 パッケージが列挙されている
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
composer test:coverage                          # カバレッジゲート(>= 85)
composer monorepo:validate                      # パッケージ整合
act -W .github/workflows/qa.yml -l              # (任意) ワークフロー構文確認
```
