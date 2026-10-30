# 01 アーキテクチャ全体設計 — TMStack WordPress Framework

## 目的

TMStack WordPress Framework 全体の物理構成を確定する。すなわち、monorepo のディレクトリツリー、全パッケージの composer 名・名前空間・責務・runtime 依存、依存ポリシー、SemVer とリリースフロー(read-only split 配布)、PHP 8.3〜8.5 / WP 7.0 以上の対応範囲と WP API 下限棚卸し、グローバルヘルパポリシー、i18n 方針、利用側(テーマ・プラグイン)の標準ディレクトリレイアウトを、この文書だけで実装・構築できる粒度で定義する。

各モジュール(Kernel、Form、Model 等)の内部 API 仕様は本書のスコープ外であり、各担当文書が定義する。本書はそれらが収まる「器」と「配布の仕組み」を確定する。

## スコープ(対象パッケージ)

- monorepo 全体(リポジトリ構成、ルート composer.json、CI/リリースワークフローの構造)
- `tmswp/core` / `tmswp/thumbnail` / `tmswp/settings-page` / `tmswp/acf-bridge` / `tmswp/ja-toolkit` / `tmswp/testing` / `tmswp/starter-theme` / `tmswp/starter-plugin`のパッケージ境界とメタデータ
- 利用側プロジェクト(テーマ・プラグイン)の標準レイアウト規約

## 依存文書

| 文書 | 本書との関係 |
|---|---|
| Kernel / Discovery 設計書 | `Kernel::boot()` / `KernelConfig` の内部仕様。本書はブート API の正準形と配置のみ定義 |
| Meta / Option 設計書 | `register_post_meta` 等への自動登録仕様。本書は WP API 下限のみ棚卸し |
| QA・CI 設計書 | PHPStan / php-cs-fixer / PHPUnit / GitHub Actions の詳細。本書は composer スクリプト名とリポジトリ上の配置のみ定義 |
| AI 資料設計書(AGENTS.md / rules/) | rules/ の内容。本書は配置場所と配布経路のみ定義 |
| 各拡張パッケージ設計書 | thumbnail / settings-page / acf-bridge / ja-toolkit の内部仕様 |

---

## 1. 全体像

### 1.1 設計原則(本書での適用)

1. **コアは小さく、依存はゼロに近く**: `tmswp/core` の runtime 依存は `psr/log ^3` と `psr/container ^2` のみ。それ以外の機能(画像処理、設定画面、ACF 連携、日本語サイト対応)はすべて独立した拡張パッケージとし、composer で個別導入する。
2. **WordPress 標準を壊さない**: FW はテンプレート階層・テーマ構築手法に一切介入しない。テーマの `index.php` / `single-*.php` / `parts/` は素の WordPress として書ける。FW が提供するのはテンプレートから「呼ばれる」静的 API のみ。
3. **monorepo 開発・split 配布**: 開発は単一リポジトリで行い(横断変更・横断テストを 1 PR で完結)、配布はパッケージごとの read-only リポジトリへ自動 split して Packagist から SemVer で導入する。
4. **AI が迷わない構造**: パッケージ→名前空間→ディレクトリが 1:1:1 で対応し、利用側 `app/` のディレクトリ規約も全プロジェクトで同一。規約は AGENTS.md / rules/ として composer 経由で利用側プロジェクトへ届く。

### 1.2 パッケージ依存グラフ

```
                       ┌────────────────────┐
                       │   tmswp/core     │  runtime: psr/log ^3, psr/container ^2
                       └─────────┬──────────┘
          ┌───────────────┬─────┴──────┬──────────────┐
          │               │            │              │
 ┌────────┴───┐ ┌─────────┴──┐ ┌───────┴────┐ ┌───────┴────┐
 │ thumbnail  │ │settings-   │ │ acf-bridge │ │ ja-toolkit │
 │ (+ext-gd)  │ │page        │ │(ACF本体は  │ │            │
 │            │ │            │ │ 実行時検出)│ │            │
 └────────────┘ └────────────┘ └────────────┘ └────────────┘

 starters/theme・starters/plugin → tmswp/core を require する雛形(create-project 用)
 testing → tmswp/coreをrequireするテストの支援コード。各パッケージはrequire-devでだけ使う
```

- 拡張パッケージは `tmswp/core` のみに依存する。**拡張同士の依存は禁止**(必要になった場合は機能をコアへ昇格するか、共通部を切り出す設計判断を行う。安易な拡張間 require は不可)。
- `acf-bridge` は ACF 本体を composer require **しない**(ACF Pro は Packagist 未登録のため)。`class_exists('ACF')` による実行時検出とする(詳細は acf-bridge 設計書)。

---

## 2. monorepo 構成(ディレクトリツリー全体)

monorepo のリポジトリは `tmstack-io/tmswp` である。以下が正準ツリーである。

```
tmswp/                                  # monorepo ルート (tmstack-io/tmswp)
├── composer.json                         # 開発用ルート(§4.2。Packagist 非公開)
├── composer.lock
├── monorepo-builder.php                  # symplify/monorepo-builder 設定
├── phpstan.neon.dist                     # 全パッケージ横断の静的解析設定(詳細: QA・CI 設計書)
├── .php-cs-fixer.dist.php                # PER-CS 2.0 + strict_types(詳細: QA・CI 設計書)
├── phpunit.xml.dist                      # ユニット層(WP 非依存)テストスイート定義
├── phpunit.integration.xml.dist          # 統合層(wp-env + WP_TESTS_DIR)テストスイート定義
├── .wp-env.json                          # 統合テスト用 WordPress 環境定義
├── .editorconfig
├── .gitattributes / .gitignore
├── LICENSE                               # GPL-2.0-or-later(packages/*の各パッケージにも同じLICENSEを置く)
├── CHANGELOG.md                          # 版ごとの変更(§4.5のリリース手順で更新)
├── README.md
├── AGENTS.md                             # FW「開発者」向け AI 指示書(monorepo の歩き方)
├── docs/                                 # 設計書(本シリーズ)
├── bin/                                  # 開発補助スクリプト(split 検証等)
├── .github/
│   └── workflows/
│       ├── qa.yml                        # PHP 8.3 / 8.5 × WP 7.0 / 最新版マトリクス(詳細: QA・CI 設計書)
│       └── split.yml                     # read-only split 配布(§4.4)
├── packages/
│   ├── core/                             # tmswp/core
│   │   ├── composer.json
│   │   ├── README.md
│   │   ├── LICENSE                       # GPL-2.0-or-later(ルートのLICENSEと同じ本文)
│   │   ├── phpunit.xml.dist              # 単独で取り出したときのユニットテスト(composer test)
│   │   ├── .gitattributes                # 配布物から外すもの(§2.2)
│   │   ├── src/                          # TMSWP\Core\ → src/ (PSR-4)
│   │   │   ├── Kernel/                   # Kernel.php, KernelConfig.php, Discovery.php, DiscoveryFile.php, DiscoverCommand.php
│   │   │   ├── Container/                # Container.php (PSR-11 実装)
│   │   │   ├── Attribute/                # AsPostType.php, AsTaxonomy.php, AsUserRole.php, AsModel.php,
│   │   │   │                             #   AsHook.php, AsOptionGroup.php, Action.php, Filter.php,
│   │   │   │                             #   MetaSchema.php, OptionSchema.php, UseMetaStorage.php, WithMetaRevisions.php
│   │   │   ├── Post/                     # PostType.php, PostTypeDefinition.php, PostQuery.php
│   │   │   ├── Tax/                      # Taxonomy.php, TaxonomyDefinition.php, TermQuery.php
│   │   │   ├── User/                     # UserRole.php, UserRoleDefinition.php, UserQuery.php
│   │   │   ├── Meta/                     # MetaBag.php, ObjectType.php, MetaValueType.php, MetaStorageInterface.php,
│   │   │   │                             #   WpMetaStorage.php, MetaSchemaRegistry.php
│   │   │   ├── Option/                   # OptionAccess.php (trait), OptionRepository.php,
│   │   │   │                             #   OptionStorageInterface.php, WpOptionStorage.php
│   │   │   ├── Form/                     # Form.php, FormDefinition.php, FormResult.php, FormStep.php,
│   │   │   │                             #   Field.php, FieldView.php, FormHtml.php, Validator.php,
│   │   │   │                             #   ValidationContext.php, ConfirmToken.php, UploadedFile.php,
│   │   │   │                             #   TempFileStore.php, Rule/ (RuleInterface.php ほか Rule 群)
│   │   │   ├── Model/                    # Model.php, TableDefinition.php, QueryBuilder.php,
│   │   │   │                             #   Migrator.php, Connection.php
│   │   │   ├── Log/                      # Log.php, SimpleLogger.php, LoggerRegistry.php
│   │   │   ├── View/                     # Template.php, TemplateLocator.php
│   │   │   ├── Support/                  # Arr.php, Str.php, Html.php
│   │   │   ├── Hook/                     # Hook.php
│   │   │   ├── Exception/                # TMSWP\Core\Exception\ 配下(各文書が定義する例外を収載)
│   │   │   └── functions.php             # tms_* グローバルヘルパ(§6)
│   │   ├── phpstan/                      # FW 同梱 PHPStan 拡張(§3.4)
│   │   │   ├── extension.neon
│   │   │   └── src/                      # TMSWP\Core\PHPStan\ → phpstan/src/ (PSR-4)
│   │   ├── rules/                        # 利用側 AI 向け機能別レシピ(§2.1)
│   │   ├── languages/                    # tmswp-core.pot / tmswp-ja.po / tmswp-ja.mo / tmswp-ja.l10n.php(§7.2)
│   │   └── tests/
│   │       ├── Unit/                     # WP 非依存(Brain Monkey)
│   │       ├── Integration/              # wp-env + WP_TESTS_DIR
│   │       └── Support/                  # core自身のテストだけが使う支援コード
│   ├── thumbnail/                        # tmswp/thumbnail(LICENSE・phpunit.xml.dist・.gitattributesはcoreと同じ。以下の拡張も同じ)
│   │   ├── composer.json
│   │   ├── src/                          # TMSWP\Thumbnail\ → src/
│   │   ├── rules/
│   │   ├── languages/
│   │   └── tests/ (Unit/ + Integration/)
│   ├── settings-page/                    # tmswp/settings-page
│   │   ├── composer.json
│   │   ├── src/                          # TMSWP\SettingsPage\ → src/
│   │   ├── rules/
│   │   ├── languages/
│   │   └── tests/
│   ├── acf-bridge/                       # tmswp/acf-bridge
│   │   ├── composer.json
│   │   ├── src/                          # TMSWP\AcfBridge\ → src/
│   │   ├── rules/
│   │   ├── languages/
│   │   └── tests/
│   ├── ja-toolkit/                       # tmswp/ja-toolkit
│   │   ├── composer.json
│   │   ├── src/                          # TMSWP\JaToolkit\ → src/
│   │   ├── rules/
│   │   ├── languages/
│   │   └── tests/
│   └── testing/                          # tmswp/testing(各パッケージのテストの支援コード。§3.1)
│       ├── composer.json
│       ├── src/                          # TMSWP\Testing\ → src/
│       └── tests/
└── starters/
    ├── theme/                            # tmswp/starter-theme(composer create-project 用)
    │   ├── composer.json
    │   ├── .gitattributes                # node_modules/・.DS_Store・.github/を配布物から外す(§2.2)
    │   ├── style.css
    │   ├── functions.php                 # Kernel::boot() 1 行ブート
    │   ├── AGENTS.md                     # 利用側プロジェクト向け AI 指示書(vendor 内 rules/ を参照)
    │   ├── app/                          # §8.2 の標準レイアウト(スケルトン)
    │   ├── parts/
    │   ├── languages/
    │   └── index.php ほか WP 標準テンプレート
    └── plugin/                           # tmswp/starter-plugin
        ├── composer.json
        ├── plugin.php                    # create-project 後にリネームする本体ファイル
        ├── AGENTS.md
        ├── app/
        ├── templates/                    # Template::part のプラグイン同梱 fallback 置き場
        └── languages/
```

### 2.1 rules/ の配置と配布経路

- AI 向け機能別レシピ(rules/)は**各パッケージの `rules/` ディレクトリに収載し、composer 配布物に含める**。これにより利用側プロジェクトは `composer update` だけで最新のレシピを受け取る(`vendor/tmswp/core/rules/post-type.md` のように参照できる)。
- monorepo ルートの `AGENTS.md` は **FW 自体を開発する AI** 向け。スターター同梱の `AGENTS.md` は **FW を利用してサイトを作る AI** 向けで、`vendor/tmswp/*/rules/` への参照を含む。両者は別物である(内容は AI 資料設計書が定義)。
- 拡張パッケージの導入手順(利用者AIが最初に読むもの)は、コアの`rules/acf.md`・`rules/extensions.md`に集める。拡張パッケージだけの細部は、そのパッケージの`rules/`に置く(例: `packages/acf-bridge/rules/sync-check.md`)。配置の詳細はAI資料設計書(11-ai-docs.md §1.2・§6)が定める。

### 2.2 パッケージ内ディレクトリの不変条件

- PSR-4 ルートは常に `src/`。1 パッケージ = 1 名前空間ルート。`src/` 直下のサブディレクトリ = サブ名前空間。
- `tests/Unit/` は WP 関数に依存しない(Brain Monkey でスタブ)。`tests/Integration/` のみ WordPress のテストライブラリ(wp-env が提供する WP_TESTS_DIR)を使う。テスト名前空間は `TMSWP\<Pkg>\Tests\`(autoload-dev)。
- `languages/` には翻訳ファイル(gettext の .pot / .po / .mo と、.po から生成する .l10n.php)のみを置く(§7)。
- 配布物(splitリポジトリのタグのzipと、Composerのpathリポジトリの写し)から、開発用のファイルを各パッケージの`.gitattributes`の`export-ignore`で外す。packages/*は`tests/`(fixtureを含む)・`phpunit.xml.dist`・`.gitattributes`・`.DS_Store`・`.github/`(split.ymlが同期の前に置く、同期先でプルリクエストを閉じるワークフロー。§4.4)を外し、`src/`・`rules/`・`languages/`・`LICENSE`・`README.md`・`composer.json`(coreは`phpstan/`も)を配布する。starters/*は作ったプロジェクトがそのまま動くための`tests/`・`build/blocks/`・探索結果ファイル(`tmswp-discovery.php`)を外さず、`node_modules/`・`.DS_Store`・`.github/`だけを外す。Composerのpathリポジトリ(`symlink: false`)の写しが従うのは`.gitattributes`の`export-ignore`だけで、composer.jsonの`archive.exclude`は効かないため、除外は`export-ignore`で行う。
- 各パッケージは、splitで単独で取り出した状態で`composer install && composer test`が通る(§3.2の3)。`composer test`はそのパッケージの`phpunit.xml.dist`の`tests/Unit/`を走らせる。統合テスト(`tests/Integration/`)はmonorepoのルートのfixtureとwp-envを使うため、monorepoでだけ走らせる。

---

## 3. パッケージ一覧と依存ポリシー

### 3.1 全パッケージ表

| composer 名 | 名前空間ルート | type | 責務 | runtime 依存(php 以外) |
|---|---|---|---|---|
| `tmswp/core` | `TMSWP\Core\` | library | Kernel/DI コンテナ/Attribute 探索、PostType・Taxonomy・UserRole 宣言、meta/option 宣言層、Form+バリデーション、Model(独自テーブル)、Log(PSR-3)、Template::part 相当のテンプレートヘルパ、Support(Arr/Str/Html) | `psr/log ^3.0`, `psr/container ^2.0` |
| `tmswp/thumbnail` | `TMSWP\Thumbnail\` | library | 署名付き URL + `WP_Image_Editor` による動的サムネイル(timthumb 全面置換) | `tmswp/core ^X.Y`, `ext-gd *` |
| `tmswp/settings-page` | `TMSWP\SettingsPage\` | library | 管理画面設定ページ生成(コアの Form 層ベース、nonce 自動) | `tmswp/core ^X.Y` |
| `tmswp/acf-bridge` | `TMSWP\AcfBridge\` | library | acf-json を真実源とした型付きアクセサ生成(`app/Acf/*Fields.php`)、`AcfMetaStorage`、CI ドリフト検知 | `tmswp/core ^X.Y`(ACF 本体は実行時検出) |
| `tmswp/ja-toolkit` | `TMSWP\JaToolkit\` | library | 日本語サイト対応(日本語スラッグ・全角スペース検索正規化・wptexturize無効化)のオプトインモジュール群 | `tmswp/core ^X.Y`, `ext-mbstring *` |
| `tmswp/testing` | `TMSWP\Testing\` | library | 各パッケージと利用側のテストの支援コード(`UnitTestCase`・`IntegrationTestCase`・`UsesTempDirectories`・`RecordingLogger`・メモリのストレージ・`WpdbTestDouble`等)。require-devでだけ使う | `tmswp/core ^X.Y`, `phpunit/phpunit ^11.5`, `brain/monkey ^2.6`, `mockery/mockery ^1.6`, `psr/log ^3.0` |
| `tmswp/starter-theme` | `TmswpStarterTheme\`(create-projectでスラッグから生成した名前空間に書き換える) | project | `composer create-project`用テーマ雛形 | `tmswp/core ^X.Y` |
| `tmswp/starter-plugin` | `TmswpStarterPlugin\`(create-projectでスラッグから生成した名前空間に書き換える) | project | `composer create-project`用プラグイン雛形 | `tmswp/core ^X.Y` |

スターターの名前空間は、create-projectの`bin/setup.php`がスラッグから生成した名前空間に書き換える(実装計画§1.6。プラグインごとに名前空間が違うため、同じサイトの複数のプラグインでクラスが衝突しない。詳細はAI資料設計書§10)。

`^X.Y`(PATCHが0より大きい版では`^X.Y.Z`)は、版の最初のタスクで`Kernel::VERSION`と同じコミットで手で上げ、リリースの前の検査(§4.3)がタグの版との一致を確かめる。全パッケージのライセンスは**GPL-2.0-or-later**(WordPress互換。旧iwf2bと同一)。packages/*はそれぞれ`LICENSE`のファイルを持つ(splitの配布物にライセンスの本文が入るように)。スターターは`LICENSE`のファイルを置かず、composer.jsonの`license`だけで示す(create-projectで作ったプロジェクトは`bin/setup.php`が`proprietary`に書き換えるため)。

### 3.2 依存ポリシー(規範)

1. **コアの runtime 依存は `psr/log ^3` と `psr/container ^2` のみ**。これ以外の require 追加(symfony/*、illuminate/*、polyfill 類を含む)は禁止。PHP 拡張も require しない(`ext-json` は PHP 8.0+ で常時有効のため宣言不要。`ext-mbstring` は `suggest` に記載し、`Support\Str` のマルチバイト処理が必要とする旨を明示する。WordPress 実行環境では mbstring はホスティング標準であり、これを事実上のプラットフォーム前提とする。`ext-intl` も `suggest` に記載し、Form の `Url` ルールが国際化ドメイン名の変換(`idn_to_ascii()`)に使う旨を明示する。ext-intl が無い環境では `Url` のコンストラクタが `FormConfigException` を投げる)。
2. **拡張パッケージは各自 runtime 依存を持ってよい**。ただし次の条件を満たすこと:
   - 依存追加は当該拡張の設計書に「何を・なぜ」を記録する(本表の更新を伴う)。
   - WordPress プラグイン同梱で衝突しやすいライブラリ(グローバル関数を定義するもの、メジャーバージョンが乱立しているもの)を避ける。
   - `ext-gd` は `tmswp/thumbnail` のみが require する(旧 iwf2b はコアが ext-gd を要求していた。これを拡張へ移設する)。thumbnail は `WP_Image_Editor` 経由で処理するため Imagick 環境でも動作するが、最低保証として ext-gd を要求する。
3. **require-devはmonorepoルートに集約**する(phpstan、szepeviktor/phpstan-wordpress、php-stubs/wordpress-stubs、friendsofphp/php-cs-fixer、phpunit ^11、brain/monkey、bamarni/composer-bin-plugin、symplify/monorepo-builder等。確定リストはQA・CI設計書)。統合テスト用のPHPUnit 9.6とYoast PHPUnit Polyfillsはルートのrequire-devに置かず、`vendor-bin/wp-tests`に隔離する。WordPressのテストライブラリはComposerでは入れず、wp-envが提供するもの(WP_TESTS_DIR)を使う(QA・CI設計書§4.2)。各パッケージのcomposer.jsonのrequire-devは、split配布後に単体で`composer install && composer test`が成立する最小限に限る。テストの支援コードとPHPUnit・Brain Monkey・Mockeryは`tmswp/testing`の依存として入るため、各パッケージ(coreを含む)のrequire-devは`tmswp/testing`と、そのパッケージのテストだけが使うもの(coreの`mikey179/vfsstream`・`phpstan/phpstan`)である。ルートと同じ依存の版の制約は、monorepo-builderのvalidateで乖離を検出する。coreのrequire-devの`tmswp/testing`は`tmswp/core`をrequireするが、splitしたcoreではrootパッケージ(`dev-main`をbranch-aliasで`X.Y.x-dev`とみなす)がこれを満たす。
4. **WordPress 本体・ACF 等のプラグインを composer 依存に含めない**。WP 関数はホスト環境が提供する前提とし、静的解析は wordpress-stubs で行う。統合テスト環境の WordPress と ACF(wordpress.org の無料版 `advanced-custom-fields`。版を固定した zip)は `.wp-env.json` で導入する(QA・CI 設計書 §4.7)。

### 3.3 同一サイト内の複数インストール共存規約

テーマとプラグインがそれぞれ `vendor/` に tmswp/* を同梱するケース(要件 5)を次のとおり扱う:

- PHP のクラスは最初にオートロードされた定義が勝つ(後続の vendor/ の同名クラスはロードされない)。したがって**同一サイト内の全 TMSWP 利用コンポーネントは tmswp/* の同じ版を同梱することを必須**とする。`Kernel::boot()` は、読み込まれたすべての vendor の installed.php にある tmswp/core のリリース版を `Kernel::VERSION` と照合し、食い違いを `KernelException` にする(dev 版は照合の対象外。Kernel 設計書 §3.9)。
- `Kernel::boot()` はコンポーネント(テーマ・各プラグイン)ごとに 1 回ずつ呼ばれる設計であり、複数回ブートは正常系である(多重ブートの整合は Kernel 設計書が定義)。
- 拡張パッケージ・ログのチャンネル・ラベル言語の既定は、Kernel ごとに `KernelConfig` で宣言する(`extensions`・`logChannels`・`labelLanguage`)。テーマとプラグインがそれぞれ同じ拡張を登録しても、互いの設定を上書きしない(Kernel 設計書 §3.2・§3.10、View/Support/Log 設計書 §5)。
- 名前空間のプレフィックス化(strauss 等)は行わない(実装計画 §1.16)。
- 旧 iwf2b(`Iwf2b\` / `iwf_*`)とは名前空間・ヘルパ接頭辞が完全に異なるため、同一サイト内での新旧共存(例: テーマは TMSWP、既存プラグインは iwf2b)に支障はない。

**psr/container・psr/log の利用条件**: tmswp/core は psr/container ^2.0 と psr/log ^3.0 の宣言を前提に `Container`・`LoggerRegistry` 等を実装する。PHP の interface は最初に読み込んだ定義で固定されるため、**他のプラグインや WP-CLI の phar が、古い psr/container(1.x)・psr/log(1.x・2.x)を TMSWP より先に読み込む構成では動かない**。`Kernel::boot()` はこれを検出し、Fatal error の代わりに、その interface を定義したファイルのパスと要求する版を含む `KernelException` で止める(Kernel 設計書 §3.8)。

- WP-CLI の phar は psr/container 1.0 を含み、`--require` 等で TMSWP の Composer のオートローダを phar より前に登録すると(phar は `--require` の後に自身のオートローダを先頭に登録する)、phar 側の interface が読まれる。メッセージのファイルが `phar://` で始まれば、これが原因である。
- 検出されたときの対処: (1) TMSWP の Composer のオートローダを、テーマ・プラグインの読み込み時(functions.php・プラグイン本体ファイル)に登録する(WP-CLI の `--require` や `wp-cli.yml` の `require` で早く登録しない)。(2) 古い版を読み込む他のプラグインを更新するか、そのプラグインと併用しない。

### 3.4 FW 同梱 PHPStan 拡張の配置

DR-05 の「FW 同梱の PHPStan 拡張」(MetaSchema からの型ナローイング等)は次のとおり配置する:

- 物理配置: `packages/core/phpstan/`(`extension.neon` + `src/`)。
- 名前空間: `TMSWP\Core\PHPStan\`。autoload は core composer.json の psr-4 に **別エントリ**として登録する(`"TMSWP\\Core\\PHPStan\\": "phpstan/src/"`)。runtime コードからこの名前空間への参照は禁止(PHPStan 実行時のみロードされる)。
- 有効化: core composer.json の `extra.phpstan.includes` に `phpstan/extension.neon` を記載し、利用側が `phpstan/extension-installer` を導入していれば自動で有効になる。手動の場合は利用側 phpstan.neon で `includes: [vendor/tmswp/core/phpstan/extension.neon]`。
- `phpstan/phpstan` 本体への依存は require-dev のみ。runtime require には一切現れない(依存ポリシー 1 と矛盾しない)。
- 拡張の中身(ナローイングルールの仕様)は Meta/Option 設計書および QA・CI 設計書が定義する。

### 3.5 tmswp/core の composer.json(正準形)

```json
{
    "name": "tmswp/core",
    "description": "TMStack WordPress Framework - Core",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "psr/log": "^3.0",
        "psr/container": "^2.0"
    },
    "suggest": {
        "ext-intl": "Required by TMSWP\\Core\\Form\\Rule\\Url to validate internationalized domain names",
        "ext-mbstring": "Required for multibyte string handling in TMSWP\\Core\\Support\\Str",
        "tmswp/thumbnail": "Signed dynamic thumbnail URLs",
        "tmswp/acf-bridge": "Typed accessors generated from acf-json"
    },
    "autoload": {
        "psr-4": {
            "TMSWP\\Core\\": "src/",
            "TMSWP\\Core\\PHPStan\\": "phpstan/src/"
        },
        "files": ["src/functions.php"]
    },
    "require-dev": {
        "mikey179/vfsstream": "^1.6.12",
        "phpstan/phpstan": "^2.1",
        "tmswp/testing": "^0.10"
    },
    "autoload-dev": {
        "psr-4": {"TMSWP\\Core\\Tests\\": "tests/"}
    },
    "scripts": {
        "test": "phpunit"
    },
    "extra": {
        "phpstan": {"includes": ["phpstan/extension.neon"]},
        "branch-alias": {"dev-main": "0.10.x-dev"}
    },
    "minimum-stability": "stable",
    "config": {"sort-packages": true}
}
```

注意点:

- PSR-4 で `TMSWP\Core\PHPStan\` を先に書いても、composer は最長一致で解決するため `src/` 側と衝突しない。
- PHPStan 拡張の設定ファイルは `phpstan/extension.neon` の 1 枚だけを置く(§3.4)。`extra.phpstan.includes` とルートの phpstan.neon.dist はどちらもこのファイルを直接指す。
- `branch-alias`は`<major>.<minor>.x-dev`の形で、版の最初のタスクで`Kernel::VERSION`・制約と同じコミットで手で上げ、リリースの前の検査(§4.3)がタグの版との一致を確かめる。リリースの後に次の版へ自動で上げない。branch-aliasを持つのはpackages/*のcomposer.jsonだけで、スターターには無い。上の値は`Kernel::VERSION`が0.10.0の間のもので、`tmswp/core`・`tmswp/testing`の制約(`^0.10`)はこの版を満たす。
- `scripts.test`は、パッケージに置いた`phpunit.xml.dist`(`tests/Unit/`)を走らせる。coreの`phpunit.xml.dist`のbootstrap(`tests/bootstrap.php`)は、Brain MonkeyのPatchworkが`phar://`のストリームを差し替える前にPHPStanのテストのコンテナを作る(PHPStan 2.3のpharは、差し替えた後に初めて読み込むと、同じファイルを別のパスで二度読み込んでクラスの再宣言で止まるため)。
- `files` オートロードは `src/functions.php` の 1 ファイルのみ(§6)。拡張パッケージがヘルパを持つ場合も同形式(各 1 ファイル)とする。
- 拡張パッケージのcomposer.jsonは本形式から`require`に`"tmswp/core": "^X.Y"`を加え、`require-dev`を`"tmswp/testing": "^X.Y"`だけにし、psr-4・phpstanエントリを各自のものに差し替えた形とする(thumbnailのみ`"ext-gd": "*"`を追加)。`tmswp/testing`は`require`に`tmswp/core`・PHPUnit・Brain Monkey・Mockery・psr/logを持ち、`require-dev`を持たない。
- スターターの composer.json は §8.4 に正準形を示す。

---

## 4. バージョニングとリリースフロー

### 4.1 SemVer 規約

- 全パッケージ **lockstep バージョニング**(常に同一バージョン番号でリリース)。`tmswp/core 1.3.0` と `tmswp/thumbnail 1.3.0` が同時に出る。パッケージ個別のバージョン進行は行わない(利用側の「揃える」運用(§3.3)を単純化するため)。
- バージョン番号の意味:
  - **MAJOR**: 公開 API の破壊的変更(クラス・メソッドシグネチャの変更/削除、Attribute 引数の非互換変更、探索結果ファイル(`tmswp-discovery.php`)の形式の非互換変更(利用側で `wp tmswp discover` による再生成を必須とする)、生成コード(`app/Acf/*Fields.php`)の再生成を必須とする変更)。
  - **MINOR**: 後方互換のある機能追加。PHP / WP の下限引き上げも MINOR で行ってよい(下限引き上げは CHANGELOG に必ず明記)。
  - **PATCH**: 後方互換のあるバグ修正のみ。
- **公開 API の定義**: `@internal` PHPDoc が付いていない public クラス・public メソッド・グローバルヘルパ・Attribute・composer スクリプト名。protected メンバは基底クラス(`PostType`、`Taxonomy`、`UserRole`、`Model`、`Hook`、`Form` 関連)の継承契約に含まれる場合のみ公開 API とみなす(オプショングループは backed enum + `OptionAccess` trait のため継承契約を持たない)。
- 初版は `v0.1.0`。0.x の間は MINOR が破壊的変更を含み得る(SemVer 0.x 慣行)。実案件 1 件での安定運用確認をもって `v1.0.0` を切り、以後上記規約を厳守する。
- 旧 iwf2b が dev-master 運用で 15 案件が 8 世代のコミットに分散した反省から、**利用側プロジェクトの dev-main 直接参照を禁止**する(rules/ に明記)。利用側は常に `"tmswp/core": "^1.0"` のようにキャレット制約で導入する。

### 4.2 monorepo ルート composer.json

ルート composer.json は開発専用で Packagist には公開しない。要点:

```json
{
    "name": "tmswp/tmswp",
    "description": "TMStack WordPress Framework - development monorepo (not for production use)",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "psr/log": "^3.0",
        "psr/container": "^2.0",
        "ext-gd": "*"
    },
    "require-dev": {
        "phpstan/phpstan": "...",
        "szepeviktor/phpstan-wordpress": "...",
        "php-stubs/wordpress-stubs": "...",
        "friendsofphp/php-cs-fixer": "...",
        "phpunit/phpunit": "^11",
        "brain/monkey": "...",
        "bamarni/composer-bin-plugin": "...",
        "symplify/monorepo-builder": "^12"
    },
    "replace": {
        "tmswp/core": "self.version",
        "tmswp/thumbnail": "self.version",
        "tmswp/settings-page": "self.version",
        "tmswp/acf-bridge": "self.version",
        "tmswp/ja-toolkit": "self.version",
        "tmswp/testing": "self.version"
    },
    "autoload": {
        "psr-4": {
            "TMSWP\\Core\\": "packages/core/src/",
            "TMSWP\\Core\\PHPStan\\": "packages/core/phpstan/src/",
            "TMSWP\\Thumbnail\\": "packages/thumbnail/src/",
            "TMSWP\\SettingsPage\\": "packages/settings-page/src/",
            "TMSWP\\AcfBridge\\": "packages/acf-bridge/src/",
            "TMSWP\\JaToolkit\\": "packages/ja-toolkit/src/"
        },
        "files": ["packages/core/src/functions.php"]
    },
    "scripts": {
        "lint": "...", "fix": "...", "analyse": "...", "test": "...", "qa": "..."
    }
}
```

- ルートのcomposer.jsonは手で維持する。`require`・`replace`・`autoload`・`autoload-dev`はpackages/*のcomposer.jsonに合わせ、依存の版の制約がパッケージと食い違わないことを`composer monorepo:validate`で検査する。`monorepo-builder merge`は使わない(starters/*のautoloadと、tmswp/testingのrequireのPHPUnit・Brain Monkey・Mockeryをルートの`autoload`・`require`に入れるため。QA・CI設計書§1.1)。
- `replace` により monorepo 内でのパッケージ相互解決に path repository を使わない(単一 vendor/ で完結)。
- `tmswp/testing`の`src/`(`TMSWP\Testing\`)は、ルートでは`autoload`ではなく`autoload-dev`に置く(テストの支援コードで、FWのランタイムのコードではないため)。各パッケージの`tests/`(`TMSWP\<Pkg>\Tests\`)も`autoload-dev`に置く。
- 統合テスト用の PHPUnit 9.6 と Yoast PHPUnit Polyfills は `bamarni/composer-bin-plugin` で `vendor-bin/wp-tests` に隔離し、ルートの require-dev には置かない。WordPress のテストライブラリは wp-env が提供するもの(WP_TESTS_DIR)を使う(QA・CI 設計書 §4.2)。
- `scripts` の中身(lint/fix/analyse/test/qa)は QA・CI 設計書が定義する。コマンド名はこの 5 つで確定であり、人間・CI・AI が同一コマンドを使う。
- starters/はルートのautoloadに入れない(利用側雛形でありFWのコードではない)。CIではcreate-project相当のシナリオテストで検証する(QA・CI設計書)。

### 4.3 monorepo-builder の利用範囲

`symplify/monorepo-builder ^12`を次の2用途に限定して使う(`merge`は使わない。§4.2):

1. **validate**:パッケージ間の依存バージョン矛盾、ルートとの乖離検出(CIで実行)。
2. **releaseワーカー**: `vendor/bin/monorepo-builder release vX.Y.Z`で、monorepo-builder.phpに登録した`tools/release/`の次の2つのワーカーだけを順に実行する(手順は`docs/RELEASING.md`)。monorepo-builderが既定で持つワーカー(`TagVersionReleaseWorker`・`PushTagReleaseWorker`)は`MBConfig::disableDefaultWorkers()`で外し、標準のワーカーは1つも登録しない。版の書き換え、mainへのcommit・push、リリースの後の次の版(`^X.(Y+1)`・`X.(Y+1).x-dev`)への引き上げは行わない。mainへの反映は、このコマンドではなく、PR(`rewrite/v0`→`main`)のマージでユーザーの確認を経て行う。
   1. リリースの前の検査(`CheckReleaseReadinessReleaseWorker`): 次のどれか1つでも満たさなければ、満たさない項目をすべて並べた例外で止まり、タグを作らない。今のブランチが`main`である。originにmainのブランチがあり、`git fetch --no-tags origin +refs/heads/main:refs/remotes/origin/main`で取り直した`origin/main`と、HEADが同じコミットである(設定のfetchのrefspecと、手元に残った古い追跡refには頼らない)。作業ツリーが空である(追跡していないファイルも無い)。タグ`vX.Y.Z`が手元にも`origin`にも無い。`Kernel::VERSION`、packages/*とstarters/*のcomposer.jsonの`require`・`require-dev`のtmswp/*の制約とbranch-alias、examples/sample-siteのcomposer.jsonのtmswp/*の制約とpathリポジトリの`versions`が、タグの版と一致する(制約は`^X.Y`、PATCHが0より大きければ`^X.Y.Z`。branch-aliasは`X.Y.x-dev`)。CHANGELOG.mdに`## vX.Y.Z — 題名`の見出しがある。
   2. 注釈付きのタグ(`CreateAnnotatedTagReleaseWorker`): `git tag -a vX.Y.Z -m vX.Y.Z`でHEADに注釈付きのタグを作り、`git push origin refs/tags/vX.Y.Z`でそのタグだけを送る(手元のほかのタグは送らない)。gitのコマンドが失敗すれば例外で止まる。

版(`Kernel::VERSION`・制約・branch-alias・sample-siteの版・packages/*のrules/とREADME.mdの設計文書へのリンクの版)は、版の最初のタスクで1つのコミットで手で上げ、リリースの道具は書き換えない。

標準のワーカーを使わないのは、`TagVersionReleaseWorker`がmainへのpushの失敗を握りつぶしてタグを作り(origin/mainに無いコミットを指すタグが公開されうる)、`PushTagReleaseWorker`が手元のすべてのタグを送り、`AddTagToChangelogReleaseWorker`の見出しの形がCHANGELOG.mdの見出しと違うためである。版をリリースの後に自動で上げず、`Kernel::VERSION`と制約を同じコミットで上げるのは、`Kernel::VERSION`を基準にするCIのジョブ(Starter Projects・Sample Site QA)が、両者の食い違ったコミットで失敗するためである。

### 4.4 read-only split 配布

```
開発者                monorepo (tmstack-io/tmswp)   split リポジトリ                       Packagist
  │                         │                              │                                   │
  │ merge PR / tag vX.Y.Z   │                              │                                   │
  ├────────────────────────>│                              │                                   │
  │                         │ GitHub Actions split.yml     │                                   │
  │                         │  packages/core      ────────>│ tmstack-io/tmswp-core             │
  │                         │  packages/thumbnail ────────>│ tmstack-io/tmswp-thumbnail        │
  │                         │  ...(計8 split)              │ ...                               │
  │                         │  (subtree push + tag 複製)   │                                   │
  │                         │                              │ webhook ─────────────────────────>│ tmswp/core 等を更新
```

- 開発はmonorepo(`tmstack-io/tmswp`)で行う。split対象はpackages/配下6 + starters/配下2の**計8リポジトリ**で、送り先は`tmstack-io/tmswp-{パッケージ名}`(tmswp-core、tmswp-acf-bridge、tmswp-settings-page、tmswp-thumbnail、tmswp-ja-toolkit、tmswp-testing、tmswp-starter-theme、tmswp-starter-plugin)である。Packagistの名前は`tmswp/*`のまま。
- splitリポジトリは読み取り専用として運用する。IssueとWikiを無効にし、リポジトリの説明文でmonorepoへ案内する。誤って送られたプルリクエストは、同期先で動くワークフローが自動で閉じ、monorepoへ案内する(文面は英語)。このワークフローの正本はmonorepoの`.github/split/close-pull-request.yml`で、split.ymlが同期の前に各パッケージの`.github/workflows/close-pull-request.yml`へ写す(monorepoのpackages/*・starters/*には置かない)。`pull_request_target`で動き、プルリクエストのコードをcheckoutせず、権限は`pull-requests: write`だけである。配布物には入れない(各パッケージの`.gitattributes`の`/.github export-ignore`)。アーカイブは使わない(アーカイブしたリポジトリにはsplitが書き込めないため)。
- 送り先のリポジトリの作成と、送り先への書き込み権限だけを持つトークン(`SPLIT_ACCESS_TOKEN`。fine-grained PAT。対象は送り先の8リポジトリ(`tmstack-io/tmswp-*`)だけ。権限はContents: Read and writeとWorkflows: Read and writeだけ)の登録は、公開の作業で行う。
- 実装: `.github/workflows/split.yml`で`danharrin/monorepo-split-github-action`のv2.4.5をコミットのSHAで固定して使用する。`SPLIT_ACCESS_TOKEN`が空なら、ジョブは同期せずに失敗する。同じ同期先への同期はジョブの`concurrency`(同期先ごとのグループ。先の実行を取り消さず、後の実行は`queue: max`で待たせる)で並行させない(`10-quality.md` §5.3。同等機能のactionへの差し替えはPATCH扱いで可。ただし「mainへのpushでHEADを、タグpushで同名タグを各splitリポジトリへ複製する」挙動は不変条件)。
- ワークフロー(`10-quality.md` §5.2 の完全版と同じ):

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

- Packagistにはsplitリポジトリ8件を`tmswp/core`等の名前で登録し、GitHub webhookで自動更新する。monorepo本体は登録しない。

### 4.5 ブランチ・タグ運用

| 対象 | 規約 |
|---|---|
| `main` | 次期リリースの開発ブランチ。常にgreen(composer qaが通る)。splitにより各splitリポジトリの`main`と同期。mainへの反映はPRのマージで行い、直接pushしない(v0.9.0の公開までは`rewrite/v0`→`main`のPR) |
| トピックブランチ | `feature/<topic>` / `fix/<topic>`。main へ squash merge |
| タグ | `vX.Y.Z`の注釈付きのタグ。origin/mainと同じコミットにのみ打つ。monorepo-builder release経由でのみ作成し、そのタグだけをpushする(`CreateAnnotatedTagReleaseWorker`。§4.3) |
| メンテナンスブランチ | メジャーが進んだ後の旧系列修正用に `1.x` 等を必要時に作成(先行作成しない) |
| ホットフィックス | 最新タグから `fix/` ブランチ → main へ merge → PATCH リリース。旧系列が必要な場合のみメンテナンスブランチへ cherry-pick |
| リリース手順 | (1) `rewrite/v0`等の開発のブランチで`composer qa` greenを確認 → (2) CHANGELOG.mdのUnreleasedを`## vX.Y.Z — 題名`に改めるリリースの準備のコミットを置く(`Kernel::VERSION`・制約・branch-alias・sample-siteの版は、版の最初のタスクで上げ済み) → (3) ユーザーの確認を経て、PR(`rewrite/v0`→`main`)をマージする(mainへの反映はPRのマージだけで行う) → (4) mainをcheckoutし、`vendor/bin/monorepo-builder release vX.Y.Z --dry-run`の後、ユーザーの確認を経て`vendor/bin/monorepo-builder release vX.Y.Z`(リリースの前の検査に通れば、注釈付きのタグを作ってそのタグだけをpushする。§4.3) → (5) split.ymlがタグを複製 → (6) Packagist反映を確認。手順の詳細は`docs/RELEASING.md`。`Kernel::VERSION`がタグの版と違うと、リリースの前の検査が止める(食い違ったまま公開すると、そのタグのtmswp/coreを入れたサイトはboot時の版の照合(Kernel設計書§3.9)で起動しない) |

---

## 5. PHP 8.3〜8.5 / WordPress 7.0 以上の対応範囲

### 5.1 PHP 8.3〜8.5

対応範囲は PHP 8.3〜8.5(DR-02)。FW は次の言語機能を前提とし、いずれも下限の 8.3 で使える:

| 言語機能 | 必要 PHP | FW での用途 |
|---|---|---|
| Attribute | 8.0 | `#[AsPostType]` 等の宣言・探索(DR-04) |
| named arguments | 8.0 | `KernelConfig::theme(appDir: ..., appNamespace: ...)` 等の正準ブート API |
| constructor promotion / match / nullsafe | 8.0 | 全域 |
| enum | 8.1 | `FormStep`、`Meta\ObjectType` |
| readonly プロパティ | 8.1 | Definition 系・Config 系の不変オブジェクト |
| 第一級 callable 構文 | 8.1 | フック登録・Rule 合成 |
| readonly クラス | 8.2 | `KernelConfig`、`FormResult` 等の値オブジェクト全体 readonly 化 |
| DNF 型・trait 内定数 | 8.2 | 必要箇所で使用可 |
| **型付きクラス定数** | **8.3** | クラス定数の型宣言(`const string NAME = ...`) |
| **`#[\Override]`** | **8.3** | 親クラス・インターフェースのメソッドを上書きするメソッドへの付与 |

CI は PHP 8.3 / 8.5 でテストし、PHPStan は 8.3〜8.5 を解析範囲とする(DR-12)。8.4 以降の専用構文(プロパティフック、非対称可視性、パイプ演算子等)は**使用禁止**(下限 8.3 でパースできること)。

### 5.2 WordPress 7.0 以上

対応範囲は WordPress 7.0 以上(DR-02)。FW が使う WP API はすべて 7.0 より前に導入済みであり(§5.3)、7.0 を下限とすれば引数互換の心配なく使える。導入が比較的新しく、FW の機能が前提とする API は次の 3 つ:

1. `register_post_meta()` の `revisions_enabled` 引数(**6.4**)— `#[WithMetaRevisions]`(プレビュー時メタ保存)はリビジョンへのメタ保存機構の上に実装する。
2. `wp_trigger_error()`(**6.4**)— Log(SimpleLogger)がファイルの書き込み・ローテーションに失敗したときの初回の通知(07-support)に使用。`WP_DEBUG` が偽なら WordPress は何も出さない。Discovery の探索失敗と Container の循環依存は通知ではなく、`DiscoveryException`・`CircularDependencyException` として投げる(02-kernel)。
3. `wp_admin_notice()`(**6.4**)— Migrator のテーブル定義と DB の食い違いの通知(06-model)、acf-bridge のドリフト検知通知等の管理画面表示に使用。

### 5.3 WP API 下限棚卸し表

FW 各機能が使う主要 WP API と導入バージョンの棚卸し。「★」は FW の機能が前提とする、導入が比較的新しい API。

| FW 機能(担当文書) | WP API / 引数 | 導入 WP | 用途・備考 |
|---|---|---|---|
| Kernel | `add_action` / `add_filter` | 1.2 / 0.71 | フック整合ブート(DR-04) |
| Log | `wp_trigger_error()` | **6.4 ★** | Log の書き込み・ローテーションの失敗の初回の通知 |
| Kernel / Migrator / acf-bridge | `wp_admin_notice()` | **6.4 ★** | 管理画面通知の標準 API |
| Kernel(プラグインモード) | `register_activation_hook()` | 2.0 | 有効化時の処理(`rewrite_rules` の削除と `tmswp/activated` の発火。02-kernel §3.4) |
| PostType | `register_post_type()` の `show_in_rest` | 4.7(REST コントローラは 5.0) | Gutenberg 対応 CPT |
| PostType | `register_post_type()` の `template` / `template_lock` | 5.0 | ブロックテンプレート指定 |
| Taxonomy | `register_taxonomy()` の `default_term` | 5.5 | デフォルトターム宣言 |
| UserRole | `add_role()` / `remove_role()` | 2.0 | ロール宣言 |
| Meta | `register_post_meta()` / `register_term_meta()` | 4.9.8 | スキーマ自動登録(DR-05) |
| Meta(ユーザー) | `register_meta('user', ...)` | 4.6(現行引数形式) | **注: `register_user_meta()` という関数は WP コアに存在しない**。ユーザーメタは `register_meta('user', ...)` で登録する(Meta 設計書はこの API を使うこと) |
| Meta | `register_meta()` の `default` 引数 | 5.5 | `#[MetaSchema(default: ...)]` の WP 側反映 |
| Meta | `show_in_rest` の `schema`(array/object 型) | 5.3 | 複合型メタの REST スキーマ |
| Meta / `#[WithMetaRevisions]` | `register_post_meta()` の `revisions_enabled` | **6.4 ★** | プレビュー・リビジョンへのメタ保存 |
| Meta(保存タイミング) | `wp_after_insert_post` フック | 5.6 | メタ確定後の後処理フック |
| Form | `wp_create_nonce` / `wp_verify_nonce` | 2.0.3 | CSRF 自動埋込み・自動検証(DR-07) |
| Form / TokenSigner | `wp_salt()` | 2.5 | 確認トークン・送信トークンの HMAC 署名鍵の導出 |
| Form | `wp_unslash()` | 3.6 | リクエスト値の正規化 |
| Form | `nocache_headers()` | 2.0 | フォームのあるページをページキャッシュから外す(`DONOTCACHEPAGE` の定義と併用) |
| Form / JtiStore | `$wpdb->prepare()` の `%i` プレースホルダ | **6.2 ★** | 送信トークンの jti を options テーブルに `INSERT IGNORE` で原子的に記録する(`add_option()` は読み取りと挿入が非アトミックなため使わない) |
| Form / TempFileStore | `wp_upload_dir()` | 2.0 | 一時保管ディレクトリの解決 |
| Form / TempFileStore | `sanitize_file_name()` | 2.1 | 保管するファイルのクライアント名の無害化 |
| Form / TempFileGc | `wp_schedule_event()` / `wp_clear_scheduled_hook()` の `$wp_error` 引数 | 5.7 | 期限切れの一時ファイルを WP-Cron で掃除する。スケジュールと解除の失敗を `WP_Error` で受けて例外にする |
| Form / StoredFile | `wp_unique_filename()` | 2.5 | `moveTo()` の移動先のファイル名の衝突回避 |
| Form / StoredFile | `media_handle_sideload()` | 2.6 | `toAttachment()` の添付ファイル化 |
| Form / FileExtension | `wp_check_filetype_and_ext()` | 3.0 | アップロードされたファイルの中身と拡張子の照合 |
| Form / MaxFileSize | `size_format()` | 2.3 | エラーメッセージの上限サイズの表示 |
| Model / QueryBuilder | `$wpdb->prepare()` の `%i` プレースホルダ | **6.2 ★** | テーブル名・カラム名の識別子エスケープ。旧 FW の生 SQL 連結(SQL インジェクション源)の根治に必須 |
| Model / Migrator | `dbDelta()` | 1.5 | スキーマ宣言からのマイグレーション(`wp tmswp migrate`) |
| Model / Connection | `$wpdb` 各メソッド | 0.71〜 | 全クエリが prepare 経由(DR-08) |
| View / Template | `get_template_part()` の `$args` 引数 | 5.5 | `Template::part()` のデータ受け渡し。extract() 禁止(規約)の代替 |
| View / Template | `load_template()` の `$args` 引数 | 5.5 | プラグイン同梱テンプレート fallback の描画 |
| View / Template | `locate_template()` | 2.7 | 子テーマ→親テーマ→プラグイン同梱の解決順 |
| i18n | `determine_locale()` | 5.0 | 翻訳ファイルのロケール解決(§7) |
| i18n | `load_textdomain()` | 1.5 | パッケージ翻訳のロード(WP 6.5+ は同名 `.l10n.php` を自動優先ロードするが、これは WP 内部挙動であり FW から新規 API は呼ばない。§7.2) |
| thumbnail 拡張 | `wp_get_image_editor()` / `WP_Image_Editor` | 3.5 | GD/Imagick 抽象。timthumb 置換の中核 |
| settings-page 拡張 | `add_menu_page()` / `add_submenu_page()` | 1.5 | メニュー登録 |

**結論**: 上表の最大要求は 6.4(`revisions_enabled` / `wp_trigger_error` / `wp_admin_notice`)で、すべて下限の WP 7.0 に含まれる。7.0 未満のための条件分岐・フォールバックは**書いてはならない**(下限未満は Kernel がブート時に明示エラーで拒否する。検出方法は Kernel 設計書)。

### 5.4 上限互換(forward compatibility)に関する注意

- CI は WP の最新版でもテストする(DR-12)。**init フックより前の翻訳ロード・翻訳関数呼び出しは `_doing_it_wrong` 通知を出す**(WP 6.7 で入った just-in-time translation loading。下限の 7.0 を含む全対応版の挙動)。FW の翻訳ロードとラベル生成は必ず init 以降に行う(§7.3。実装拘束は Kernel / PostType 設計書に課す)。
- PHP 8.4 の暗黙 nullable 非推奨(`function f(Type $x = null)` 形式)に該当するシグネチャを書かない(明示 `?Type` を使う)。PHP 8.5 で非推奨の書き方(非正準のキャスト名 `(integer)` / `(boolean)` / `(double)` 等)も使わない。

---

## 6. グローバルヘルパポリシー(tms_*)

### 6.1 規範

1. グローバル関数の接頭辞は **`tms_`**(DR-01)。これ以外の接頭辞・無接頭辞のグローバル関数定義は全パッケージで禁止。
2. 定義ファイルはパッケージごとに 1 ファイル: コアは `packages/core/src/functions.php`、拡張は `packages/<pkg>/src/functions.php`。composer `autoload.files` でロードする。
3. ヘルパは `function_exists` ガードで囲まずに定義する。同名の関数が先に定義されていれば、PHP の「Cannot redeclare」で明示エラーになり、別の実装が黙って使われることはない。同一サイト内に複数 vendor/ が存在するケース(§3.3)でも、Composer の `autoload.files` はパッケージ名とパスから作る識別子で同じファイルを1回だけ読むため、二重定義にはならない(View/Support/Log 設計書 §5.6)。`iwf_*` とは接頭辞が異なるため旧 iwf2b のヘルパとも併存できる。
4. ヘルパの本体は**静的ファサード 1 メソッドへの単純委譲のみ**とする。ヘルパ内にロジック(条件分岐・ループ・WP 関数呼び出し)を書くことは禁止。テンプレートでの書き味のためだけに存在する。
5. ヘルパには完全な型宣言と PHPDoc を付ける(委譲先と同一シグネチャ)。`func_get_args()` / 可変引数による暗黙転送は禁止(旧 `iwf_dump` の作りは踏襲しない)。
6. 新しいヘルパの追加は、本書 §6.2 の予約表への追記を伴う(アーキテクチャ文書の改訂として扱う)。みだりに増やさない。

### 6.2 ヘルパ予約表

| ヘルパ | 委譲先 | 提供パッケージ | シグネチャ定義文書 |
|---|---|---|---|
| `tms_log()` | `TMSWP\Core\Log\Log` ファサード(チャンネル取得) | tmswp/core | Log 設計書 |
| `tms_thumb()` | `TMSWP\Thumbnail\`(署名付き URL 生成) | tmswp/thumbnail | thumbnail 設計書 |

- 旧 iwf2b のヘルパは `iwf_dump` / `iwf_log` / `iwf_thumb` の 3 つだった。`iwf_dump`(Debug)は DR-16 で廃止。`iwf_log` → `tms_log`、`iwf_thumb` → `tms_thumb` が後継に当たる(API 互換はない)。
- 上記 2 つの正確なシグネチャ(引数・戻り値型)は各定義文書が確定する。本書はヘルパ名と委譲先のみを予約する。
- 利用側プロジェクト(app/)が独自のグローバルヘルパを定義する場合は `tms_` を**使ってはならない**(プロジェクト固有接頭辞を使う)。rules/ に明記する。

---

## 7. i18n 方針

### 7.1 テキストドメインと対象文字列

- FW 全パッケージのテキストドメインは **`tmswp`** で統一する(DR-17)。
- gettext の対象となるのは FW がエンドユーザー・管理者に直接見せる文字列のみ: バリデーションエラーメッセージ(Form/Rule)、管理画面通知(Migrator、acf-bridge ドリフト検知等)、WP-CLI 出力の人間向け説明文。
- ソース文字列は**英語**で書き、`__('...', 'tmswp')` 形式で参照する。日本語は翻訳ファイルとして同梱する(日本製 FW だが、ソース=英語・ja=同梱訳とすることで gettext エコシステムの標準に乗る)。
- 翻訳対象の文字列は、gettext 関数(`__()`・`_x()`・`_n()` 等)の引数に文字列リテラルで直接書く。`wp i18n make-pot` が抽出するのはこの形だけであり、変数や独自のラッパー関数を経由して渡した文字列は `.pot` に入らない(§7.2 の `update-po` が `.po` からその訳を消す)。
- **CPT/Taxonomy ラベル自動生成は gettext を使わない**(DR-17)。ja/en の文字列テンプレートを FW 内蔵し、`KernelConfig` の `labelLanguage`(Kernel ごとの既定。デフォルト en)か定義側の `labelLanguage()` で切り替える(日本語サイトでは Kernel の既定を `LabelLanguage::Ja` にする)。翻訳ファイルの有無・ロケール設定に依存せず決定的に動作する(仕様詳細は PostType / Taxonomy 設計書)。

### 7.2 翻訳ファイルの配置と命名

| ファイル | 配置 | 備考 |
|---|---|---|
| POT(テンプレート) | `packages/<pkg>/languages/tmswp-<pkg>.pot` | 例: `tmswp-core.pot`・`tmswp-ja-toolkit.pot`。`wp i18n make-pot` が `packages/<pkg>/src` から生成 |
| PO | `packages/<pkg>/languages/tmswp-<locale>.po` | 例: `tmswp-ja.po`。翻訳の元データで、訳文は手で管理する。msgid の増減は `wp i18n update-po` で POT に揃える。初期同梱は ja のみ |
| MO | `packages/<pkg>/languages/tmswp-<locale>.mo` | 例: `tmswp-ja.mo`。`wp i18n make-mo` で PO から生成 |
| L10N PHP | `packages/<pkg>/languages/tmswp-<locale>.l10n.php` | 例: `tmswp-ja.l10n.php`。`wp i18n make-php` で PO から生成 |

- 同一ドメイン `tmswp` に対して複数パッケージが翻訳ファイルを持つが、WP の `load_textdomain()` は既ロードドメインへ**マージ**するため衝突しない。msgid の重複を避けるため、拡張パッケージの文字列にはパッケージ文脈が分かる文言を使う(機械的接頭辞は付けない)。
- 生成は `composer i18n:build` で行う。wp-env を起動した状態(`composer wp:start`)で、cli コンテナの WP-CLI がパッケージごとに次を実行する(作業ディレクトリは `wp-content/plugins/tmswp`)。
    1. `wp i18n make-pot packages/<pkg>/src packages/<pkg>/languages/tmswp-<pkg>.pot --domain=tmswp --package-name=tmswp-<pkg> --headers='{"POT-Creation-Date":""}'`(作成日時を空にし、ソースが同じなら POT が変わらないようにする)
    2. `wp i18n update-po packages/<pkg>/languages/tmswp-<pkg>.pot`(languages/ の全 PO の msgid を POT に揃える。POT に無い msgid は PO から消え、新しい msgid は訳が空の項目として加わる)
    3. `wp i18n make-mo packages/<pkg>/languages`
    4. `wp i18n make-php packages/<pkg>/languages`
- 新しい文字列を足したときは、`composer i18n:build` → PO に訳を書く → もう一度 `composer i18n:build` の順で行う。ソースと PO が変わらなければ、再実行しても生成物に差分は出ない。生成物(POT・MO・L10N PHP)は PO と一緒にコミットする。
- `.l10n.php` は `WP_Translation_File_PHP` が読む形式で、`return [ヘッダ…, 'messages' => [キー => 訳]];` の配列を返す。ヘッダは `domain`・`language`・`plural-forms` 等の小文字のキーで、トップレベルに置く。キーは msgid(文脈があれば `文脈 . "\4" . msgid`)、複数形は単数形の msgid をキーにして各形の訳を `"\0"` で連結した値を持つ。
- `.l10n.php` は WP-CLI の出力のままコミットし、php-cs-fixer の整形対象から外す(`.php-cs-fixer.dist.php` の `notPath`)。PHPStan の解析範囲にも languages/ は含めない。形式と PO・POT との整合は L10nTest(ユニット)で検査する。

### 7.3 ロード手順

- コアの翻訳は Kernel が、各拡張の翻訳は各拡張のブートストラップが、それぞれ **`init` フック(priority 1)** で次の手順でロードする:

```
$locale = determine_locale();                       // WP 5.0+
load_textdomain('tmswp', <pkg>/languages/"tmswp-{$locale}.mo");
```

- ロードコードは `.mo` のパスを渡すだけで、`.l10n.php` を直接指定しない。下限の WP 7.0 を含む全対応版で、`load_textdomain()` は引数の `.mo` パスから同名の `.l10n.php` を導出して先に読み、読めた場合は `.mo` を読まない(`.mo` を読むのは、`translation_file_format` フィルタで `mo` を選んだサイトと、`.l10n.php` を読めない場合)。FW 側に WP の版による分岐・フォールバックは書かない。
- このため `.l10n.php` の形式が §7.2 と異なると、WordPress は読み込みに成功したものとして扱い、訳は 1 件も返らず `.mo` にも進まない。`switch_to_locale('ja')` の後に Kernel と ja-toolkit のローダーを実行して `__()` が日本語を返すことを、統合テスト(TranslationIntegrationTest)で全セルについて確かめる。
- `init` より前に FW 内で `__()` / `_x()` 等を呼ぶことを全パッケージで禁止する(WP 6.7+ の just-in-time translation loading が `_doing_it_wrong` を出すため。§5.4)。翻訳済み文字列が必要な値(バリデーションメッセージ等)は遅延評価(クロージャまたは取得時解決)で持つ。この拘束は各担当文書の実装要件とする。
- ja 以外のロケールで翻訳ファイルが無い場合はソース文字列(英語)がそのまま表示される。これを正常動作とする(フォールバック処理を書かない)。

### 7.4 利用側(app/)の i18n

- 利用側テーマ・プラグインは**自身のテキストドメイン**(テーマ slug / プラグイン slug)を使う。`tmswp` ドメインを app/ 側の文字列に流用してはならない(rules/ に明記)。
- スターターには `languages/` ディレクトリと `load_theme_textdomain()` / `load_plugin_textdomain()` の呼び出し例を含める。

---

## 8. 利用側(テーマ・プラグイン)の標準ディレクトリレイアウト

### 8.1 ブート(正準形)

テーマ `functions.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

\TMSWP\Core\Kernel\Kernel::boot(
    \TMSWP\Core\Kernel\KernelConfig::theme(appDir: __DIR__ . '/app', appNamespace: 'App')
);
```

プラグイン本体ファイル:

```php
<?php
/**
 * Plugin Name: My Plugin
 */
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

\TMSWP\Core\Kernel\Kernel::boot(
    \TMSWP\Core\Kernel\KernelConfig::plugin(file: __FILE__, appDir: __DIR__ . '/app', appNamespace: 'App')
);
```

上の例のアプリ名前空間`App\`は説明用である。同一サイト内のTMSWPコンポーネント(テーマ・各プラグイン)は、それぞれ別の名前空間を使う。各コンポーネントのvendor/のオートローダはサイトの1つのプロセスに順に登録され、同じ名前のクラスは先に登録されたオートローダが読み込んだものが使われ、後のコンポーネントのクラスは隠れる(エラーにならずに別のクラスが動く)。そのため、テーマとプラグインが同じ`App\`を使うと、同じ名前のクラスが衝突する。Kernelも、起動したKernelどうしの名前空間の重複を例外にする(Kernel設計書)。スターターから作るプロジェクトの名前空間は、create-projectの`bin/setup.php`がスラッグから生成する(実装計画§1.6、§3.1)。自分で名前空間を変えるときは、`KernelConfig`の`appNamespace`とcomposer.jsonのpsr-4を揃える。

### 8.2 テーマの標準レイアウト

```
my-theme/
├── style.css                  # WP 標準テーマヘッダ
├── functions.php              # 上記ブート 1 行(+テーマ固有の add_theme_support 等は自由)
├── composer.json              # require: tmswp/core(+必要な拡張)、psr-4: "App\\" => "app/"
├── composer.lock              # コミットする(ビルド再現性)
├── vendor/                    # git 管理外(.gitignore)
├── AGENTS.md                  # AI 指示書(スターター由来。vendor 内 rules/ を参照)
├── app/                       # ビジネスロジック層(§8.3)
│   ├── Hook/
│   │   └── SiteHook.php       # #[AsHook] WP フック登録の集約
│   ├── Post/
│   │   └── ProductPost.php    # #[AsPostType]
│   ├── Tax/
│   │   └── ProductCategoryTax.php  # #[AsTaxonomy]
│   ├── User/                  # #[AsUserRole](不要なら作らない)
│   ├── Option/
│   │   └── SiteOption.php     # #[AsOptionGroup] backed enum(use OptionAccess)
│   ├── Form/
│   │   └── ContactForm.php    # Form 定義
│   ├── Page/
│   │   └── ContactPage.php    # page-contact.php の前処理クラス(テンプレートの先頭で handle() を呼ぶ。DR-19)
│   ├── Model/
│   │   └── InquiryModel.php   # #[AsModel] 独自テーブル
│   └── Acf/                   # acf-bridge 生成物(@generated。手編集禁止)
│       └── ProductFields.php
├── acf-json/                  # ACF 使用時(acf-json が真実源。DR-06)
├── languages/                 # テーマ自身のテキストドメイン用
├── parts/                     # get_template_part() 用パーツ(WP 標準機能のまま)
├── index.php
├── front-page.php
├── single-product.php         # WP 標準テンプレート階層のまま。FW は一切介入しない
├── archive-product.php
├── header.php / footer.php
└── screenshot.png
```

### 8.3 app/ ディレクトリ規約

| ディレクトリ | 置くもの | 主に使う Attribute / 基底 |
|---|---|---|
| `app/Hook/` | WP フック登録の集約クラス(サイト全体設定・テーマ調整)。フックは `#[Action]` / `#[Filter]` メソッド属性で宣言する(`register()` は真に動的な登録のみの例外手段) | `#[AsHook]` + `Hook\Hook` |
| `app/Post/` | 投稿タイプ定義 `<Name>Post.php` | `#[AsPostType]` + `Post\PostType` |
| `app/Tax/` | タクソノミー定義 `<Name>Tax.php` | `#[AsTaxonomy]` + `Tax\Taxonomy` |
| `app/User/` | ユーザーロール定義 `<Name>Role.php` | `#[AsUserRole]` + `User\UserRole` |
| `app/Option/` | オプショングループ定義(backed enum) | `#[AsOptionGroup]` + `Option\OptionAccess`(trait を enum で use) |
| `app/Form/` | フォーム定義 `<Name>Form.php` | `Form\Form` |
| `app/Page/` | テンプレートの前処理クラス `<Name>Page.php`(DR-19) | —(属性・基底クラスなし) |
| `app/Model/` | 独自テーブル `<Name>Model.php` | `#[AsModel]` + `Model\Model` |
| `app/Acf/` | acf-bridge コードジェネレータの生成物(`@generated`、手編集・手作成禁止) | — |
| `app/SettingsPage/` | 設定ページ定義(tmswp/settings-page 導入時) | settings-page 設計書 |
| 上記以外 | 自由(例: `app/Service/`)。属性が付いていないクラスは Discovery が単に無視する | — |

規約の性質:

- **ディレクトリ名は人間と AI のための規約であり、Kernel の探索はディレクトリ名に依存しない**(DR-04: 種別判定は Attribute のみで行う)。`app/Post/` 以外に置いた `#[AsPostType]` クラスも動作するが、rules/ では上表の配置を必須スタイルとして指示する。
- クラス名サフィックス(`*Post` / `*Tax` / `*Role` / `*Form` / `*Model` / `*Hook`)は旧 iwf2b 全 15 案件で定着していた命名をそのまま規約化したもの。PSR-4 のためファイル名=クラス名(大文字小文字含め完全一致)。
- `app/` 直下にクラスを直置きしない(旧案件の `app/Hook.php` 直置きスタイルは廃止し、必ず `app/Hook/SiteHook.php` 形式にする)。
- **テンプレート前処理は `app/Page/` の前処理クラスに書き、テンプレートの先頭で `handle()` を明示的に呼ぶ**(DR-19)。テンプレート1つにつき1クラスで、テンプレートが使う値を readonly のプロパティで持つ DTO を返す。属性も基底クラスも持たず、Discovery は関与しない。規約は 07-support.md §1A が定義する。

### 8.4 プラグインの標準レイアウトと composer.json

```
my-plugin/
├── my-plugin.php              # プラグインヘッダ + ブート(§8.1)
├── composer.json
├── composer.lock
├── vendor/                    # git 管理外
├── AGENTS.md
├── app/                       # §8.3 と同一規約
├── templates/                 # Template::part のプラグイン同梱 fallback(テーマ側で上書き可能。View 設計書)
├── languages/                 # プラグイン自身のテキストドメイン用
├── uninstall.php              # 独自テーブル削除等(Model 設計書の Migrator 連携)
└── readme.txt                 # WordPress.org 配布時のみ
```

利用側composer.json(テーマ・プラグイン共通の正準形。`App\`はプロジェクトの名前空間を表す。スターターから作ったプロジェクトでは、`bin/setup.php`がname・psr-4の名前空間・licenseを書き換えたcomposer.jsonになる。§3.1):

```json
{
    "name": "myorg/my-theme",
    "type": "project",
    "license": "proprietary",
    "require": {
        "php": ">=8.3",
        "tmswp/core": "^1.0"
    },
    "autoload": {
        "psr-4": {"App\\": "app/"}
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    }
}
```

- 拡張を使う場合は `"tmswp/ja-toolkit": "^1.0"` のように **コアと同一メジャー**の制約で追加する。
- `composer create-project tmswp/starter-theme my-theme` / `composer create-project tmswp/starter-plugin my-plugin` で上記構成一式が展開される(スターターの詳細内容は AI 資料設計書)。

---

## 9. 旧 iwf2b からの設計根拠(簡潔に)

| 本書の決定 | 旧 iwf2b の実態(分析資料より) |
|---|---|
| monorepo + split 配布、SemVer 必須 | 単一リポジトリ dev-master 運用で、15 案件の参照コミットが 2020〜2025 年の 8 世代に分散。タグが無いため「揃える」ことが構造的に不可能だった |
| コア=vendor/、アプリ=app/ の構成踏襲 | 全 15 案件が「composer=vendor/、ビジネスロジック=app/、ブートは数行」で完全統一されており、この構成は現場実態そのもの(要件 1) |
| app/ サブディレクトリ規約(Post/Tax/Model/SettingsPage/Hook) | 実案件の app/ 構成(`app/Post/*Post.php`、`app/Tax/*Tax.php`、`app/Model/*.php`、`app/SettingsPage/*.php`、`app/Hook.php`)をそのまま規約化。`app/Acf/` は T18 の `app/ACF.php`(コア外 ACF 連携の先行例)を発展させたもの |
| ext-gd をコアから thumbnail 拡張へ | 旧 composer.json はコアが `ext-gd` を require(サムネイル機能のため)。画像を扱わないプロジェクトにも GD を強制していた |
| psr/log ^3 へ更新 | 旧は `psr/log ~1.0`(PHP 8 の型付きインターフェースと不整合) |
| tms_ ヘルパ(`function_exists` ガードなし) | 旧 `helpers.php` の `iwf_dump` / `iwf_log` / `iwf_thumb` は `function_exists` ガードで囲んでいたが、同名の別実装が黙って使われるため、ガードせずに定義して重複を明示エラーにする。`iwf_dump` は Debug 廃止(DR-16)に伴い後継なし。可変引数の暗黙転送(`func_get_args`)は型不能のため禁止に変更 |
| FW 拡張パッケージ分離(thumbnail/settings-page) | FW 73 クラス中 27 クラス(37%)が全案件未使用。設定ページ等は一部案件のみの利用で、コア同梱がコアの肥大とテスト負債の原因だった |
| 利用側 dev-main 参照禁止 | dev-master ピン留め(コミットハッシュ固定)が標準化しており、FW 修正が誰にも届かない状態だった |

---

## 10. 受け入れ基準

### 10.1 チェックリスト

リポジトリ構成:

- [ ] monorepoルートに§2のツリーどおり`packages/`の6パッケージと`starters/`の2スターターが存在する
- [ ] 各パッケージの composer.json の name / 名前空間 / runtime 依存が §3.1 の表と完全一致する
- [ ] `tmswp/core` の require が `php >=8.3` / `psr/log ^3.0` / `psr/container ^2.0` のみである(ext 要求なし)
- [ ] `tmswp/thumbnail` のみ `ext-gd` を require している
- [ ] 拡張パッケージが `tmswp/core` 以外の tmswp/* を require していない(拡張間依存禁止)
- [ ] 全パッケージのライセンスがGPL-2.0-or-laterであり、packages/*のそれぞれに`LICENSE`がある
- [ ] 各パッケージをsplit相当で単独で取り出して`composer install && composer test`が通り、`.gitattributes`の`export-ignore`で`tests/`等の開発用のファイルと`.DS_Store`が配布物に入らない(§2.2)
- [ ] PHPStan 拡張が `packages/core/phpstan/`(`TMSWP\Core\PHPStan\`)に配置され、runtime コードから参照されていない
- [ ] 各パッケージに `rules/` が存在し composer 配布物に含まれる

リリース・配布:

- [ ] `vendor/bin/monorepo-builder validate` が green(相互依存・ルート composer.json の乖離なし)
- [ ] `split.yml`がmain pushで8 splitリポジトリのHEADを、タグpushで同名タグを複製する
- [ ] Packagistにtmswp/coreほか計8パッケージ(6パッケージと2スターター)がsplitリポジトリから登録され、タグで`composer require tmswp/core:^X.Y`が解決する
- [ ] 全パッケージのバージョン番号が常に一致する(lockstep)

下限・互換:

- [ ] 手で書いた全PHPファイル(生成物の、wp-scriptsが`build/`に書く`*.asset.php`と、`wp i18n make-php`が`languages/`に書く`*.l10n.php`を除く)が`declare(strict_types=1)`を持ち、PHP 8.3でパース可能(8.4以降の専用構文なし)
- [ ] §5.3の表にないWP APIを新規使用する場合、導入バージョンが7.0以下であることを確認するレビュー手順がAGENTS.mdに記載されている
- [ ] WP 7.0 未満のためのフォールバック分岐がコードベースに存在しない
- [ ] FW 内に init フック前の `__()` 等の呼び出しが存在しない(WP 6.7 JIT 翻訳対応)

ヘルパ・i18n:

- [ ] グローバル関数はすべて `tms_` 接頭辞 + §6.2 の予約表掲載のもののみで、`function_exists` ガードで囲んでいない
- [ ] ヘルパ本体が静的ファサード 1 メソッドへの委譲のみで構成されている
- [ ] 翻訳が `languages/tmswp-{locale}.mo` と同名の `tmswp-{locale}.l10n.php`(`wp i18n make-php` で .po から生成)の併載形式で配置され、`init`(priority 1)でロードされる(ロードコードは .mo パス指定のままで、WP の版による分岐が存在しない)

利用側レイアウト:

- [ ] `composer create-project tmswp/starter-theme` / `tmswp/starter-plugin` が §8.2 / §8.4 の構成を展開し、`Kernel::boot()` がエラーなく動作する
- [ ] スターターの AGENTS.md が `vendor/tmswp/*/rules/` を参照している
- [ ] テーマスターターのテンプレートファイルが素の WP テンプレート階層のままである(FW 固有のテンプレート処理が混入していない)

### 10.2 検証コマンド

```bash
# monorepo 整合
vendor/bin/monorepo-builder validate
composer validate --strict packages/*/composer.json

# 品質ゲート(中身は QA・CI 設計書。コマンド名は本書で確定)
composer lint && composer analyse && composer test
composer qa   # 上記一括

# 下限構文チェック(CI: PHP 8.3 ジョブで composer install が成功し全テスト green であること)
php8.3 -l $(git ls-files 'packages/*/src/**/*.php')

# ヘルパ規約
grep -rn "function_exists" packages/*/src/functions.php            # ガードが無いこと
grep -rln "function tms_" packages/*/src/ --include="*.php" | grep -v functions.php  # functions.php 以外に無いこと

# split 検証(リリース後)
composer create-project tmswp/starter-theme /tmp/t --no-interaction && php /tmp/t/vendor/autoload.php
```
