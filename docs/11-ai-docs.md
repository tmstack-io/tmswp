# 11. AI向け資料一式(AGENTS.md / rules / スターター)設計書

## 目的

TMStack WordPress Framework の明示目的「AIが自立実装できるFW」(要件2・DR-13)を実体化する資料一式を確定させる。本文書は以下の「全文案」を含む。実装者はここに書かれたMarkdown/PHPファイルをそのままリポジトリへ配置すればよい。

1. コアmonorepoリポジトリ直下の `AGENTS.md`(FWコントリビュータ向け)
2. スターター同梱の `AGENTS.md`(FW利用者=テーマ/プラグイン開発AI向け。theme版全文+plugin版差分)
3. `rules/` ディレクトリ(機能別レシピ)の構成と共通フォーマット(レシピの本文は各パッケージの `rules/` が正本)
4. `starters/theme`(tmswp/starter-theme)・`starters/plugin`(tmswp/starter-plugin)のファイルツリーと主要ファイル内容
5. `composer create-project` フロー(post-create-project-cmd によるプロジェクト名置換)

## スコープ(対象パッケージ)

- monorepoルート: `AGENTS.md`
- `packages/*/rules/`(機能別レシピ。各パッケージの配布物に同梱)
- `starters/theme/`(tmswp/starter-theme)全ファイル
- `starters/plugin/`(tmswp/starter-plugin)全ファイル

PHPクラスの新規定義は行わない。本文書が参照するFWのAPIはすべて各担当文書(02〜10)が定義するものであり、本文書とレシピのコード例はそれらの利用例である。

## 依存文書

- 00-overview.md(設計原則・DR・用語)
- 01-architecture.md(全体アーキテクチャ・monorepo構成)
- 02-kernel.md(Kernel::boot / KernelConfig / Discovery / 探索結果ファイル)
- 03-entities.md(PostType / Taxonomy / UserRole 定義層)
- 04-meta-options.md(MetaSchema / MetaBag / オプショングループ(backed enum + OptionAccess))
- 05-form.md(Form / FormDefinition / Rule群)
- 06-model.md(Model / TableDefinition / QueryBuilder / Migrator)
- 07-support.md(Template / Support / Log)
- 08-extensions.md(thumbnail / settings-page / ja-toolkit)
- 09-acf-bridge.md(acf-json運用・コードジェネレータ)
- 10-quality.md(composer qa / PHPStan / CI)

---

## 1. 設計方針

### 1.1 AI資料の3層構造

| 層 | 実体 | 読者AIの状況 | 更新主体 |
|---|---|---|---|
| 規約層 | `AGENTS.md`(リポジトリ直下) | セッション開始時に必ず読む。プロジェクト全体の規約・禁止事項・コマンドを把握する | FW(スターター由来)+利用者追記 |
| レシピ層 | `vendor/tmswp/core/rules/*.md` | 「CPTを追加して」等のタスク開始時に該当1ファイルだけ読む | FWコア(composer updateで自動更新) |
| 雛形層 | starterが生成した `app/` スケルトン・設定ファイル | コード生成時に既存ファイルを模倣する | create-project時に1回生成 |

### 1.2 rules/の単一真実源戦略

レシピ(rules/)は **各パッケージに同梱し、利用側プロジェクトからは `vendor/tmswp/<パッケージ>/rules/` を参照する**。レシピの正本は各パッケージの `rules/*.md` だけであり、スターターへの物理コピーも、本文書を含む設計書への複製も行わない。

- 理由1: コピー配布はFW更新とレシピの乖離(ドリフト)を必ず生む。vendor参照ならインストール済みコアのバージョンと常に一致する。
- 理由2: AIエージェントにとってvendor配下のMarkdownを読むコストはゼロであり、コピーの利点がない。
- 理由3: 旧iwf2bの最大の失敗は「規約がどこにも文書化されず15案件が定型コードをコピペで増殖させた」ことであり、コピー方式はこの失敗の再演になる。

スターターの `AGENTS.md` はタスク種別→レシピファイルの対応表を持ち、AIを該当レシピへ誘導する(§5)。拡張パッケージの使い方のレシピはその拡張パッケージの`rules/`に置き、拡張パッケージ固有の詳細(acf-bridgeの内部仕様等)は各拡張パッケージのREADMEが持つ。利用者AIが最初に読むべき導入手順はコアの`rules/acf.md`・`rules/extensions.md`に集約する(拡張を入れる前でも、コアのrules/だけ見れば次の一手が必ず分かる状態を保証する)。

### 1.3 レシピの完結性要件

各レシピは次を満たす(受け入れ基準§12で検証):

1. **単独完結**: そのレシピ1ファイルだけを読んだAIが、AGENTS.mdの規約前提のもとで該当タスクを完遂できる。他レシピへの参照は「発展」節のみに置く。
2. **完全動作コード**: コード例は省略(`// ...`)なしでコピーして動く完全形。`declare(strict_types=1)`・`use`文・名前空間まで含める。
3. **配置規約の明示**: 「どのディレクトリに・どのファイル名で・どの名前空間で」置くかを冒頭で確定させる。
4. **検証手順**: レシピ末尾に必ず「確認方法」(composerコマンド+WP上での目視確認手順)を置く。
5. **アンチパターン**: 旧FW由来の事故や typicalなAIの誤実装を「してはいけないこと」として明記する。

### 1.4 成果物配置マップ

```
tmswp/ (monorepo)
├── AGENTS.md                       … §4 全文(コントリビュータ向け。monorepoの開発ルールの正本を含む)
├── packages/
│   ├── core/
│   │   └── rules/                  … §6 レシピ14本(パッケージに同梱して配布。本文はこのディレクトリが正本)
│   │       ├── post-type.md
│   │       ├── taxonomy.md
│   │       ├── user-role.md
│   │       ├── meta.md
│   │       ├── acf.md
│   │       ├── form.md
│   │       ├── form-mail.md
│   │       ├── page.md
│   │       ├── model.md
│   │       ├── options.md
│   │       ├── hooks.md
│   │       ├── view.md
│   │       ├── blocks.md
│   │       └── extensions.md
│   ├── acf-bridge/rules/sync-check.md        … §6 拡張のレシピ(各パッケージに同梱)
│   ├── settings-page/rules/settings-page.md
│   ├── thumbnail/rules/thumbnail.md
│   ├── ja-toolkit/rules/ja-toolkit.md
│   └── testing/rules/testing.md
├── starters/
│   ├── theme/                      … §8 tmswp/starter-theme
│   │   ├── AGENTS.md               … §5 全文(利用者向け・theme版)
│   │   └── (ツリーは§8参照)
│   └── plugin/                     … §9 tmswp/starter-plugin
│       ├── AGENTS.md               … §5 差分(plugin版)
│       └── (ツリーは§9参照)
└── examples/
    └── sample-site/
        └── AGENTS.md               … スターターのtheme版を元に、名前空間SampleSite\・wp-env・例の場所を足したもの
```

`CLAUDE.md` 等のエージェント固有の規約ファイルは置かない。規約はmonorepo・スターター・examples/sample-siteとも`AGENTS.md`だけに置く(二重管理を避けるため。12-implementation-plan.md §1.18)。monorepoのルートの`AGENTS.md`は、冒頭にmonorepoの開発ルール(エージェントが常に従う規約)を置き、続けて§4のコントリビュータ向けの節を置く。

---

## 2. 文書間の用語固定

本文書とレシピ・AGENTS.mdが使うコマンド名は10-quality.mdの定義に従い、次に固定する。

| コマンド | 内容 |
|---|---|
| `composer lint` | php-cs-fixer dry-run(差分表示のみ) |
| `composer fix` | php-cs-fixer 自動修正 |
| `composer analyse` | PHPStan level max |
| `composer test:unit` | PHPUnit(ユニット層。WP非依存・Docker不要) |
| `composer test:coverage` | ユニット・統合テストの合算カバレッジの計測と閾値判定(wp-env/Docker必要。`composer wp:start:coverage` で起動) |
| `composer test:integration` | PHPUnit(統合層、wp-env必須) |
| `composer test` | `test:unit` → `test:integration` の合成(wp-env/Docker必要) |
| `composer qa:fast` | lint + analyse + test:unit(monorepoのみ。Docker不要。**AIの実装中の反復ループはこれ**) |
| `composer discover:build` | fixtureのテーマ・プラグインの探索結果ファイルの生成(`wp tmswp discover --kernels=2`。wp-env/Docker必要) |
| `composer discover:check` | fixtureの探索結果ファイルが最新かの照合(`wp tmswp discover --check --kernels=2`。wp-env/Docker必要) |
| `composer acf:check:fixtures` | fixtureのacf-bridgeの生成物の照合(`tms-acf check --strict`。Docker不要) |
| `composer qa` | lint → analyse → acf:check:fixtures → discover:check → test を直列実行(コミット前必須ゲート。統合テストを含むためDocker必要) |

利用側プロジェクト(スターター)には、このうち`lint`・`fix`・`analyse`・`test`・`discover:check`・`qa`を同名のcomposer scriptsとして定義し(ほかに探索結果ファイルを作る`discover`を持つ。§8.2)、**人間・CI・AIが同一コマンドを叩く**(DR-12。10-quality.md §7の項目1)。`test:unit`・`test:integration`・`test:coverage`・`qa:fast`・`discover:build`・`acf:check:fixtures`はmonorepoだけのscriptsで、スターターには無い(スターターのAGENTS.mdにも書かない)。ただしスターターは統合テストを既定で持たないため、スターター側の `composer test` はユニット層のみを指す(範囲差は§5のAGENTS.mdに注記する)。スターター側の`composer qa`はlint → analyse → discover:check → testで、`discover`・`discover:check`は`wp tmswp discover`(`--check`)をそのまま呼ぶ(§8.2)。コマンド意味論の正準は10-quality.md §1であり、本文書内の全コマンド表記はこれとgrep突合で一致させる。

WP-CLIコマンド名も各担当文書の正準名に固定する。本文書のAGENTS.md・全レシピはこの表の表記のみを使う。

| WP-CLIコマンド | 内容 | 正準定義 |
|---|---|---|
| `wp tmswp discover` | 起動中のすべてのKernelの探索結果ファイル(`tmswp-discovery.php`)の生成。`--check`はファイルが最新かの照合(古ければ非0)、`--kernels=<n>`は起動中のKernelの数の確認(違えば走査せずに非0) | 02-kernel |
| `wp tmswp migrate` | Model(独自テーブル)のマイグレーション実行 | 06-model |
| `wp tmswp acf sync` | acf-jsonから型付きアクセサクラスを再生成 | 09-acf-bridge |
| `wp tmswp acf check` | acf-jsonと生成クラスのドリフト検査(差分があればexit 1) | 09-acf-bridge |
| `wp tmswp thumb clear` | thumbnailのキャッシュのファイルの削除(`--days`より古いもの) | 08-extensions |

acf-bridgeは、WordPressを起動せずにテーマ・プラグインのルートの`tmswp-kernel.php`の構成で同じ生成・照合を行う`vendor/bin/tms-acf sync|check`も持つ(09-acf-bridge §5.1。E101だけは検査しない)。スターターはacf-bridgeに依存しないため、`acf:sync`・`acf:check`のscriptsを持たない。acf-bridgeを入れるときに足す(§8.2)。

---

## 4. コアmonorepo直下 AGENTS.md(全文案)

読者は「FW本体を開発・修正するAI(コントリビュータ)」。monorepoルートに配置する。以下のフェンス内が全文で、monorepoルートの`AGENTS.md`の実物と同じ内容である。冒頭の「TMSWP 開発ルール」の箇条は、このmonorepoの開発ルールの正本(エージェントが常に従う規約)で、続く節が対応範囲・パッケージ構成・必須コマンド・CI・コーディング規約・変更の手順・新しいパッケージを足す手順・禁止事項・リリースを示す。

````markdown
# TMSWP 開発ルール

このリポジトリは TMStack WordPress Framework 本体の開発用 monorepo です。実装は `docs/12-implementation-plan.md` のロードマップに従い、版とタスクの順に進めます。

- 対応範囲は PHP 8.3〜8.5、WordPress 7.0 以上とする。
- `tmswp/core` の runtime 依存は `psr/log` と `psr/container` のみに限定する。
- 旧 iwf2b 互換のシム、別名、移行用 API は追加しない。
- すべての PHP ファイルに `declare(strict_types=1)` を置き、公開 API は型宣言を必須とする。
- 品質確認は `composer lint` / `composer analyse` / `composer test:unit` / `composer test:integration` / `composer qa` を使う。
- fixture の app/ を変えたら、探索結果ファイル(`tmswp-discovery.php`)を `composer discover:build` で作り直してコミットする。照合は `composer discover:check`(`composer qa` に含まれ、wp-env が必要)。
- wp-env は `npm ci` で入れたローカルの `@wordpress/env`(package.json で版を固定)を `composer wp:start` / `wp:stop` / `test:integration` 経由で使う。直接呼ぶときは `node node_modules/@wordpress/env/bin/wp-env` とパスで指定する。`npx` や npm の `exec` / `run` でコマンド名 `wp-env` を解決する呼び出しは、第三者のパッケージやグローバルの同名コマンドを実行しうるので使わない。
- 版のすべてのタスクを終えたら、その版の受け入れ条件の確認結果を `docs/acceptance/v0.x.y.md` に記録する。

## このファイルの読者

このファイルは、フレームワーク本体を開発・修正するAIエージェントと人(コントリビュータ)が読む。
利用側のプロジェクト(テーマ・プラグイン)の開発規約は`starters/*/AGENTS.md`と`packages/*/rules/`にある。混同しない。
規約はAGENTS.mdだけに置き、`CLAUDE.md`等のエージェント固有の規約ファイルは置かない(docs/12 §1.18)。

## アーキテクチャの要約

- 対応範囲とコアのruntimeの依存は、上の開発ルールのとおり(`psr/log` ^3・`psr/container` ^2)。
- 規約による自動の探索: 利用側の`app/`にPHP 8のAttributeを付けたクラス(投稿タイプ・タクソノミー・ロール・オプショングループ・Model・フック)を置くだけで、Kernelが探索して登録する。独自ブロックは`build/blocks/`以下の`block.json`を探索して登録する。フォームは探索の対象ではなく、利用側が`FormDefinition`で定義し、前処理クラス等で明示的に作って使う。探索の結果は、探索コマンド(`wp tmswp discover`)が探索結果ファイルに書き、コミットする。本番(debugが偽)は探索結果ファイルだけを読み、debugのときだけ`app/`と`build/blocks/`を毎回走査する。
- ビューはWordPress標準のテンプレート階層を壊さない(絶対原則)。テンプレートの前処理は、テンプレートの先頭で前処理クラスを明示的に呼ぶ。
- 外部プラグイン(ACF・CF7等)に依存するコードはコアに置かない(ACFは`packages/acf-bridge`だけ)。拡張は利用側の`KernelConfig`の`extensions`で、Kernelごとに登録する。
- 配布: このmonorepoで開発し、`.github/workflows/split.yml`がmainとタグを8つの読み取り専用リポジトリ(`tmstack-io/tmswp-*`)へ同期し、Packagistに`tmswp/*`の名前で登録する。全パッケージを同じ版で出す(lockstep。SemVer、0.xの間はマイナーで破壊的変更を許す)。Packagistへの公開はv0.9.0で行う予定で、まだ行っていない。

## パッケージ構成

| パス | composerの名前 | 名前空間 | 役割 |
|---|---|---|---|
| packages/core | tmswp/core | `TMSWP\Core\` | Kernel・Container・エンティティの定義・メタ・オプション・Form・Model・Log・View・Support・ブロックの登録 |
| packages/thumbnail | tmswp/thumbnail | `TMSWP\Thumbnail\` | 署名付きのURLのサムネイル(WP_Image_Editor) |
| packages/settings-page | tmswp/settings-page | `TMSWP\SettingsPage\` | 管理画面の設定ページ(Formの層を使う) |
| packages/acf-bridge | tmswp/acf-bridge | `TMSWP\AcfBridge\` | acf-jsonからの型付きアクセサの生成・`AcfMetaStorage`・`tms-acf` |
| packages/ja-toolkit | tmswp/ja-toolkit | `TMSWP\JaToolkit\` | 日本語サイト向けの機能(すべてのモジュールがオプトイン) |
| packages/testing | tmswp/testing | `TMSWP\Testing\` | テストの支援コード(`UnitTestCase`・`IntegrationTestCase`・テストダブル。利用側はrequire-devで使う) |
| starters/theme | tmswp/starter-theme | `TmswpStarterTheme\`(create-projectで、スラッグから作った名前空間に書き換える) | create-projectで使うテーマの雛形 |
| starters/plugin | tmswp/starter-plugin | `TmswpStarterPlugin\`(同上) | create-projectで使うプラグインの雛形 |

上の8つが読み取り専用リポジトリへの同期の対象である。`examples/sample-site`(テーマのスターターから作ったサンプルのテーマ。同期しない)は、主な機能を実物のWordPressで動かす例で、専用のwp-env(ポート8882)と`composer qa`を持つ。

設計の正本は`docs/00〜11`である(用語は`00-overview.md` §7、設計判断はDRとして同§4)。`docs/`に定義の無い公開APIを作る場合は、同じ変更で設計書を改訂する。

## 必須コマンド

```bash
composer qa                 # lint → analyse → acf:check:fixtures → discover:check → test。これが通らない変更はコミットしない(wp-env・Dockerが必要)
composer qa:fast            # lint → analyse → test:unit。Docker不要。実装中の反復はこちら
composer test               # test:unit → test:integration(wp-envが必要: composer wp:start)
composer test:unit          # ユニットテスト(PHPUnit 11・Brain Monkey。WordPress非依存。Docker不要)
composer test:integration   # 統合テスト(wp-envのcliコンテナのPHPUnit 9.6。wp-envが必要: composer wp:start)
composer test:coverage      # ユニット・統合の合算カバレッジの計測と閾値の判定(wp-envをcomposer wp:start:coverageで起動しておく)
composer analyse            # PHPStan level max(baselineなし)
composer lint               # コードスタイルの検査(php-cs-fixerのdry-run)
composer fix                # コードスタイルの自動修正
composer discover:build     # fixtureのテーマ・プラグインの探索結果ファイルを作る(wp tmswp discover --kernels=2。wp-envが必要)
composer discover:check     # fixtureの探索結果ファイルが最新かを照合する(wp tmswp discover --check --kernels=2)
composer acf:check:fixtures # fixtureのacf-bridgeの生成物を、WordPressを起動しないtms-acf check --strictで照合する
composer i18n:build         # 翻訳ファイル(packages/*/languages/)をソースから作り直す(wp-envが必要)
composer monorepo:validate  # パッケージ間の版の制約の整合を検査する
composer wp:start           # wp-envを起動する(http://localhost:8888)。止めるのはcomposer wp:stop
php tools/extract-rules-code.php && php tools/verify-rules-code.php   # packages/*/rules/のコード例の抽出と検査
```

- PHPStanは**level max・baselineなし**で運用する。エラーをbaselineや`@phpstan-ignore`で握りつぶす変更は受け入れない。ignoreがどうしても要る場合(WordPressの関数のstubの不備等)は、理由のコメントを付け、変更の説明に書く。
- テストは2層である。ユニット層(`packages/*/tests/Unit/`。WordPress非依存)と統合層(`packages/*/tests/Integration/`。wp-envとWordPressのテストライブラリ)。ルートの`tests/Unit/`はtools・bin・スターターのsetup.php等を、`tests/Integration/`はスターターから作ったテーマ・プラグインの同時起動を確かめる。テストは`src/`の構成をミラーした場所に置く。
- 新しいクラスにはユニットテストを足す。WordPressの関数に触れる処理は薄い境界のクラスに寄せ、その内側をユニットテストできるようにする。WordPressに依存するクラスは、モックを多用したユニットテストではなく、統合テストで確かめる。
- カバレッジは、ユニットと統合を合算した行カバレッジで判定する。全体の閾値は版ごと(`docs/12-implementation-plan.md` §0.7)、パッケージ別の閾値は6パッケージ(core・acf-bridge・settings-page・thumbnail・ja-toolkit・testing)とも80%で、どちらも`composer.json`の`test:coverage:check`の引数の1か所で定義する。
- `packages/*/rules/`を変えたら、上のrulesの抽出と検査を通す。
- サンプルサイトは`cd examples/sample-site && composer wp:start && composer wp:setup && composer qa`で確かめる(手順は`examples/sample-site/README.md`)。スターターから作ったプロジェクトは、`tools/ci/create-starter-project.sh`(CIのStarter Projectsのジョブと同じ手順)で作って確かめる。

## CI

`.github/workflows/qa.yml`は、すべてのプルリクエストとmainへのpushで次のジョブを実行する。外部アクションはSHAで固定し、runnerは`ubuntu-24.04`に固定する(`ubuntu-latest`を使わない)。

| ジョブ | 内容 |
|---|---|
| Coding Standards | PHPStanのbaselineのファイルが無いことの確認と`composer lint`(PHP 8.3) |
| Static Analysis | `composer analyse`と`composer acf:check:fixtures` |
| Monorepo Validate | `composer monorepo:validate` |
| Rules Code Examples | `packages/*/rules/`のコード例の抽出と検査(`tools/extract-rules-code.php`・`tools/verify-rules-code.php`) |
| Unit | `composer test:unit`(PHP 8.3と8.5) |
| Integration | `composer test:integration`(PHP 8.3と8.5 × WordPress 7.0と最新版の4セル) |
| Coverage | 翻訳ファイルと探索結果ファイルの照合、合算カバレッジの閾値の判定(PHP 8.3・WordPress最新版) |
| Sample Site QA | `examples/sample-site`の`composer qa`(専用のwp-env) |
| Starter Projects | スターターからcreate-projectで作ったテーマ・プラグインの`composer qa`と、テーマのスターターのブロックのビルドと探索結果ファイルの照合 |

## コーディング規約

- `declare(strict_types=1);`はすべてのPHPファイルに置く(上の開発ルール)。コードスタイルはPER Coding Style 2.0と`.php-cs-fixer.dist.php`の規則に従う。
- クラスは`final`にする。継承させる拡張点(`PostType`・`Taxonomy`・`UserRole`・`Model`・`Hook`の各基底)は`abstract`で宣言する。オプショングループはbacked enumで、基底クラスを持たない。`Form`は`final`で、利用側は`FormDefinition`で定義して`new Form(...)`で作る(継承しない)。
- プロパティ・引数・戻り値のすべてに型を宣言する。`mixed`は境界(WordPressのAPI・`$_POST`等)に限り、内側ですぐに絞り込む。
- **禁止**: `__call`・`__callStatic`・`__get`・`__set`等のマジックメソッド、`extract()`、`eval()`、グローバル変数の新設、`exit`・`die`(`00-overview.md`の原則1の例外である、PRGのリダイレクトと設定ページの保存の後の`wp_safe_redirect()`の直後を除く)、WordPress標準のテンプレート階層への介入(`template_include`での差し替え)。
- 例外はFWの基底(`TMSWP\Core\Exception\`の`LogicException`・`RuntimeException`・`InvalidArgumentException`)を継承し、マーカーのinterface`TmswpExceptionInterface`を実装する(`tests/Unit/ExceptionHierarchyTest.php`が確かめる)。素の`\RuntimeException`等をthrowしない。エラーの扱いは`00-overview.md` §5.6(設定の誤りは起動時に常に例外、実行時のデータの取得の失敗はdebugなら例外・本番ならKernelのロガーに記録して既定値)に従う。
- WordPressの関数の直接の呼び出しは、各パッケージの境界のクラス(例: `Meta\WpMetaStorage`・`Model\Connection`)に閉じ込める。
- コアの名前空間は`TMSWP\Core\`。コアの名前空間がDiscoveryの走査の対象外である前提を崩さない。
- i18n: テキストドメインは`tmswp`。ソースの文言(gettextのソースの文字列)は英語で書き、`__('...', 'tmswp')`の形で渡す。
- コメント・docblockは日本語でよい。識別子は英語にする。

## 変更の手順

1. 該当するパッケージの設計書(`docs/`)を読み、APIの契約を確かめる。ロードマップのタスクとして行う場合は、`docs/12-implementation-plan.md`のそのタスクの「対象ファイル領域」と完了条件に従う。
2. テストを先に書くか、少なくとも変更と同じ変更でテストを足す。
3. `composer qa`を通す。
4. 利用者に見える変更は、`CHANGELOG.md`の先頭の節(見出しはUnreleased)の小見出し(追加・変更・削除・修正・開発)に書く。公開APIのシグネチャの変更・削除等の破壊的変更は、変更・削除に、利用側で直すことと一緒に書く。
5. 公開APIを変えたら、`packages/*/rules/`・`starters/`・`examples/sample-site/`の規約とコード例を追従させる(`grep -rn <旧API> packages/*/rules starters examples/sample-site --exclude-dir=vendor --exclude-dir=node_modules`で追従の漏れを確かめる)。

## 新しいパッケージを足す手順

正本は`docs/10-quality.md` §6.2である。

1. `packages/<name>/`に、`composer.json`(nameは`tmswp/<name>`、PSR-4は`TMSWP\<PascalName>\` → `src/`、license、requireの`"tmswp/core": "^X.Y"`とPHP・拡張の要件、require-devの`"tmswp/testing": "^X.Y"`、`scripts.test`、`extra.branch-alias`)、`LICENSE`(ルートと同じGPL-2.0-or-later)、`README.md`、`phpunit.xml.dist`、`.gitattributes`(`export-ignore`で`/tests`・`/phpunit.xml.dist`・`/.gitattributes`・`.DS_Store`を外す)と、`src/`・`tests/Unit/`を置く。利用者向けのレシピが要る場合は`rules/`に置き、コアの`rules/extensions.md`にも導入手順を足す。コアに要件を足さない。
2. ルートの`composer.json`の`replace`・`autoload`・`autoload-dev`に手で足す(`monorepo-builder merge`は使わない)。
3. ルートの`phpstan.neon.dist`の`paths`、`phpunit.unit.xml.dist`・`phpunit.integration.xml.dist`のtestsuite、ルートの`composer.json`の`test:coverage:check`の`--package`、`.github/workflows/split.yml`の`matrix`に足す。
4. `docs/01-architecture.md`・`docs/10-quality.md`・`docs/RELEASING.md`・このファイルのパッケージの一覧と数を改める。
5. パッケージをsplit相当で取り出して`composer install && composer test`が通ること(`docs/RELEASING.md`の「split相当の確認」)と、`composer monorepo:validate`で確かめる。
6. 拡張のパッケージは**コアのAPIだけに依存する**。拡張どうしの依存は原則として作らない(要る場合は変更の説明で合意を取る)。Packagistへの登録はメンテナが行う。

## 禁止事項(コントリビュータ向け)

- コアに外部プラグイン(ACF・CF7・Yoast等)の関数・クラスの参照を足すこと。
- コアのruntimeの依存(`psr/log`・`psr/container`以外)を足すこと。
- `register_post_type`等のWordPressの登録の関数を、`init`等の正規のフックの外で即時に実行すること。
- `packages/*`の間でソースをコピーすること(共有したいコードは、coreのSupportへの昇格を検討する)。
- `starters/`・`packages/*/rules/`・`examples/sample-site/`の規約とコード例を、コアのAPIの変更に追従させずに放置すること。
- `CLAUDE.md`等のエージェント固有の規約ファイルを置くこと。

## リリース

- 手順の正本は`docs/RELEASING.md`である。`vendor/bin/monorepo-builder release vX.Y.Z`が、mainへのpushとタグのpushを自ら行い、`split.yml`が各読み取り専用リポジトリへ同期する。
- mainへのpushとタグ付けは、毎回ユーザーに確認してから行う。
````

---

## 5. スターター同梱 AGENTS.md(全文案)

読者は「このテーマ/プラグインを実装するAI(FW利用者)」。starter-theme・starter-pluginの直下に配置し、create-projectでそのままプロジェクトに残る。theme版を全文、plugin版を差分で示す。

実物のAGENTS.mdには、スターターの名前空間(`TmswpStarterTheme\`・`TmswpStarterPlugin\`)を書き、create-projectのsetup.phpがスラッグから生成した名前空間に書き換える(§10)。実物のAGENTS.mdは、レシピ(`vendor/tmswp/*/rules/`)のコード例の名前空間`App\`を、その名前空間に読み替える旨を示す。テキストドメイン(`{{project_slug}}`)とブロック名の名前空間(`tmswp-starter/`)も、setup.phpがスラッグに書き換える。そのため、AGENTS.mdにはこの3つを、書き換えた後もそのまま読める形でだけ書き、ほかの`{{`(setup.phpが置き換えないプレースホルダ)を書かない(`tests/Unit/StarterSetupTest.php`が、作ったプロジェクトに`{{`とスターターの名前空間が残らないことを確かめる)。

AGENTS.mdは次の節を持つ。タスク→レシピの対応表(coreの14本と拡張の5本)、プロジェクト構成、ロジックの置き場(配置決定木)、クラス追加の基本手順、必須コマンド、コーディング規約、禁止事項、デバッグ(ログ・探索の不調・探索結果ファイルとキャッシュ・起動時のエラー)、完了条件。

### 5.1 theme版全文(starters/theme/AGENTS.md)

以下のフェンス内が全文で、`starters/theme/AGENTS.md`の実物と同じ内容である。

````markdown
# このテーマの開発規約(TMSWP)

このプロジェクトはTMStack WordPress Framework(TMSWP)を使うWordPressテーマである。
あなた(AIエージェント)はこのファイルの規約に従って実装する。
フレームワーク本体(`vendor/`)はここでは開発しない。

## 最初に読むもの

タスクに応じて、下表の該当レシピを必ず読んでから実装を始める。
レシピはフレームワークの各パッケージに同梱されており、このプロジェクトに入っている版と一致する。

このテーマの名前空間は`TmswpStarterTheme\`である(composer.jsonの`autoload`の`psr-4`で`app/`に割り当てたもの。create-projectのときにスラッグから作った)。
レシピのコード例の名前空間`App\`は、`TmswpStarterTheme\`に読み替える。

| やりたいこと | 読むファイル |
|---|---|
| カスタム投稿タイプを追加する | `vendor/tmswp/core/rules/post-type.md` |
| カスタムタクソノミーを追加する | `vendor/tmswp/core/rules/taxonomy.md` |
| ユーザーロールを追加する | `vendor/tmswp/core/rules/user-role.md` |
| 投稿・ターム・ユーザーにカスタムフィールド(非ACF)を持たせる | `vendor/tmswp/core/rules/meta.md` |
| ACFでフィールドを管理する(acf-json) | `vendor/tmswp/core/rules/acf.md` |
| フォーム(問い合わせ・申し込み等)を実装する | `vendor/tmswp/core/rules/form.md` |
| フォームの完了処理でメールを送る | `vendor/tmswp/core/rules/form-mail.md` |
| テンプレートの前処理(値の用意・フォームの駆動)を書く | `vendor/tmswp/core/rules/page.md` |
| 独自DBテーブルを作って読み書きする | `vendor/tmswp/core/rules/model.md` |
| サイト設定値(オプション)を定義する | `vendor/tmswp/core/rules/options.md` |
| add_action・add_filterをまとめて登録する | `vendor/tmswp/core/rules/hooks.md` |
| テンプレートパーツを読み込む | `vendor/tmswp/core/rules/view.md` |
| 独自ブロックを追加する | `vendor/tmswp/core/rules/blocks.md` |
| 公式の拡張(acf-bridge・settings-page・thumbnail・ja-toolkit・testing)を導入する | `vendor/tmswp/core/rules/extensions.md` |
| ACFの生成アクセサを生成・照合する(tmswp/acf-bridge) | `vendor/tmswp/acf-bridge/rules/sync-check.md` |
| 管理画面に設定ページを作る(tmswp/settings-page) | `vendor/tmswp/settings-page/rules/settings-page.md` |
| 署名付きのURLでサムネイルを出す(tmswp/thumbnail) | `vendor/tmswp/thumbnail/rules/thumbnail.md` |
| 日本語サイト向けの機能を使う(tmswp/ja-toolkit) | `vendor/tmswp/ja-toolkit/rules/ja-toolkit.md` |
| テストの基底クラス・テストダブルを使う(tmswp/testing。スターターには入っていないため、先に`composer require --dev tmswp/testing`で入れる) | `vendor/tmswp/testing/rules/testing.md` |

拡張のレシピ(`vendor/tmswp/core/`以外)は、その拡張を`composer require`で入れると`vendor/`に入る。入っていなければ、先に`vendor/tmswp/core/rules/extensions.md`の手順で拡張を入れる。

## プロジェクト構成

```
.
├── style.css               # テーマヘッダ(Theme Name・Text Domain等)
├── functions.php           # FWのブートだけ。ロジックを書かない
├── tmswp-kernel.php        # Kernelの構成(KernelConfig::theme())を返す。拡張・ログ・ブロックカテゴリはここに書く。WordPressの関数を呼ばない
├── tmswp-discovery.php     # 探索結果ファイル(wp tmswp discoverの生成物。コミットする)
├── index.php ほか          # WordPress標準のテンプレート階層(header.php・footer.php・page-{slug}.php等。通常のテーマと同じ)
├── parts/                  # テンプレートパーツ(Template::part()・get_template_part()の読み込み先)
├── assets/css/main.css     # app/Hook/ThemeSetupHook.phpが読み込むCSS
├── blocks/                 # 独自ブロックのソース(1ブロック1ディレクトリ。blocks.md)
├── build/blocks/           # ブロックのビルドの出力(npm run build。Kernelが登録する。コミットする)
├── languages/              # このテーマの翻訳ファイル(<locale>.mo・<locale>.l10n.php。例: ja.mo)
├── app/                    # PHPのクラス(名前空間 TmswpStarterTheme\、PSR-4)
│   ├── Hook/               # フック(*Hook。#[AsHook])
│   ├── Post/               # 投稿タイプ(*Post。#[AsPostType])
│   ├── Tax/                # タクソノミー(*Tax。#[AsTaxonomy])
│   ├── User/               # ユーザーロール(*Role。#[AsUserRole])
│   ├── Form/               # フォーム(*Form)
│   ├── Model/              # 独自テーブル(*Model。#[AsModel])
│   ├── Option/             # サイト設定値(*Option。#[AsOptionGroup]のbacked enum)
│   └── Page/               # テンプレートの前処理クラス(*Page。使うときに作る)
├── tests/Unit/             # ユニットテスト(composer test)
├── composer.json
├── package.json            # ブロックのビルド(npm run build)
├── phpstan.neon
├── phpunit.xml.dist
└── .php-cs-fixer.dist.php
```

`languages/`の翻訳ファイルは、WordPressがテーマのヘッダの`Text Domain`から自動で読み込む(読み込みのコードは要らない)。名前は`<locale>.mo`・`<locale>.l10n.php`(例: `ja.mo`)にする(テキストドメインを付けた名前では読まれない)。`WP_LANG_DIR`(`wp-content/languages/themes/`)に同じテキストドメインの翻訳ファイル(`<テキストドメイン>-<locale>.mo`)があれば、そちらが優先される。
`app/`の下には、レシピが示すほかの置き場(メールの`Mail/`等)も作ってよい。PSR-4のとおり、クラス名とファイルのパスを一致させる。

## ロジックの置き場(配置決定木)

書こうとしているコードの置き場は、次の順に当てはめて最初に当たったもので決める。

1. **特定のテンプレートの表示前の処理**(そのテンプレートでだけ要る値の用意・整形・フォームの送信の処理・PRGのリダイレクト)
   → `app/Page/<Name>Page.php`の前処理クラス。テンプレートの先頭(`get_header()`より前)で`$vm = <Name>Page::handle();`を1回だけ呼び、返された値(`public readonly`のプロパティ)をテンプレートで読む(page.md)。
2. **投稿タイプ・タクソノミー・ユーザーロール**の定義 → `app/Post/`・`app/Tax/`・`app/User/`(post-type.md・taxonomy.md・user-role.md)。
3. **投稿・ターム・ユーザーのメタ** → そのエンティティのクラスの`#[MetaSchema]`付きの定数と、明示のアクセサ(meta.md。ACFの画面で編集する値はacf.md)。
4. **サイト設定値** → `app/Option/`のbacked enum(options.md)。
5. **フォームの定義** → `app/Form/`(form.md)。完了処理のメールは`app/Mail/`(form-mail.md)。
6. **独自テーブル** → `app/Model/`(model.md)。
7. **独自ブロック** → `blocks/<slug>/`のソースをビルドする(blocks.md)。ブロック専用のフックは`app/Hook/`。
8. **それ以外のWordPressのフックの処理**(リクエストの種類を問わず動くもの) → `app/Hook/`の`#[AsHook]`クラス(hooks.md)。

前処理クラスはAttributeも継承も要らない`final class`で、テンプレートが使う値を型付きのプロパティで返す。出力(`echo`)はしない。全テンプレートに共通の前処理は持たない。共通の処理は`app/Hook/`に置くか、パーツの中でエンティティのアクセサを直接読む。

## クラス追加の基本手順(全機能共通)

1. `app/`の該当するディレクトリに、1ファイル1クラスで`final class`を作る(PSR-4: `TmswpStarterTheme\Post\NewsPost` → `app/Post/NewsPost.php`)。
2. 投稿タイプ・タクソノミー・ロール・独自テーブル・フックは、機能に対応するAttribute(`#[AsPostType]`等)を付けてFWの基底クラスを継承し、サイト設定値は`#[AsOptionGroup]`のbacked enumにする(レシピのとおりにする)。フォーム(`FormDefinition`で定義して`Form`を返すクラス)と前処理クラスは、Attributeも基底クラスも無い`final class`で、使う場所から明示的に呼ぶ(form.md・page.md)。
3. **登録のコードは書かない。** Attributeを付けたクラスは、`app/`に置けばKernelが探索して登録する(functions.phpへの追記は要らない)。独自ブロックは、`build/blocks/`の`block.json`から登録される(blocks.md)。
4. `composer discover`(ブロックを変えた場合は`npm run build`)で探索結果ファイル(テーマのルートの`tmswp-discovery.php`)を作り直し、クラスと一緒にコミットする。開発環境(Kernelのdebugが真。省略すると`WP_DEBUG`の値)は起動のたびに`app/`と`build/blocks/`を走査するため、作り直す前でも反映される。本番は探索結果ファイルだけを読む。
5. `composer qa`を通す。
6. WordPress上で動作を確かめる(各レシピの「確認方法」)。

## 必須コマンド

```bash
composer qa         # lint → analyse → discover:check → test。タスクを終える前に必ず通す
composer lint       # コードスタイルの検査(php-cs-fixerのdry-run)
composer fix        # コードスタイルの自動修正
composer analyse    # PHPStan level max(app/が対象)
composer test       # ユニットテスト(tests/Unit/)
composer discover   # 探索結果ファイルを作り直す(wp tmswp discover)
npm run build       # blocks/をbuild/blocks/にビルドし、続けて探索結果ファイルを作り直す
```

- このプロジェクトの`composer test`はユニットテストだけを指す(統合テストは既定で持たない)。
- スターターのユニットテストはBrain Monkey(`brain/monkey`)だけで書く構成で、`tmswp/testing`は入っていない。Kernelをbootするテスト(メタ・オプションのメモリのストレージ等を使うもの)を書くときは、`composer require --dev tmswp/testing`で入れ、`vendor/tmswp/testing/rules/testing.md`を読む。
- ユニットテストは、`app/`のディレクトリの構成をミラーして`tests/Unit/`に置く(`app/Hook/ThemeSetupHook.php`のテストは`tests/Unit/Hook/ThemeSetupHookTest.php`で、名前空間は`TmswpStarterTheme\Tests\Unit\Hook\`)。例の`ThemeSetupHookTest`のとおり、`PHPUnit\Framework\TestCase`を継承して`MockeryPHPUnitIntegration`を使い(Brain Monkeyの期待をアサーションとして数える)、`setUp()`で`Brain\Monkey\setUp()`を、`tearDown()`で`Brain\Monkey\tearDown()`を呼ぶ。フックのメソッドを直接呼び、WordPressの関数の呼び出しを`Brain\Monkey\Functions\expect()`で、戻り値を`assertSame()`等で確かめる。
- `phpunit.xml.dist`は、テストが1件も無いとき(`failOnEmptyTestSuite`)と、risky・警告・非推奨・Noticeを出したテストやテスト中の出力があるときに、`composer test`を失敗にする。
- `composer qa`は探索結果ファイルの照合(`wp tmswp discover --check`)を含む。`composer discover`・`npm run build`とともに、WP-CLIの`wp`がこのテーマを有効にしたサイトに届く場所で実行する。
- `npm run build`の前に一度`npm ci`でビルドの道具を入れる。

## コーディング規約

- すべてのPHPファイル(テンプレートを含む)に`declare(strict_types=1);`を置く。
- `app/`のクラスは`final`にし、プロパティ・引数・戻り値のすべてに型を宣言する。マジックメソッド(`__call`・`__callStatic`・`__get`・`__set`)と`extract()`・`eval()`は使わない。
- メタのキーは`#[MetaSchema]`付きのクラス定数で、オプションのキーは`#[OptionSchema]`付きのenumのケースで宣言し、キーの文字列リテラルをコードに直接書かない。
- 頻繁に読むメタ・設定の値は、定義のクラスに明示のアクセサ(例: `ProductPost::price($post): int`)を書く。
- テンプレートでは、ファイルの先頭の`use`文でクラスを読み込んでよい。
- 出力は`esc_html()`・`esc_attr()`・`esc_url()`でエスケープする。FWのフォームの描画(`$form->open()`、`$form->field('name')`の`input()`・`label()`・`errors()`・`display()`等のHTMLを返すメソッド)はエスケープ済みなので、そのまま`echo`してよい。`value()`は生の値なので、出力するときはエスケープする。
- テキストドメインはこのテーマのもの(`{{project_slug}}`。style.cssの`Text Domain`)を使い、FWのドメイン`tmswp`を使わない。
- ブロックの名前は`tmswp-starter/<名前>`の形にする(`blocks/example/block.json`の`name`と同じ名前空間)。
- コードスタイルは`.php-cs-fixer.dist.php`(TMSWPのmonorepoと同じ規則)に従う。テンプレート(テンプレート階層のファイル・`parts/`・ブロックの`render.php`)はlintの対象外だが、`declare(strict_types=1);`とエスケープの規約は守る。

## 禁止事項(違反はレビューで差し戻す)

1. **`vendor/`の編集。** FWの挙動を変えたいときは、フック・`tmswp-kernel.php`の構成・基底クラスの継承で行う。それでもできなければ、FW本体への課題として報告する。
2. **FWのコードのコピーと改変。** `vendor/tmswp/*`のクラスを`app/`へ写して変えない。
3. **WordPress標準のテンプレート階層の迂回。** `template_include`でテンプレートを差し替えない。`exit`でWordPressのテンプレートの読み込みを止めない(フォームのPOSTの処理の後の`wp_safe_redirect()`の直後だけは例外)。ページの出し分けは、テンプレート階層(`page-{slug}.php`等)と条件分岐タグで行う。
4. **登録系のWordPressの関数の直接の呼び出し。** `register_post_type`・`register_taxonomy`・`register_post_meta`・`add_role`・`register_block_type`を`app/`やfunctions.phpで直接呼ばない。FWの定義のクラスを使う(該当のレシピ)。
5. **`$wpdb`の直接の利用。** 独自テーブルはModel(model.md)を使う。WordPress標準のテーブルは`WP_Query`等の標準のAPIで読む。
6. **functions.phpへのロジックの追加。** functions.phpはFWのブートだけにする。フックは`app/Hook/`(hooks.md)へ書く。
7. **生成物の手での編集。** `tmswp-discovery.php`は`wp tmswp discover`の、`build/blocks/`は`npm run build`の生成物である。手で編集せず、`app/`・`blocks/`を変えたら作り直してコミットする。本番は探索結果ファイルが無いと起動時にエラーになり、古いと追加・変更したクラスとブロックが反映されない。

## デバッグ

- **開発環境は`WP_DEBUG`を有効にする。** Kernelのdebug(`tmswp-kernel.php`の`KernelConfig`の`debug`。省略すると`WP_DEBUG`の値)が真なら、設定の誤りと実行時のデータの取得の失敗が例外になり、起動のたびに`app/`と`build/blocks/`を走査する。debugが偽(本番)では、実行時の失敗をKernelのロガーに`error`で記録して既定値で続ける。
- **ログ:** `\TMSWP\Core\Log\Log::info('message', ['context' => $value]);`で書く(処理の種類を示すときは`Log::scope('mail')->error(...)`)。どのチャンネル(出力先)に書くかは、`tmswp-kernel.php`の`logChannels`の各`LogChannel`の`levels`・`scopes`で決める。宣言したチャンネルへ直接書くときは`Log::channel('<名前>')`を使う(宣言していない名前は例外になる)。スターターの`SimpleLogger`は既定で`wp-content/tmswp-logs/`に書く。`var_dump`・`print_r`をコミットに残さない。
- **クラスを置いたのに有効にならない:** 次の順に確かめる。
  1. クラス名とファイルのパスがPSR-4で一致しているか(`app/Post/NewsPost.php`なら`TmswpStarterTheme\Post\NewsPost`)。
  2. Attribute(`#[AsPostType]`等)を付け忘れていないか。
  3. `wp tmswp discover --check`で探索結果ファイルが最新か。古ければ`composer discover`で作り直す。
- **探索結果ファイルとキャッシュ:** FWは探索の結果をtransient・オブジェクトキャッシュに持たず、探索結果ファイルだけを使う。そのため、消すべきFWのキャッシュは無く、反映されないときは探索結果ファイルを作り直す。`wp tmswp discover`は、そのサイトで起動したすべてのKernel(このテーマと、TMSWPを使うプラグイン)の探索結果ファイルを作り直し、書いたファイルのOPcacheを無効にする。FTPで上げた探索結果ファイルが本番で反映されない場合は、サーバーのOPcacheが更新を検知しない設定(`opcache.validate_timestamps`が0等)になっていないかを確かめ、OPcacheを空にする。ページキャッシュのプラグインを使うサイトでは、デプロイの後にページキャッシュも消す。
- **起動時のエラー:** 同じサイトのTMSWPの版が食い違う場合(別のテーマ・プラグインが別の版の`tmswp/core`を入れている等)と、他のプラグインが互換の無い`psr/log`・`psr/container`を先に読み込んだ場合は、Kernelが起動時に明示のエラーにする。メッセージに従って版をそろえる。

## 完了条件

実装の後は`composer qa`を通し、WordPress上で表示・保存・CLIの実行を確かめる(各レシピの「確認方法」)。
````

### 5.2 plugin版(starters/plugin/AGENTS.md)— theme版との差分

plugin版はtheme版と同一の章立て・文面とし、次の箇所だけを差し替える。

1. 冒頭文・見出し・本文の「テーマ」を「プラグイン」に、名前空間`TmswpStarterTheme\`を`TmswpStarterPlugin\`にする。
2. 「最初に読むもの」: 対応表から「テンプレートの前処理」の行(page.md)を除き、表の後の拡張のレシピの段落に続けて「プラグインはトップレベルのテンプレートを持たないため、テンプレートの前処理クラス(`vendor/tmswp/core/rules/page.md`)は使わない」を置く。
3. 「プロジェクト構成」のツリーを次に差し替え、ツリーの後の`languages/`の段落をプラグインの決まり(本体ファイルのヘッダの`Domain Path: /languages`で読み込まれ、名前は`<テキストドメイン>-<locale>.mo`・`.l10n.php`、`WP_LANG_DIR`は`wp-content/languages/plugins/`)にし、「プラグインのスターターはブロックのビルドの設定(`blocks/`・`package.json`)を持たない。ブロックを置くときは、blocks.mdの手順で足す」を置く。

```
.
├── {{project_slug}}.php     # プラグインの本体ファイル(プラグインヘッダとFWのブートだけ。ロジックを書かない)
├── tmswp-kernel.php        # Kernelの構成(KernelConfig::plugin())を返す。拡張・ログ・ブロックカテゴリはここに書く。WordPressの関数を呼ばない
├── tmswp-discovery.php     # 探索結果ファイル(wp tmswp discoverの生成物。コミットする)
├── templates/              # プラグイン同梱のテンプレート(Template::part()の読み込み先。テーマに同じ名前のファイルがあればテーマが優先される)
├── languages/              # このプラグインの翻訳ファイル(<テキストドメイン>-<locale>.mo・.l10n.php。例: {{project_slug}}-ja.mo)
├── app/                    # PHPのクラス(名前空間 TmswpStarterPlugin\、PSR-4)
│   ├── Hook/               # フック(*Hook。#[AsHook])
│   ├── Post/               # 投稿タイプ(*Post。#[AsPostType])
│   ├── Tax/                # タクソノミー(*Tax。#[AsTaxonomy])
│   ├── User/               # ユーザーロール(*Role。#[AsUserRole])
│   ├── Form/               # フォーム(*Form)
│   ├── Model/              # 独自テーブル(*Model。#[AsModel])
│   ├── Option/             # サイト設定値(*Option。#[AsOptionGroup]のbacked enum)
│   └── SettingsPage/       # 設定ページ(tmswp/settings-pageを入れたとき)
├── tests/Unit/             # ユニットテスト(composer test)
├── composer.json
├── phpstan.neon
├── phpunit.xml.dist
└── .php-cs-fixer.dist.php
```

4. 「ロジックの置き場(配置決定木)」: theme版の1(前処理クラス)と、決定木の後の前処理クラスの段落を除く。最後の項を「**それ以外のWordPressのフックの処理**(ショートコード、テンプレートの外で要る前処理を含む) → `app/Hook/`の`#[AsHook]`クラス(hooks.md。ショートコードのフォームの駆動はform.md)」にする。
5. 「クラス追加の基本手順」: 手順2の前処理クラスの記述を除いてフォームを呼ぶ場所を「フックのクラス等」にし(page.mdの参照も除く)、手順3の「functions.phpへの追記」を「本体ファイルへの追記」にし、手順4の探索結果ファイルの作り直しは`composer discover`だけにする(`npm run build`の記述を除く)。
6. 「必須コマンド」: `npm run build`の行と、`npm ci`の注記を除く。ユニットテストの置き場と例は`app/Hook/PluginSetupHook.php`・`tests/Unit/Hook/PluginSetupHookTest.php`・`PluginSetupHookTest`にし、例の`PluginSetupHookTest`は処理を持たない雛形のフックに対する出発点(Brain Monkeyの準備の形だけを示し、フックに処理を足したら`Functions\expect()`・`assertSame()`等で確かめる形に書き換える)と書く。
7. 「コーディング規約」: テキストドメインを「本体ファイルのヘッダの`Text Domain`」にし、ブロックの名前の項を除き、lintの対象外を`templates/`にする。
8. 「禁止事項」: テンプレート階層の迂回の項は「プラグインでも`template_include`でテンプレートを差し替えない」とし、ページの出し分けの文を除く。「独自のマイグレーション」(独自テーブルの定義は`wp tmswp migrate`で適用し、プラグインの有効化では適用しない。マイグレーションのために`register_activation_hook`を直接書かない)を足す。functions.phpの項は本体ファイルの項にし、生成物の項から`build/blocks/`を除く。
9. 「デバッグ」: 探索結果ファイルとキャッシュの項のKernelの例示を「このプラグインと、TMSWPを使うテーマ・ほかのプラグイン」に、起動時のエラーの項の例示を「テーマ・ほかのプラグインが別の版の`tmswp/core`を入れている等」にし、起動時のエラーに「同じサイトの別のプラグインと名前空間が同じ場合も、Kernelが起動時のエラーにする」を足す。
10. 「完了条件」: 「表示・保存」を「有効化・保存」にする。

---

## 6. rules/ ディレクトリ構成と共通フォーマット

### 6.1 構成

`packages/core/rules/`に以下14ファイルを置く。tmswp/coreパッケージの配布物に含める(composer.jsonのarchive excludeに入れない)。

| ファイル | 対象タスク | 主依存文書 |
|---|---|---|
| post-type.md | CPT追加 | 03-entities |
| taxonomy.md | タクソノミー追加 | 03-entities |
| user-role.md | ユーザーロール追加 | 03-entities |
| meta.md | 非ACFカスタムフィールド | 04-meta-options |
| acf.md | acf-json運用+型付きアクセサ生成 | 09-acf-bridge |
| form.md | フォーム実装(3画面+メール) | 05-form |
| form-mail.md | フォームの完了処理のメール送信(ヘッダインジェクション対策・自動返信・失敗時のログ) | 05-form |
| page.md | テンプレート前処理(テンプレートの先頭で呼ぶ前処理クラス) | 07-support |
| model.md | 独自テーブル | 06-model |
| options.md | サイト設定値 | 04-meta-options |
| hooks.md | WPフック登録の置き場 | 02-kernel |
| view.md | テンプレートパーツ運用 | 07-support |
| blocks.md | 独自ブロック(置き場・npmのビルドと探索コマンドの連結・対象外) | 02-kernel・03-entities |
| extensions.md | 拡張パッケージ導入(拡張のレシピへの入口) | 08-extensions |

拡張パッケージの使い方のレシピは、その拡張パッケージの`rules/`に同じ共通フォーマット(§6.2)で置き、そのパッケージの配布物に含める。

| ファイル | 対象タスク | 主依存文書 |
|---|---|---|
| packages/acf-bridge/rules/sync-check.md | acf-bridgeの生成アクセサの生成と照合(`tms-acf sync`・`tms-acf check`) | 09-acf-bridge |
| packages/settings-page/rules/settings-page.md | 管理画面の設定ページ | 08-extensions |
| packages/thumbnail/rules/thumbnail.md | 署名付きのURLのサムネイル | 08-extensions |
| packages/ja-toolkit/rules/ja-toolkit.md | 日本語サイト向けの機能 | 08-extensions |
| packages/testing/rules/testing.md | テストの支援コード(テストの基底クラスとテストダブル) | 10-quality |

### 6.2 各レシピの共通フォーマット(厳守)

```
# <タスク名>
このレシピでできること(1〜3行)
## 手順         … 番号付き。ファイルパス・クラス名・名前空間を確定値で示す
## 完全な実装例  … コピーで動く全文コード
## テンプレートでの使い方 … (該当する場合)
## してはいけないこと … アンチパターン列挙
## 確認方法      … composer qa + WP上での目視確認手順
## 発展         … 他レシピ・設計書への参照(任意)
```

レシピ内のコード例の名前空間は`App\`とする。スターターから作ったプロジェクトの名前空間は、create-projectのsetup.phpがスラッグから生成するため(§10)、プロジェクトごとに違う。各レシピの冒頭に、`App\`をプロジェクトの名前空間(`KernelConfig`の`appNamespace`)に読み替える旨を1行で示す。スターターのAGENTS.mdも、そのプロジェクトの名前空間と読み替えを示す(§5)。

レシピの本文は各パッケージの `rules/*.md` が正本であり、本文書は構成と共通フォーマットだけを定める。レシピのコード例が担当文書(02〜10)の確定APIと食い違う場合は **担当文書を正としてレシピ側を修正** する。拡張パッケージ固有のレシピは、そのパッケージの `rules/` に同じ共通フォーマットで置く。

### 6.3 レシピ検証CI(コード例ドリフトの機械検出)

rules/*.md内のコード例とFWのAPIの乖離(ドリフト)は、10-quality.mdのGitHub Actionsワークフロー(QA)に置くCIジョブ`rules-verify`で機械検出する。方式は次のとおり:

1. **抽出**: `tools/extract-rules-code.php`(monorepo同梱。vendor非依存・PHP標準関数のみ)が各パッケージの`rules/*.md`(`packages/*/rules/*.md`)から、`<?php`で始まる```` ```php ````フェンスのコードブロックを抽出する。`namespace App\…`の宣言を含むブロックを「クラス定義例」とみなし、`var/rules-code/app/`配下へPSR-4整合のパス(例: `App\Post\NewsPost` → `var/rules-code/app/Post/NewsPost.php`)で書き出す。それ以外のブロック(テンプレートの断片・functions.php等)は`var/rules-code/templates/`へ`<レシピ名>-<連番>.php`で書き出す。
2. **検証**: `tools/verify-rules-code.php`が、抽出したすべてのPHPファイルの構文を`php -l`で確かめる。クラス定義例(`var/rules-code/app/`)は`var/rules-analysis/app/`へ写し、専用の設定(level max、szepeviktor/phpstan-wordpressとtmswp/coreのPHPStan拡張、ACF Proのスタブ)のPHPStanで解析する。テンプレートの断片はPHPStanの対象外(§8.6の方針と同一)。どちらかの失敗でジョブを失敗させる。
3. **実行契機**: QAワークフローの対象(すべてのPRとmainへのpush)。

これにより「コアのpublic API変更にrules/のコード例が追従していない」状態をマージ前に継続検出する(§12.2・§12.5の検収項目に対応)。このジョブはスターターを展開せず、スターター自身の`composer lint`・`composer analyse`も実行しない。starters/のPHPは、monorepoの`composer lint`(各スターターの`app/`・`tests/`と、テンプレートでないルートのPHP(テーマの`functions.php`・`tmswp-kernel.php`、プラグインの`plugin.php`・`tmswp-kernel.php`)と`bin/setup.php`。テンプレートとビルドの出力(`build/`)は含めない。ルートの`.php-cs-fixer.dist.php`のFinder)と`composer analyse`(`starters/theme/app`・`starters/plugin/app`・`starters/theme/bin/setup.php`)の対象であり、API変更時の追従は禁止事項(§4)のgrepでも確かめる。

---

## 7. レシピの正本

レシピの本文は各パッケージの `rules/*.md` が正本であり、本文書には複製を置かない(§1.2・§6)。

---

## 8. starter-theme(tmswp/starter-theme)

本節と§9の全文は、`starters/theme`・`starters/plugin`の実物と同じ内容である。

### 8.1 ファイルツリー

```
starters/theme/
├── AGENTS.md                 # §5.1 全文
├── README.md                 # §8.7 全文(人間向け導入文書)
├── composer.json             # §8.2
├── package.json              # §8.8(ブロックのビルドと探索コマンドの連結)
├── package-lock.json         # package.jsonのlock(npm ci)
├── style.css                 # §8.3(プレースホルダ入り)
├── functions.php             # §8.4(FWブートのみ)
├── tmswp-kernel.php          # §8.4(Kernelの構成)
├── tmswp-discovery.php       # スターターのまま`wp tmswp discover`で作った探索結果ファイル(setup.phpが削除する)
├── index.php                 # §8.5(最小のWP標準ループ)
├── header.php                # 最小(<!doctype html>〜wp_head〜<body>とサイト名)
├── footer.php                # 最小(サイト名〜wp_footer〜</html>)
├── parts/
│   └── .gitkeep
├── assets/
│   └── css/
│       └── main.css          # 最小のCSS(ThemeSetupHookがenqueueする実在ファイル)
├── blocks/
│   └── example/              # §8.8(サンプルの動的ブロックのソース)
│       ├── block.json
│       ├── index.js
│       └── render.php
├── build/
│   └── blocks/
│       └── example/          # blocks/exampleのビルドの出力(npm run build。コミットする)
│           ├── block.json
│           ├── index.asset.php
│           ├── index.js
│           └── render.php
├── languages/
│   └── .gitkeep
├── app/
│   ├── .gitkeep
│   ├── Hook/
│   │   └── ThemeSetupHook.php   # §8.9(動くサンプル兼雛形)
│   ├── Post/.gitkeep
│   ├── Tax/.gitkeep
│   ├── User/.gitkeep
│   ├── Form/.gitkeep
│   ├── Model/.gitkeep
│   └── Option/.gitkeep
├── tests/
│   ├── bootstrap.php         # §8.6(require vendor/autoload のみ)
│   └── Unit/
│       └── Hook/
│           └── ThemeSetupHookTest.php  # ThemeSetupHookの例のユニットテスト(Brain Monkey。利用者のテストの手本)
├── bin/
│   └── setup.php             # §10.2(post-create-project-cmd。実行後に自己削除)
├── phpunit.xml.dist          # §8.6
├── phpstan.neon              # §8.6
├── .php-cs-fixer.dist.php    # §8.6
├── .gitignore                # §8.7
└── .gitattributes            # §8.7
```

方針: スケルトンの `app/` は **動くサンプル1つ(ThemeSetupHook)+空ディレクトリ構造** とする。サンプルCPT等は置かない(消す手間と「消し忘れた雛形CPTが本番に出る」事故を避ける。AIはrules/のコード例から生成する)。ブロックは、ビルドと探索の流れを示すサンプル1つ(`blocks/example`)とそのビルドの出力を置く。テンプレートの前処理クラスの`app/Page/`は、使うときに作る(AGENTS.mdのツリーに示す)。

### 8.2 composer.json(全文)

```json
{
  "name": "tmswp/starter-theme",
  "description": "Starter theme for TMStack WordPress Framework.",
  "type": "project",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.3",
    "tmswp/core": "^0.9"
  },
  "require-dev": {
    "brain/monkey": "^2.6",
    "friendsofphp/php-cs-fixer": "^3.88",
    "phpstan/extension-installer": "^1.4",
    "phpstan/phpstan": "^2.1",
    "phpunit/phpunit": "^11.5",
    "szepeviktor/phpstan-wordpress": "^2.0"
  },
  "autoload": {
    "psr-4": {
      "TmswpStarterTheme\\": "app/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "TmswpStarterTheme\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "lint": "php-cs-fixer fix --dry-run --diff",
    "fix": "php-cs-fixer fix",
    "analyse": "phpstan analyse --memory-limit=1G",
    "discover": "wp tmswp discover",
    "discover:check": "wp tmswp discover --check",
    "test": "phpunit",
    "qa": [
      "@lint",
      "@analyse",
      "@discover:check",
      "@test"
    ],
    "post-create-project-cmd": [
      "@php bin/setup.php",
      "@composer dump-autoload"
    ]
  },
  "config": {
    "allow-plugins": {
      "phpstan/extension-installer": true
    },
    "optimize-autoloader": true,
    "sort-packages": true
  }
}
```

- `name` はPackagist登録名のまま配布し、create-project後に `bin/setup.php` が `local/<slug>` へ書き換える(§10)。licenseは、スターター自身はGPL-2.0-or-laterで、setup.phpが作ったプロジェクトでは`proprietary`にする。
- `autoload`・`autoload-dev`の名前空間`TmswpStarterTheme\`は、setup.phpがスラッグから作った名前空間に書き換える(§10)。
- `phpstan/extension-installer` がPHPStan拡張(szepeviktor/phpstan-wordpress と tmswp/core 同梱拡張)を自動登録する(10-quality.md §7 項目2)。`allow-plugins` を事前記載しているため、create-project後の `composer install` は承認プロンプトなしで完了する。
- `tmswp/core`の制約は、リリースのときに`monorepo-builder release`がその版の`^X.Y`に同期する(10-quality.md §6.2)。
- `qa`は`lint` → `analyse` → `discover:check` → `test`の順で、`discover:check`(`wp tmswp discover --check`)は探索結果ファイルの照合である。`discover`・`discover:check`は、WP-CLIの`wp`がこのテーマを有効にしたサイトに届く場所で実行する(10-quality.md §7の項目5)。
- `post-create-project-cmd`は、`bin/setup.php`と、書き換えた名前空間でオートローダを作り直す`composer dump-autoload`の2つである(§10.1)。
- 統合テスト(`test:integration`)はスターターには含めない(利用側プロジェクトのテストはユニット層のみを既定とする。必要なプロジェクトはコアのwp-env構成を参考に追加する)。したがってスターターの `test` はユニット層のみを指す(コアmonorepoとの範囲差はAGENTS.mdに注記済み。§2参照)。
- スターターはtmswp/acf-bridgeに依存しないため、`acf:sync`・`acf:check`のscriptsを持たない(依存の無い`tms-acf`を呼ぶscriptsがあると、既定のまま「command not found」になるため)。ACFを使うプロジェクトは、テーマ・プラグイン(§9.2のとおりプラグインも同じ)で次の3つを行う。
  1. `composer require tmswp/acf-bridge`を実行する。
  2. `tmswp-kernel.php`(§8.4・§9.3)の`KernelConfig`の`extensions`に`new \TMSWP\AcfBridge\AcfBridge()`を足す。
  3. composer.jsonの`scripts`に、次の`acf:sync`・`acf:check`を足す(`tms-acf`はacf-bridgeが`vendor/bin`に置く。09-acf-bridge §5.1)。

```json
{
    "scripts": {
        "acf:check": "tms-acf check",
        "acf:sync": "tms-acf sync"
    }
}
```

### 8.3 style.css(全文)

```css
/*
Theme Name: {{project_name}}
Description: Built with TMStack WordPress Framework.
Version: 0.1.0
Requires at least: 7.0
Requires PHP: 8.3
Text Domain: {{project_slug}}
*/
```

### 8.4 tmswp-kernel.php・functions.php(全文)

Kernelの構成はテーマのルートの`tmswp-kernel.php`が返し、functions.phpは`KernelConfig::load()`で読んだその構成でbootする(02-kernel利用例)。WordPressを起動しない`tms-acf`(09-acf-bridge §5.1)も`tmswp-kernel.php`を読むため、このファイルではWordPressの関数を呼ばない(拡張のコンストラクタも同じ)。`appNamespace`はsetup.phpがスラッグから作った名前空間に書き換え、`labelLanguage`はスターターのラベル言語の既定(日本語)を指定する。

`tmswp-kernel.php`:

```php
<?php

declare(strict_types=1);

// テーマのKernelの構成。functions.phpのブートと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。
// WordPressの関数はここで呼ばない(拡張のコンストラクタも同じ)。
// 名前空間(appNamespace)は、create-projectのときにbin/setup.phpがスラッグから決める
return TMSWP\Core\Kernel\KernelConfig::theme(
    appDir: __DIR__ . '/app',
    appNamespace: 'TmswpStarterTheme',
    logChannels: static function (): array {
        return [
            'default' => new TMSWP\Core\Log\LogChannel(new TMSWP\Core\Log\SimpleLogger()),
        ];
    },
    // labelLanguage()を指定しない投稿タイプ・タクソノミーの定義のラベルを、日本語で作る
    labelLanguage: TMSWP\Core\Label\LabelLanguage::Ja,
);
```

`functions.php`:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

TMSWP\Core\Kernel\Kernel::boot(TMSWP\Core\Kernel\KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
```

### 8.5 index.php(全文。WP標準そのもの)

```php
<?php

declare(strict_types=1);

get_header();
?>
<main>
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : ?>
            <?php the_post(); ?>
            <article>
                <h2>
                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                </h2>
                <?php the_excerpt(); ?>
            </article>
        <?php endwhile; ?>
        <?php the_posts_pagination(); ?>
    <?php else : ?>
        <p>記事がありません。</p>
    <?php endif; ?>
</main>
<?php
get_footer();
```

### 8.6 QA設定ファイル(全文)

`phpstan.neon`:

```neon
parameters:
  level: max
  paths:
    - app
  treatPhpDocTypesAsCertain: false
```

- `includes` の手動列挙は不要。PHPStan拡張(szepeviktor/phpstan-wordpress、およびFW同梱の `#[MetaSchema]`/`#[OptionSchema]` 型ナローイング拡張=DR-05)は、§8.2 require-devの `phpstan/extension-installer` が設定なしで自動登録する(10-quality.md §7 項目2の確定方式。vendor配下の拡張パスをスターター側が知る必要がなく、コア側の配置変更にも自動追従する)。
- 解析対象は `app/` のみ。テンプレートファイル(WPグローバル文脈)は対象外とする。

`.php-cs-fixer.dist.php`:

```php
<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/*
 * TMSWPのmonorepoと同じ規則(PER Coding Style 2.0と、final_class・strict_comparison等の厳密性の規則)で検査する。
 * 対象はapp/・tests/と、テンプレートでないテーマのルートのPHP(functions.php・tmswp-kernel.php)である。
 * テーマのテンプレート(header.php・footer.php・index.php等のテンプレート階層のファイル、parts/、ブロックの
 * render.php)は、WordPress標準のテーマの書き方を保つため対象に含めない。
 */
$finder = (new Finder())
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->append([
        __FILE__,
        __DIR__ . '/functions.php',
        __DIR__ . '/tmswp-kernel.php',
    ]);

return (new Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'void_return' => true,
        'final_class' => true,
        'nullable_type_declaration_for_default_null_value' => true,
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
        'array_syntax' => ['syntax' => 'short'],
        'list_syntax' => ['syntax' => 'short'],
        'modernize_strpos' => true,
        'modernize_types_casting' => true,
        'get_class_to_class_keyword' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'single_quote' => true,
        'binary_operator_spaces' => true,
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'phpdoc_separation' => true,
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'php_unit_data_provider_static' => true,
        'php_unit_data_provider_return_type' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
    ]);
```

- 規則はmonorepoの`.php-cs-fixer.dist.php`と同じ(10-quality.md §3.1の`setRules()`。`native_function_invocation`の`exclude`だけはFWのKernelの事情のため持たない。10-quality.md §3.2)。
- 対象は`app/`・`tests/`と、テンプレートでないルートのPHP(`functions.php`・`tmswp-kernel.php`)である。テンプレート(テンプレート階層のファイル・`parts/`・ブロックの`render.php`)とビルドの出力(`build/`)は対象に含めない。

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnEmptyTestSuite="true"
         failOnRisky="true"
         failOnWarning="true"
         failOnDeprecation="true"
         failOnNotice="true"
         beStrictAboutOutputDuringTests="true"
         displayDetailsOnTestsThatTriggerDeprecations="true"
         displayDetailsOnTestsThatTriggerNotices="true"
         displayDetailsOnTestsThatTriggerWarnings="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- `failOnRisky`から`displayDetailsOnTestsThatTriggerWarnings`までの属性は、packages/*/phpunit.xml.dist(10-quality.md §4.3)と同じである。加えて`failOnEmptyTestSuite`で、テストが1件も無いときも失敗にする。
- テストの例は`tests/Unit/Hook/ThemeSetupHookTest.php`(プラグインは`PluginSetupHookTest.php`)で、tmswp/testingを使わず、Brain MonkeyとPHPUnitの`TestCase`だけで書く。Brain Monkeyの準備(`Brain\Monkey\setUp()`・`tearDown()`)はテストのクラスの`setUp()`・`tearDown()`で行い、`tests/bootstrap.php`には要らない(Brain MonkeyとPatchworkは、Composerのオートローダが読み込む)。

`tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
```

### 8.7 README.md(全文)

人間の開発者向けの導入文書として必須で同梱する。規約の真実源はAGENTS.mdであり、READMEは導線に徹する(規約本文を転載して二重管理しない)。以下のフェンス内が全文で、`starters/theme/README.md`の実物と同じ内容である。

````markdown
# {{project_name}}

TMStack WordPress Framework を使用したWordPressテーマ。

<!-- tmswp-starter-setup -->
## 新規プロジェクトの作成

新規プロジェクトはこの雛形から作成する。作成の直後に`bin/setup.php`が、スラッグから名前空間(例: `my-shop` → `MyShop\`)を作り、composer.json・`tmswp-kernel.php`・`app/`・AGENTS.md・ブロックの名前を書き換えて、自身を削除する。

```bash
composer create-project tmswp/starter-theme my-theme
```

スラッグ(英小文字・数字で、英字で始まり、語を1つの`-`か`_`でつなぐ)と表示名は、端末では対話で尋ねる。端末の無い環境(CI等)では、環境変数で渡す。渡さなければエラーで止まる。

```bash
TMSWP_SETUP_SLUG=my-theme TMSWP_SETUP_NAME="My Project" composer create-project --no-interaction tmswp/starter-theme my-theme
```

`composer create-project --no-scripts`で作成した場合と、セットアップがエラーで止まった場合は、`bin/setup.php`を手で実行し、書き換えた名前空間でオートローダを作り直す(create-projectは、この2つを続けて実行する)。

```bash
php bin/setup.php --slug=my-theme --name="My Project"
composer dump-autoload
```

<!-- /tmswp-starter-setup -->

## セットアップの後

`WP_DEBUG`を有効にしたWordPressのサイト(開発環境)でこのテーマを有効にし、WP-CLIがそのサイトに届く場所で次を実行する。探索結果ファイル(`tmswp-discovery.php`)を作り、コミットする。探索結果ファイルを作るまでは、`WP_DEBUG`が無効のサイトでは起動時にエラーになる(debugのときだけ`app/`を毎回走査する)。

```bash
composer install
npm ci
npm run build    # ブロックのビルドと、探索結果ファイルの作成(wp tmswp discover)
composer qa
```

## 開発規約

- 開発規約・禁止事項・必須コマンド: [AGENTS.md](./AGENTS.md)
- タスク別レシピ: `vendor/tmswp/core/rules/*.md`(どのレシピを読むかはAGENTS.mdの「最初に読むもの」)
- 拡張のレシピ: 拡張を入れると`vendor/tmswp/<拡張>/rules/*.md`に入る
````

- `{{project_name}}`はsetup.phpの置換対象(§10.1)。
- `<!-- tmswp-starter-setup -->`から`<!-- /tmswp-starter-setup -->`までの節(スターターからの作り方と`bin/setup.php`の手順)は、setup.phpが消す(§10.1)。作ったプロジェクトのREADME.mdは、見出しと1文の後に「セットアップの後」の節が続く。この目印の2行は、setup.phpが探す文字列なので変えない。
- plugin版は、「WordPressテーマ」を「WordPressプラグイン」、`starter-theme`・`my-theme`を`starter-plugin`・`my-plugin`に差し替え、setup.phpが書き換えるものの列挙を「composer.json・本体ファイルの名前(`plugin.php`→`<スラッグ>.php`)・`tmswp-kernel.php`・`app/`・AGENTS.md」にし、「セットアップの後」のコマンドの`npm ci`・`npm run build`の2行を`composer discover    # 探索結果ファイルの作成(wp tmswp discover)`の1行にした同一構成とする。

`.gitignore`:

```
/vendor/
/node_modules/
/composer.lock
/.php-cs-fixer.cache
/.phpunit.result.cache
/.phpunit.cache/
```

- 探索結果ファイル`tmswp-discovery.php`(テーマのルート直下。02-kernel.md §6)と`build/blocks/`はコミットしてデプロイする生成物のため、.gitignoreで除外しない。
- plugin版は`/node_modules/`の行を持たない(プラグインのスターターにはpackage.jsonが無い)。

`.gitattributes`:

```
# create-projectの配布物(読み取り専用リポジトリのzipと、Composerのpathリポジトリの写し)から、ビルドの道具とOSのファイルを外す。
# build/blocks/と探索結果ファイル(tmswp-discovery.php)は、作ったプロジェクトがそのまま動くために要るので外さない
/node_modules export-ignore
.DS_Store export-ignore
```

- plugin版のコメントは1行目だけで、末尾の「。」と、2行目(`build/blocks/`と探索結果ファイルを外さない理由)が無い。作ったプロジェクトが使う`tests/`・`build/blocks/`・探索結果ファイルは外さない(10-quality.md §6.2)。

### 8.8 ブロックのサンプルとpackage.json(全文)

ブロックのソースは`blocks/<slug>/`、ビルドの出力は`build/blocks/<slug>/`に置く(rules/blocks.md)。`npm run build`は、`@wordpress/scripts`でビルドし、続けて探索コマンド(`wp tmswp discover`)で探索結果ファイルを作り直す。ブロック名の名前空間`tmswp-starter/`とテキストドメインの`{{project_slug}}`は、setup.phpがスラッグから書き換える(blocks/とbuild/blocks/のblock.json・ビルドの出力のJavaScript・render.php。§10.1)。

`package.json`:

```json
{
  "private": true,
  "scripts": {
    "start": "wp-scripts start --source-path=blocks --output-path=build/blocks",
    "build": "wp-scripts build --source-path=blocks --output-path=build/blocks && npm run discover",
    "discover": "wp tmswp discover"
  },
  "devDependencies": {
    "@wordpress/scripts": "36.1.0"
  },
  "allowScripts": {
    "@parcel/watcher": false,
    "core-js": false,
    "core-js-pure": false,
    "fsevents": false,
    "unrs-resolver": false
  }
}
```

`blocks/example/block.json`:

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "tmswp-starter/example",
  "title": "Example",
  "category": "widgets",
  "description": "An example dynamic block rendered by render.php.",
  "textdomain": "{{project_slug}}",
  "editorScript": "file:./index.js",
  "render": "file:./render.php"
}
```

`blocks/example/index.js`:

```js
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import metadata from './block.json';

// 表示はrender.php(サーバー)が作るため、エディタでは目印だけを出し、保存する内容は持たない
registerBlockType(metadata.name, {
    edit: () => <p {...useBlockProps()}>{metadata.title}</p>,
    save: () => null,
});
```

`blocks/example/render.php`:

```php
<?php

declare(strict_types=1);

/*
 * サンプルのブロックの動的描画。WordPressがblock.jsonのrenderから読み込み、$attributes・$content・$blockを渡す。
 */
?>
<p <?php echo get_block_wrapper_attributes(); ?>><?php echo esc_html__('This is an example block.', '{{project_slug}}'); ?></p>
```

- `build/blocks/example/`は`npm run build`の出力をそのままコミットする(`block.json`・`render.php`はソースの写し、`index.js`・`index.asset.php`はビルドの生成物)。CIのStarter Projectsのジョブが、ビルドの出力と探索結果ファイルがコミット済みのものと同じかを確かめる。

### 8.9 app/Hook/ThemeSetupHook.php(全文)

```php
<?php

declare(strict_types=1);

namespace TmswpStarterTheme\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class ThemeSetupHook extends Hook
{
    #[Action('after_setup_theme')]
    public function setupTheme(): void
    {
        add_theme_support('post-thumbnails');
        add_theme_support('title-tag');
        add_theme_support('html5', ['search-form', 'gallery', 'caption']);
    }

    #[Action('wp_enqueue_scripts')]
    public function enqueueAssets(): void
    {
        wp_enqueue_style(
            'app-main',
            get_theme_file_uri('assets/css/main.css'),
            [],
            (string) wp_get_theme()->get('Version'),
        );
    }

    #[Filter('excerpt_more')]
    public function excerptMore(string $more): string
    {
        return '...';
    }
}
```

---

## 9. starter-plugin(tmswp/starter-plugin)

### 9.1 ファイルツリー

```
starters/plugin/
├── AGENTS.md                 # §5.2(theme版との差分適用済み全文)
├── README.md                 # §8.7のplugin版
├── composer.json             # §9.2(theme版との差分のみ記載)
├── plugin.php                # §9.3(プラグイン本体。setup.phpが <slug>.php にリネームする)
├── tmswp-kernel.php          # §9.3(Kernelの構成。setup.phpが本体ファイルのパスを書き換える)
├── tmswp-discovery.php       # スターターのまま`wp tmswp discover`で作った探索結果ファイル(setup.phpが削除する)
├── templates/
│   └── .gitkeep              # プラグイン同梱テンプレート(Template::part のフォールバック先)
├── languages/
│   └── .gitkeep
├── app/
│   ├── .gitkeep
│   ├── Hook/
│   │   └── PluginSetupHook.php  # §9.4(動くサンプル兼雛形)
│   ├── Post/.gitkeep
│   ├── Tax/.gitkeep
│   ├── User/.gitkeep
│   ├── Form/.gitkeep
│   ├── Model/.gitkeep
│   ├── Option/.gitkeep
│   └── SettingsPage/.gitkeep
├── tests/
│   ├── bootstrap.php         # theme版と同一
│   └── Unit/
│       └── Hook/
│           └── PluginSetupHookTest.php  # 雛形のPluginSetupHookの例のユニットテスト(Brain Monkeyの準備の形を示す出発点)
├── bin/
│   └── setup.php             # theme版と同一スクリプト(§10.2)
├── phpunit.xml.dist          # theme版と同一
├── phpstan.neon              # theme版と同一
├── .php-cs-fixer.dist.php    # §9.5
├── .gitignore                # §8.7(plugin版)
└── .gitattributes            # §8.7(plugin版)
```

プラグインのスターターは、ブロックのサンプルとビルドの設定(`blocks/`・`build/`・`package.json`)を持たない。プラグインにブロックを置く場合は、rules/blocks.mdの手順で足す(探索とKernelの登録は、テーマと同じくプラグインのルートの`build/blocks/`を対象にする)。

### 9.2 composer.json(theme版との差分)

- `"name": "tmswp/starter-plugin"`、`"description": "Starter plugin for TMStack WordPress Framework."`
- autoloadは`TmswpStarterPlugin\`・`TmswpStarterPlugin\Tests\`を`app/`・`tests/`へ割り当てる。setup.phpがスラッグから作った名前空間に書き換えるため、同じサイトでテーマと複数のプラグインを併用できる(§10)。
- それ以外(license・require・require-dev・scripts・config)はtheme版(§8.2)と同一。

### 9.3 tmswp-kernel.php・plugin.php(全文)

プラグイン本体のパス(`file:`)は`tmswp-kernel.php`の位置から決める(`__FILE__`は使わない)。プラグインのbootとtms-acfのどちらから読んでも同じ値になる。本体ファイルのパスはスターターの名前のまま`'/plugin.php'`と書き、setup.phpが本体を`<slug>.php`にリネームするときに、同じスラッグで`'/<slug>.php'`に書き換える(§10)。

`tmswp-kernel.php`:

```php
<?php

declare(strict_types=1);

// プラグインのKernelの構成。本体ファイルのブートと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。
// 本体ファイルのパスはこのファイルの位置から決めるため、どちらの経路でも同じ値になる。
// WordPressの関数はここで呼ばない(拡張のコンストラクタも同じ)。
// 本体ファイルの名前と名前空間(appNamespace)は、create-projectのときにbin/setup.phpがスラッグから決める
return TMSWP\Core\Kernel\KernelConfig::plugin(
    file: __DIR__ . '/plugin.php',
    appDir: __DIR__ . '/app',
    appNamespace: 'TmswpStarterPlugin',
    logChannels: static function (): array {
        return [
            'default' => new TMSWP\Core\Log\LogChannel(new TMSWP\Core\Log\SimpleLogger()),
        ];
    },
    // labelLanguage()を指定しない投稿タイプ・タクソノミーの定義のラベルを、日本語で作る
    labelLanguage: TMSWP\Core\Label\LabelLanguage::Ja,
);
```

`plugin.php`:

```php
<?php

/**
 * Plugin Name: {{project_name}}
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Text Domain: {{project_slug}}
 * Domain Path: /languages
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

TMSWP\Core\Kernel\Kernel::boot(TMSWP\Core\Kernel\KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
```

### 9.4 app/Hook/PluginSetupHook.php(全文)

```php
<?php

declare(strict_types=1);

namespace TmswpStarterPlugin\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class PluginSetupHook extends Hook
{
    public function register(): void {}
}
```

### 9.5 .php-cs-fixer.dist.php(全文)

theme版(§8.6)との違いは、先頭のコメントと`->append()`の対象(`functions.php`の代わりに本体ファイル`plugin.php`)である。`'/plugin.php'`はsetup.phpが`'/<slug>.php'`に書き換えるため、setup.phpの実行の前と後のどちらでも、実在するファイルだけを参照する。

```php
<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/*
 * TMSWPのmonorepoと同じ規則(PER Coding Style 2.0と、final_class・strict_comparison等の厳密性の規則)で検査する。
 * 対象はapp/・tests/と、プラグインの本体ファイル・tmswp-kernel.phpである。templates/のテンプレートは、
 * WordPress標準の書き方を保つため対象に含めない。
 * 本体ファイルの名前(plugin.php)は、bin/setup.phpがファイルの名前とともに<スラッグ>.phpに書き換える。
 */
$finder = (new Finder())
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->append([
        __FILE__,
        __DIR__ . '/plugin.php',
        __DIR__ . '/tmswp-kernel.php',
    ]);

return (new Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'void_return' => true,
        'final_class' => true,
        'nullable_type_declaration_for_default_null_value' => true,
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
        'array_syntax' => ['syntax' => 'short'],
        'list_syntax' => ['syntax' => 'short'],
        'modernize_strpos' => true,
        'modernize_types_casting' => true,
        'get_class_to_class_keyword' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'single_quote' => true,
        'binary_operator_spaces' => true,
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'phpdoc_separation' => true,
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'php_unit_data_provider_static' => true,
        'php_unit_data_provider_return_type' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
    ]);
```

---

## 10. composer create-project フロー

### 10.1 利用者(人間/AI)の手順

```bash
# テーマ(wp-content/themes/ 直下で実行)
composer create-project tmswp/starter-theme my-shop-theme

# プラグイン(wp-content/plugins/ 直下で実行)
composer create-project tmswp/starter-plugin my-shop-tools

# 端末の無い環境(CI・AIエージェント)では、スラッグと表示名を環境変数で渡す
TMSWP_SETUP_SLUG=my-shop-tools TMSWP_SETUP_NAME="My Shop Tools" \
    composer create-project --no-interaction tmswp/starter-plugin my-shop-tools
```

create-project完了時に`post-create-project-cmd`(`bin/setup.php`と、続けて`composer dump-autoload`)が自動実行され、次が行われる:

1. **値の決定**:スラッグと表示名を、引数(`--slug=`・`--name=`)、環境変数(`TMSWP_SETUP_SLUG`・`TMSWP_SETUP_NAME`)、対話の入力の順に探す。対話の入力は標準入力が端末のときだけ使い、既定値として、スラッグには設置ディレクトリ名(スラッグの形のときだけ)を、表示名にはスラッグの各語の先頭を大文字にしたもの(例: `my-shop-tools` → `My Shop Tools`)を示す。端末が無い(CI・AIエージェント)場合は、値が無ければ既定値を使わずにエラーで止まる。`post-create-project-cmd`には引数を渡せないため、create-projectでは環境変数で渡す。
2. **検査**:次のどれかに当たればエラーで止まり、プロジェクトを書き換えない。
   - スラッグが、英小文字・数字で、英字で始まり、語を1つの`-`か`_`でつなぐ形(`^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$`)でない(正規化はしない)。末尾の`-`や`__`を拒むのは、composer.jsonの`name`(`local/<slug>`)をComposerの名前の規則に合わせるためである。
   - スラッグから作る名前空間(次項)が、PHPの予約語(マニュアルのList of KeywordsとOther reserved words。大文字と小文字を区別しない)か、TMSWPの名前空間`TMSWP`と同じ。PHPの名前空間は大文字と小文字を区別しないため、`Tmswp`も`TMSWP`と同じとみなす。
   - 表示名が空か、制御文字か`*/`(style.cssとプラグインの本体ファイルのヘッダのコメントを閉じる)を含む。
   - 知らない引数か、同じ引数が2回以上ある。composer.jsonのautoloadのpsr-4で`app/`に割り当てた名前空間が1つでない(setup.phpを実行済みの場合を含む)。プラグインの本体ファイルの名前を変えた先に、ファイルがすでにある。
3. **名前空間の生成**:スラッグを`-`・`_`で区切り、各語の先頭を大文字にして連結する(例: `my-shop-plugin` → `MyShopPlugin`、`client_theme` → `ClientTheme`)。ブロック名の名前空間は、スラッグの`_`を`-`にしたもの(ブロック名は英小文字・数字・`-`だけで作るため)。
4. **書き換え**:プロジェクトのテキストのファイル(vendor/・node_modules/・.git/の下を除く)で、次を置き換える。書き換えた内容をすべて作ってから書き込み、composer.jsonは最後に書く(途中で失敗しても、composer.jsonがスターターの名前空間のまま残り、もう一度実行すれば残りを書き換えられる)。
   - スターターの名前空間(composer.jsonのautoloadのpsr-4で`app/`に割り当てた`TmswpStarterTheme`・`TmswpStarterPlugin`)を、生成した名前空間に。識別子の全体だけを置き換える。composer.jsonのpsr-4(autoload・autoload-dev)、`tmswp-kernel.php`の`appNamespace`、`app/`のクラスの`namespace`、AGENTS.mdの名前空間とレシピの読み替えの記述が変わる。
   - `{{project_name}}`・`{{project_slug}}`を、表示名・スラッグに(style.css、プラグインの本体ファイルのヘッダ、README.md、block.jsonの`textdomain`、render.phpのテキストドメイン)。
   - スターターのブロック名の名前空間`tmswp-starter/`を、ブロック名の名前空間に(blocks/とbuild/blocks/のblock.jsonと、ビルドの出力のJavaScript)。
   - `'/plugin.php'`を`'/<slug>.php'`に(プラグインの`tmswp-kernel.php`の本体ファイルのパスと、`.php-cs-fixer.dist.php`)。
   - README.mdの`<!-- tmswp-starter-setup -->`から`<!-- /tmswp-starter-setup -->`までの節(スターターからの作り方と、`bin/setup.php`の手順)を消す。作ったプロジェクトには`bin/setup.php`が無いためである。
   - npmのパッケージの名前(テーマのみ。プラグインのスターターにはpackage.jsonが無い)を、スラッグにする。package.jsonには`name`が無いため先頭に足し、package-lock.jsonは先頭の`name`(スターターを作ったディレクトリの名前`theme`のまま残っている)と、ルートのパッケージ(`packages[""]`)の`name`を変える。npmが`name`のあるpackage.jsonから書くのと同じ形にするためである。スラッグの形(英小文字・数字・`-`・`_`で、英字で始まる)は、npmの名前の規則に合う。字下げは元のファイルのものを保つ。ルートのパッケージの無いpackage-lock.json(npm 6以前の形式)は、エラーで止まる。
5. **本体リネーム**(プラグインのみ): `plugin.php` → `<slug>.php`。
6. **探索結果ファイルの削除**:スターターの`tmswp-discovery.php`は、書き換える前の名前空間とブロック名で作ったものなので消す。
7. **useの並びの整え**: プロジェクトの`vendor/bin/php-cs-fixer`があれば、プロジェクトの`.php-cs-fixer.dist.php`で`fix`を実行する(キャッシュは使わない)。名前空間を書き換えると、自分の名前空間と依存の名前空間の`use`の並び(`ordered_imports`の規則)が崩れることがあり(例: スターターの`TmswpStarterTheme\`を`ClientTheme\`にすると、`PHPUnit\`より前に並ぶ)、そのままでは`composer lint`が通らないためである。composer.jsonを書く前に行うため、php-cs-fixerが失敗してエラーで止まっても、直した後でもう一度実行できる。create-projectは依存を入れた後で`post-create-project-cmd`を実行するため、php-cs-fixerはある。`--no-install`で作った場合は無いため実行せず、依存を入れた後の`composer fix`の実行を案内する。
8. **composer.json書き換え**:上の置き換えに加えて、`name`を`local/<slug>`、`description`を`<表示名> (WordPress theme)`(プラグインは`(WordPress plugin)`。スターターの説明`Starter theme for …`は作ったプロジェクトに当てはまらない)、`license`を`proprietary`に変更し、`config.autoloader-suffix`を生成した名前空間にし、`post-create-project-cmd`エントリを削除する。字下げは元のcomposer.jsonのものを保つ。`autoloader-suffix`は、Composerのオートローダのクラス名(`ComposerAutoloaderInit<suffix>`)の接尾辞である。既定の接尾辞は、create-projectが依存を入れた時点のcomposer.lockのハッシュで、同じ版のスターターから作ったプロジェクトどうしで同じになる。そのため、同じスターターから作ったプラグインを同じサイトで有効にすると、クラスの重複で止まる。スターター自身のlicenseはGPL-2.0-or-later(Packagistに公開してcreate-projectで使うため)で、作った案件のプロジェクトはクライアントの非公開のコードのため`proprietary`にする。スターターにLICENSEのファイルは置かない。
9. **自己削除**: `bin/setup.php`と、空になった`bin/`を削除する(再実行事故の防止)。
10. **composer.lockの更新**: create-projectが作った`composer.lock`があれば、Composer(`COMPOSER_BINARY`。Composerがスクリプトに渡す)で`composer update --lock --no-install`を実行し、書き換えたcomposer.jsonのハッシュを記録し直す(パッケージの版は変えない。`composer validate`がlockの古さを報告しないため)。`--no-install`で作った場合は`composer.lock`が無いため、何もしない。Composerから実行していない(手で実行した)場合は、`composer update --lock`の実行を案内する。
11. 次の一手を標準出力に案内する。オートローダの作り直し(`composer dump-autoload`。php-cs-fixerを実行しなかった場合は`composer fix`も)、`WP_DEBUG`を有効にしたサイト(開発環境)でテーマ・プラグインを有効にしての探索結果ファイルの作成(テーマは`npm ci && npm run build`、プラグインは`composer discover`。どちらも`wp tmswp discover`を実行する)とコミット、`composer qa`、AGENTS.mdである。
12. **オートローダの作り直し**: Composerは依存を入れてオートローダを作った後で`post-create-project-cmd`を実行するため、その時点のオートローダ(psr-4とclassmap)はスターターの名前空間のままである。`post-create-project-cmd`は、setup.phpに続けて`@composer dump-autoload`を実行し、書き換えた名前空間で作り直す。Composerはスクリプトの一覧を読み込み済みのため、setup.phpがcomposer.jsonから`post-create-project-cmd`を消しても、続きは実行される。

探索結果ファイルは、setup.phpの中では作り直さず、作り直す手順を案内する。`wp tmswp discover`は、テーマ・プラグインを有効にしたWordPressのサイトとWP-CLIを要する(探索コマンドは起動中のKernelを走査する。02-kernel.md §6)が、create-projectの時点では、プロジェクトがサイトの中にあるとも、サイトのDBが動いているとも限らないためである。debug(開発環境)のbootは毎回`app/`と`build/blocks/`を走査するため、オートローダを作り直した後(手順12)は、探索結果ファイルを作り直す前でも動く。debugでないサイト(本番)は、作り直すまで探索結果ファイルが無いことを起動時のエラーで示す。`wp tmswp discover`は起動したKernelを走査するため、debugでないサイトでは作り直すこともできない。そのため、作り直しは`WP_DEBUG`を有効にしたサイトで行う。スターターの`composer qa`は探索結果ファイルの照合(`discover:check`。`wp tmswp discover --check`)を含むため、作り直すまで失敗する。

`composer create-project --no-scripts`で作った場合と、setup.phpがエラーで止まった場合は、`php bin/setup.php --slug=<slug> --name=<name>`と`composer dump-autoload`を手で実行する(`composer.lock`があれば、`composer update --lock`も)。

### 10.2 bin/setup.php(全文)

```php
<?php

declare(strict_types=1);

/*
 * composer create-projectで作ったテーマ・プラグインを、案件のスラッグで初期化する。
 *
 * create-projectのpost-create-project-cmdが1回だけ実行し、最後に自分(bin/setup.php)を消す。Composerは依存を入れて
 * オートローダを作った後でこれを実行するため、post-create-project-cmdは続けて`composer dump-autoload`を実行し、
 * 書き換えた名前空間でオートローダを作り直す(Composerはスクリプトの一覧を読み込み済みのため、ここでcomposer.jsonから
 * post-create-project-cmdを消しても続きは実行される)。
 * テーマとプラグインのスターターで同じ内容で、Composerのvendor/のクラスを読み込まない(PHPの標準関数だけを使う)。
 * 書き換えの後に、vendor/bin/php-cs-fixerがあれば別のプロセスで実行し、名前空間が変わって崩れたuseの並びを整える。
 *
 * 値は、引数(--slug=・--name=)、環境変数(TMSWP_SETUP_SLUG・TMSWP_SETUP_NAME)、対話の入力の順に探す。
 * 対話の入力は標準入力が端末のときだけ使い、そうでなければ(CI・エージェント)、値が無い時点でエラーで止まる。
 */

const SETUP_SLUG_ENV = 'TMSWP_SETUP_SLUG';
const SETUP_NAME_ENV = 'TMSWP_SETUP_NAME';
/** スターターのブロック名の名前空間。スラッグから作るブロックの名前空間に書き換える */
const SETUP_STARTER_BLOCK_NAMESPACE = 'tmswp-starter';
/**
 * 生成した名前空間に使えないPHPの予約語(PHPのマニュアルのList of Keywordsと、Other reserved words)。
 * スラッグから作れない語(`_`を含む`__halt_compiler`・`include_once`・`require_once`と、2語の`yield from`)は除く
 */
const SETUP_RESERVED_WORDS = [
    'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue',
    'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
    'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach',
    'function', 'global', 'goto', 'if', 'implements', 'include', 'instanceof', 'insteadof', 'interface', 'isset',
    'list', 'match', 'namespace', 'new', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly',
    'require', 'return', 'self', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
    'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never',
    'resource', 'numeric',
];
/** 書き換えの対象から外すディレクトリ(プロジェクトのルートからの相対パス) */
const SETUP_SKIPPED_DIRECTORIES = ['.git', 'node_modules', 'vendor'];

try {
    setupProject(dirname(__DIR__), commandLineArguments());
} catch (InvalidArgumentException | RuntimeException $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}

/**
 * 値を決めて検査し、すべて揃ってからプロジェクトを書き換える。
 *
 * @param list<string> $arguments
 *
 * @throws InvalidArgumentException 引数・入力の値が不正な場合
 * @throws RuntimeException ファイルの読み書きに失敗した場合
 */
function setupProject(string $root, array $arguments): void
{
    $options = parseArguments($arguments);
    $slug = $options['slug'] ?? environmentValue(SETUP_SLUG_ENV);
    $name = $options['name'] ?? environmentValue(SETUP_NAME_ENV);
    $interactive = stream_isatty(STDIN);

    if (!$interactive && ($slug === null || $name === null)) {
        $missing = implode(' and ', array_keys(array_filter(['slug' => $slug === null, 'name' => $name === null])));

        throw new InvalidArgumentException("the project {$missing} must be given without a terminal (bin/setup.php does not ask and has no default): "
            . 'pass --slug=<slug> --name=<name>, or set ' . SETUP_SLUG_ENV . ' and ' . SETUP_NAME_ENV);
    }

    $slug ??= prompt('Project slug', isSlug(basename($root)) ? basename($root) : null);
    assertSlug($slug);
    $namespace = namespaceFromSlug($slug);
    $name ??= prompt('Project name', ucwords(str_replace(['-', '_'], ' ', $slug)));
    assertName($name);

    $composerFile = $root . '/composer.json';
    $composer = readComposerJson($composerFile);
    $starterNamespace = starterNamespace($composer);
    $pluginFile = $root . '/plugin.php';
    $renamedPluginFile = $root . '/' . $slug . '.php';

    if (is_file($pluginFile) && $pluginFile !== $renamedPluginFile && file_exists($renamedPluginFile)) {
        throw new RuntimeException("cannot rename plugin.php: {$renamedPluginFile} already exists");
    }

    $replacements = [
        '{{project_name}}' => $name,
        '{{project_slug}}' => $slug,
        "'/plugin.php'" => "'/{$slug}.php'",
    ];
    $patterns = [
        // 名前空間は識別子の全体だけを置き換える(別の識別子の一部は変えない)
        '/(?<![A-Za-z0-9_])' . $starterNamespace . '(?![A-Za-z0-9_])/' => $namespace,
        '#(?<![a-z0-9-])' . SETUP_STARTER_BLOCK_NAMESPACE . '/#' => str_replace('_', '-', $slug) . '/',
        // README.mdのスターターの作り方とセットアップの節は、作ったプロジェクトには残さない
        '#<!-- tmswp-starter-setup -->.*?<!-- /tmswp-starter-setup -->\n*#s' => '',
    ];

    // 書き換えた内容をすべて作ってから書き込む。composer.jsonは最後に書くため、途中で失敗しても、
    // composer.jsonがスターターの名前空間のまま残り、もう一度実行すれば残りを書き換えられる
    $rewrites = [];

    foreach (projectFiles($root) as $file) {
        $contents = readTextFile($file);
        $updated = rewriteContents($file, $contents, $replacements, $patterns);

        if ($updated !== $contents) {
            $rewrites[$file] = $updated;
        }
    }

    // npmのパッケージの名前をスラッグにする(package.jsonには無いため足す)。package-lock.jsonの名前は、スターターを
    // 作ったディレクトリの名前のまま残っているため
    foreach (['package.json', 'package-lock.json'] as $npmFileName) {
        $npmFile = $root . '/' . $npmFileName;

        if (is_file($npmFile)) {
            $npmSource = $rewrites[$npmFile] ?? readTextFile($npmFile);
            $rewrites[$npmFile] = encodeJson($npmFile, withNpmName($npmFile, decodeJsonObject($npmFile, $npmSource), $slug), $npmSource);
        }
    }

    $kind = is_file($root . '/style.css') ? 'theme' : 'plugin';
    $composerSource = $rewrites[$composerFile] ?? readTextFile($composerFile);
    $composer = decodeJsonObject($composerFile, $composerSource);
    $composer->name = 'local/' . $slug;
    // スターターの説明(Starter theme for ...)は、作ったプロジェクトには当てはまらない
    $composer->description = "{$name} (WordPress {$kind})";
    $composer->license = 'proprietary';
    // Composerのオートローダのクラス名(ComposerAutoloaderInit<suffix>)の接尾辞を、名前空間にする。既定の接尾辞は、
    // create-projectが依存を入れた時点のcomposer.lockのハッシュ(同じ版のスターターから作ったプロジェクトどうしで同じ)で、
    // 同じスターターから作ったプラグインを同じサイトで有効にすると、クラスの重複で止まるため
    if (!isset($composer->config) || !$composer->config instanceof stdClass) {
        $composer->config = new stdClass();
    }

    $composer->config->{'autoloader-suffix'} = $namespace;

    if (isset($composer->scripts) && $composer->scripts instanceof stdClass) {
        unset($composer->scripts->{'post-create-project-cmd'});
    }

    $composerJson = encodeJson($composerFile, $composer, $composerSource);
    unset($rewrites[$composerFile]);

    foreach ($rewrites as $file => $contents) {
        writeTextFile($file, $contents);
    }

    if (is_file($pluginFile) && $pluginFile !== $renamedPluginFile && !rename($pluginFile, $renamedPluginFile)) {
        throw new RuntimeException("cannot rename {$pluginFile} to {$renamedPluginFile}");
    }

    // スターターの探索結果ファイルは、書き換える前の名前空間とブロック名で作ったもので、もう使えない
    $discoveryFile = $root . '/tmswp-discovery.php';

    if (is_file($discoveryFile) && !unlink($discoveryFile)) {
        throw new RuntimeException("cannot delete {$discoveryFile}");
    }

    // composer.jsonを書く前に整える。php-cs-fixerが失敗しても、もう一度実行すれば整え直せる
    $formatted = formatPhpFiles($root);
    writeTextFile($composerFile, $composerJson);

    if (!unlink(__FILE__)) {
        throw new RuntimeException('cannot delete ' . __FILE__);
    }

    if (scandir(__DIR__) === ['.', '..'] && !rmdir(__DIR__)) {
        throw new RuntimeException('cannot delete ' . __DIR__);
    }

    $lockStep = refreshLockFile($root)
        ? ''
        : "\n               Then run `composer update --lock`: composer.lock records the hash of the old composer.json.";
    $fixStep = $formatted
        ? ''
        : "\n               Then run `composer fix`: the imports may be out of order after the namespace was rewritten.";
    $build = $kind === 'theme' ? 'npm ci && npm run build' : 'composer discover';
    fwrite(STDOUT, <<<TEXT

          Initialized {$name} (slug: {$slug}, namespace: {$namespace}\\).

          Next steps:
            1. Run `composer dump-autoload` so that the autoloader uses the new namespace
               (`composer install` if the dependencies are not installed yet).
               composer create-project runs `composer dump-autoload` right after this script.{$lockStep}{$fixStep}
            2. Work on a WordPress site with WP_DEBUG enabled (a development environment): tmswp-discovery.php
               does not exist yet, and without WP_DEBUG the kernel fails to boot until it exists.
               Activate this {$kind}, then run `{$build}` where WP-CLI can reach the site.
               It runs `wp tmswp discover`, which writes tmswp-discovery.php. Commit that file.
            3. Run `composer qa`.
            4. Read AGENTS.md before you add code.

        TEXT);
}

/**
 * @throws RuntimeException コマンドラインから実行していない場合
 *
 * @return list<string>
 */
function commandLineArguments(): array
{
    $argv = $_SERVER['argv'] ?? null;

    if (!is_array($argv)) {
        throw new RuntimeException('run bin/setup.php from the command line: php bin/setup.php');
    }

    $arguments = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!is_string($argument)) {
            throw new RuntimeException('command line arguments must be strings');
        }

        $arguments[] = $argument;
    }

    return $arguments;
}

/**
 * @param list<string> $arguments
 *
 * @throws InvalidArgumentException 知らない引数がある場合
 *
 * @return array{slug: string|null, name: string|null}
 */
function parseArguments(array $arguments): array
{
    $options = ['slug' => null, 'name' => null];

    foreach ($arguments as $argument) {
        if (preg_match('/^--(slug|name)=(.*)$/s', $argument, $matches) !== 1) {
            throw new InvalidArgumentException("unknown argument: {$argument}. Usage: php bin/setup.php [--slug=<slug>] [--name=<name>]");
        }

        if ($options[$matches[1]] !== null) {
            throw new InvalidArgumentException("--{$matches[1]} is given more than once");
        }

        $options[$matches[1]] = $matches[2];
    }

    return $options;
}

/**
 * 環境変数の値を返す。設定されていなければnullを返す。
 */
function environmentValue(string $name): ?string
{
    $value = getenv($name);

    return $value === false ? null : $value;
}

/**
 * 端末で値を尋ねる。空の入力は既定値(無ければ空の文字列)にする。
 *
 * @throws RuntimeException 端末から読めない場合
 */
function prompt(string $label, ?string $default): string
{
    fwrite(STDOUT, $default === null ? "{$label}: " : "{$label} [{$default}]: ");
    $line = fgets(STDIN);

    if ($line === false) {
        throw new RuntimeException("cannot read the {$label} from the terminal");
    }

    $input = trim($line);

    return $input === '' ? (string) $default : $input;
}

/**
 * スラッグの形(英小文字・数字で、英字で始まり、語を1つの'-'か'_'でつなぐ)かを返す。
 * composer.jsonのname(local/<slug>)がComposerの名前の規則に合う形に限る。
 */
function isSlug(string $slug): bool
{
    return preg_match('/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/D', $slug) === 1;
}

/**
 * @throws InvalidArgumentException スラッグの形が違う場合
 */
function assertSlug(string $slug): void
{
    if (!isSlug($slug)) {
        throw new InvalidArgumentException("invalid slug '{$slug}': use lowercase letters and digits starting with a letter, joining words with a single '-' or '_'");
    }
}

/**
 * スラッグを'-'と'_'で区切り、各語の先頭を大文字にして連結する(例: my-shop-plugin → MyShopPlugin)。
 *
 * @throws InvalidArgumentException 名前空間がPHPの予約語か、TMSWPと同じ場合
 */
function namespaceFromSlug(string $slug): string
{
    $namespace = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
    $lower = strtolower($namespace);

    if (in_array($lower, SETUP_RESERVED_WORDS, true)) {
        throw new InvalidArgumentException("slug '{$slug}' makes the namespace '{$namespace}', which is a reserved word in PHP; choose another slug");
    }

    // PHPの名前空間は大文字と小文字を区別しないため、TmswpもTMSWPの名前空間と同じになる
    if ($lower === 'tmswp') {
        throw new InvalidArgumentException("slug '{$slug}' makes the namespace '{$namespace}', which is the namespace of TMSWP; choose another slug");
    }

    return $namespace;
}

/**
 * 名前はstyle.cssとプラグインのヘッダのコメントに入るため、コメントを閉じる'*\/'と制御文字を拒む。
 *
 * @throws InvalidArgumentException 名前が不正な場合
 */
function assertName(string $name): void
{
    if (trim($name) === '' || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || str_contains($name, '*/')) {
        throw new InvalidArgumentException("invalid project name '{$name}': it must not be empty or contain control characters or '*/'");
    }
}

/**
 * @throws RuntimeException 読めない・JSONのオブジェクトでない場合
 */
function readComposerJson(string $file): stdClass
{
    return decodeJsonObject($file, readTextFile($file));
}

/**
 * @throws RuntimeException JSONのオブジェクトでない場合
 */
function decodeJsonObject(string $file, string $json): stdClass
{
    try {
        $composer = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("cannot parse {$file}: {$exception->getMessage()}", 0, $exception);
    }

    if (!$composer instanceof stdClass) {
        throw new RuntimeException("{$file} is not a JSON object");
    }

    return $composer;
}

/**
 * composer.jsonのautoloadのpsr-4でapp/に割り当てた名前空間(スターターの名前空間)を返す。
 *
 * @throws RuntimeException 見つからない場合
 */
function starterNamespace(stdClass $composer): string
{
    $psr4 = isset($composer->autoload) && $composer->autoload instanceof stdClass ? ($composer->autoload->{'psr-4'} ?? null) : null;
    $namespaces = [];

    if ($psr4 instanceof stdClass) {
        foreach (get_object_vars($psr4) as $prefix => $path) {
            if ($path === 'app/' && preg_match('/^([A-Za-z][A-Za-z0-9]*)\\\\$/', (string) $prefix, $matches) === 1) {
                $namespaces[] = $matches[1];
            }
        }
    }

    if (count($namespaces) !== 1) {
        throw new RuntimeException('composer.json must map exactly one namespace to app/ in autoload.psr-4; bin/setup.php has already run or composer.json was changed');
    }

    return $namespaces[0];
}

/**
 * 書き換えの対象のファイル(vendor/・node_modules/・.git/と、このファイルを除く)を返す。
 *
 * @return list<string>
 */
function projectFiles(string $root): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $current): bool => !$current->isDir()
                || !in_array(substr($current->getPathname(), strlen($root) + 1), SETUP_SKIPPED_DIRECTORIES, true),
        ),
    );
    $files = [];

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getPathname() !== __FILE__) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * テキストのファイルの内容の文字列を置き換えた結果を返す。NULを含むファイル(バイナリ)はそのまま返す。
 *
 * @param array<string, string> $replacements 文字列 => 置き換え後
 * @param array<string, string> $patterns 正規表現 => 置き換え後
 *
 * @throws RuntimeException 置き換えに失敗した場合
 */
function rewriteContents(string $file, string $contents, array $replacements, array $patterns): string
{
    if (str_contains($contents, "\0")) {
        return $contents;
    }

    $updated = preg_replace(array_keys($patterns), array_values($patterns), strtr($contents, $replacements));

    if ($updated === null) {
        throw new RuntimeException("cannot rewrite {$file}: " . preg_last_error_msg());
    }

    return $updated;
}

/**
 * プロジェクトのvendor/bin/php-cs-fixerを、プロジェクトの.php-cs-fixer.dist.phpで実行する(lintの対象のPHPを整える)。
 * 名前空間を書き換えると、useの並び(ordered_importsの規則)が崩れることがあるため。実行したかどうかを返す。
 * php-cs-fixerが無い(create-projectの--no-installで依存を入れていない)場合は、実行せずにfalseを返す。
 *
 * @throws RuntimeException php-cs-fixerが失敗した場合
 */
function formatPhpFiles(string $root): bool
{
    $fixer = $root . '/vendor/bin/php-cs-fixer';

    if (!is_file($fixer)) {
        return false;
    }

    $process = proc_open(
        [PHP_BINARY, $fixer, 'fix', '--config=' . $root . '/.php-cs-fixer.dist.php', '--using-cache=no'],
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        $root,
    );

    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('php-cs-fixer failed to format the rewritten PHP files; fix the error and run bin/setup.php again');
    }

    return true;
}

/**
 * composer create-projectが作ったcomposer.lockに、書き換えたcomposer.jsonのハッシュを記録し直す(パッケージの版は
 * 変えない)。Composerから実行されたとき(COMPOSER_BINARYがある)だけ行い、行ったかどうかを返す。
 * composer.lockが無い(create-projectの--no-install)場合は、記録し直すものが無いためtrueを返す。
 *
 * @throws RuntimeException Composerが失敗した場合
 */
function refreshLockFile(string $root): bool
{
    if (!is_file($root . '/composer.lock')) {
        return true;
    }

    $composerBinary = getenv('COMPOSER_BINARY');

    if ($composerBinary === false || $composerBinary === '') {
        return false;
    }

    $process = proc_open(
        [PHP_BINARY, $composerBinary, 'update', '--lock', '--no-install', '--no-interaction', '--working-dir=' . $root],
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
    );

    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('cannot update the hash in composer.lock; run `composer update --lock`');
    }

    return true;
}

/**
 * npmのパッケージの名前を$nameにしたものを返す。nameは先頭に置く(npmが書く順と同じ)。package-lock.jsonは、先頭の
 * nameと、ルートのパッケージ(packages[""])のnameの両方を変える(npmは、package.jsonのnameを両方に書く)。
 *
 * @throws RuntimeException package-lock.jsonにルートのパッケージが無い場合
 */
function withNpmName(string $file, stdClass $package, string $name): stdClass
{
    $renamed = withFirstProperty($package, 'name', $name);

    if (basename($file) !== 'package-lock.json') {
        return $renamed;
    }

    $packages = isset($renamed->packages) && $renamed->packages instanceof stdClass ? get_object_vars($renamed->packages) : [];
    $rootPackage = $packages[''] ?? null;

    if (!$rootPackage instanceof stdClass) {
        throw new RuntimeException("{$file} has no root package (packages[\"\"]); regenerate it with npm 7 or later");
    }

    $packages[''] = withFirstProperty($rootPackage, 'name', $name);
    $renamed->packages = (object) $packages;

    return $renamed;
}

/**
 * $keyを$valueにして、先頭に置いたオブジェクトを返す(ほかのプロパティの順は保つ)。
 */
function withFirstProperty(stdClass $object, string $key, string $value): stdClass
{
    $properties = get_object_vars($object);
    unset($properties[$key]);

    return (object) ([$key => $value] + $properties);
}

/**
 * JSONのオブジェクトを、元のファイルの内容($source)と同じ字下げで書いたJSON(末尾に改行)を返す。
 *
 * @throws RuntimeException JSONにできない場合
 */
function encodeJson(string $file, stdClass $data, string $source): string
{
    try {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("cannot encode {$file}: {$exception->getMessage()}", 0, $exception);
    }

    return reindentJson($file, $json, jsonIndent($source)) . "\n";
}

/**
 * JSONの1段の字下げ(最初に字下げした行の空白)を返す。字下げが無ければ4つの空白を返す。
 */
function jsonIndent(string $json): string
{
    return preg_match('/^([ \t]+)\S/m', $json, $matches) === 1 ? $matches[1] : '    ';
}

/**
 * json_encode()のJSON_PRETTY_PRINT(4つの空白)の字下げを、$indentに変える。
 *
 * @throws RuntimeException 置き換えに失敗した場合
 */
function reindentJson(string $file, string $json, string $indent): string
{
    $reindented = preg_replace_callback(
        '/^(?: {4})+/m',
        static fn(array $matches): string => str_repeat($indent, intdiv(strlen($matches[0]), 4)),
        $json,
    );

    if ($reindented === null) {
        throw new RuntimeException("cannot indent {$file}: " . preg_last_error_msg());
    }

    return $reindented;
}

/**
 * @throws RuntimeException 読めない場合
 */
function readTextFile(string $file): string
{
    $contents = file_get_contents($file);

    if ($contents === false) {
        throw new RuntimeException("cannot read {$file}");
    }

    return $contents;
}

/**
 * @throws RuntimeException 書けない場合
 */
function writeTextFile(string $file, string $contents): void
{
    if (file_put_contents($file, $contents) === false) {
        throw new RuntimeException("cannot write {$file}");
    }
}
```

実装上の注意:

- スターターのcomposer.json上の `name` はPackagist配布名のまま(プレースホルダにするとPackagist登録・create-project解決が壊れる)。ローカル名への書き換えは必ずsetup.php側で行う。
- スターターのコードの名前空間とブロック名は、プレースホルダではなく実在の名前(`TmswpStarterTheme`・`TmswpStarterPlugin`・`tmswp-starter/`)にする。スターターのままで構文・PHPStan・探索コマンドを通し、スターターの探索結果ファイルを生成できるようにするためである。
- `composer create-project --no-scripts`で実行された場合はsetup.phpが走らない。READMEに「`php bin/setup.php --slug=<slug> --name=<name>`と`composer dump-autoload`を手動実行する」と記載する。
- setup.phpはvendor非依存・PHP標準関数のみで書く(オートロード前提にしない)。テーマとプラグインのスターターで同じ内容とし、両者と本節の全文が一致することを`tests/Unit/StarterSetupTest.php`が確かめる。
- 同じサイトの複数のテーマ・プラグインで、生成した名前空間が同じにならないようにするのは利用者の責任である(`my-shop`と`my_shop`はどちらも`MyShop`になる)。同じ名前空間のKernelを2つbootすると、Kernelが起動時のエラーにする。

---

## 11. 旧iwf2bからの設計根拠(簡潔に)

| 旧FWの事実 | 本設計での対応 |
|---|---|
| ブート規約・app/構成がコード/READMEのどこにも文書化されず、15案件全てがブートコードを完全コピペで運用 | スターター+AGENTS.mdで規約を機械可読化し、create-projectで定型を雛形化 |
| FW利用の知識が暗黙知で、案件ごとに命名ゆれ・FQCN直書き・独自Hook再実装が発生 | rules/レシピをタスク単位で完結させ、AIが該当1ファイルだけで実装を完遂できる形に統一 |
| 文書コピー配布の実績がなく、dev-master運用でFWと利用側の乖離が8世代分蓄積 | rules/をtmswp/coreに同梱しvendor参照(インストール済みバージョンと常に一致、ドリフト原理的に不可) |
| T04実案件のフォーム(13フィールド・確認画面・ACFオプションのメール文面・wp_mail+ログ)が現場の代表形 | form.mdレシピをこの実案件相当(3画面+管理者/自動返信メール+mailチャンネルログ+PRG)で全文化 |
| 旧FWのCSRF検証は手書き任意でT06に検証漏れ実例、確認画面はhidden全値再POST(改ざん検知なし) | レシピで「CSRF自作禁止・$_POST直読み禁止」を明文化(FW側の自動化はDR-07) |
| AbstractCsvImportのecho HTMLログ、timthumbの脆弱性、SettingsPageのnonce欠落 | extensions.mdで公式拡張への誘導と「自作・コピー改変禁止」を明記 |
| 管理メニュー全削除等の破壊的オプトアウトデフォルトが「継承一発」で発動 | 管理画面クリーンアップはFWコードに収載せず、rules/hooks.md のレシピで削除対象を案件側に明示させる。ja-toolkitの全モジュールはオプトインとし、レシピでも「理解したモジュールのみ有効化」と明記 |
| QAコマンドが形骸化(.travis.yml死亡・PHPUnitローカル実行不能)し、検証ゲートが存在しなかった | スターターに `composer qa` を同梱し、AGENTS.md・全レシピの「確認方法」で同一コマンドを必須ゲート化 |

※ rules/ が示す各機構の正準定義は各担当文書(02/04/08/10)側にある。

## 12. 受け入れ基準

### 12.1 成果物の存在と形式

- [ ] monorepoルートに`AGENTS.md`(§4の全文。冒頭にmonorepoの開発ルールを含む)が存在し、`CLAUDE.md`はmonorepo・スターター・examples/sample-siteのどこにも存在しない(`git ls-files | grep -i 'claude.md'`が0件)。
- [ ] `packages/core/rules/`に14ファイル(post-type/taxonomy/user-role/meta/acf/form/form-mail/page/model/options/hooks/view/blocks/extensions)が存在し、拡張パッケージの`rules/`に§6.1の5ファイルが存在し、composer配布物に含まれる(`composer archive`の内容で確認)。
- [ ] 各レシピが共通フォーマット(§6.2: 手順/完全な実装例/してはいけないこと/確認方法)を満たす。
- [ ] `starters/theme` / `starters/plugin` が§8/§9のツリーどおりに存在する。
- [ ] スターター直下に `README.md`(§8.7)が存在し、セットアップ手順・create-project例・AGENTS.md/rules/への導線を含む(規約本文の転載はないこと)。
- [ ] スターターのAGENTS.mdのタスク対応表のパス(`vendor/tmswp/<パッケージ>/rules/*.md`)が、`packages/<パッケージ>/rules/`の実在ファイルと一致する。

### 12.2 コード例の正しさ

- [ ] レシピ・スターター内の全PHPコード例が `declare(strict_types=1)`・完全な `use` 文・finalクラス・型宣言を備え、省略(`// ...`)を含まない(`@generated` クラス例の実装本体コメントを除く)。
- [ ] 全コード例が担当文書(02〜10)の正準API名(名前空間・クラス名・メソッド名)と一致する。
- [ ] rules/のコード例が、§6.3の`rules-verify` CIジョブ(抽出したPHPの構文の確認と、クラス定義例の専用のPHPStan(level max))を通る。
- [ ] 本文書内の全composer/WP-CLIコマンド表記が、10-quality.md §1の正準スクリプト名(`lint` / `fix` / `analyse` / `test:unit` / `test:coverage` / `test:integration` / `test` / `qa:fast` / `qa`)および各文書のWP-CLIコマンド定義とgrep突合で一致する。スターター側 `composer test`(=ユニットのみ)の範囲差はAGENTS.md・§8.2の注記で明示されている。

### 12.3 動作検証(統合)

- [ ] `composer create-project tmswp/starter-theme test-theme`(ローカルpathリポジトリ可)が成功し、setup.php後に `{{` がプロジェクト内に残存しない(`grep -r '{{' test-theme` が0件)。
- [ ] 同テーマをwp-envで有効化し、白画面・noticeなしで表示される。
- [ ] `composer create-project tmswp/starter-plugin test-plugin` 後、本体ファイルが `test-plugin.php` にリネームされ、有効化できる。
- [ ] 非対話環境(`echo | php bin/setup.php` 相当)でsetup.phpがブロックせず完了する。
- [ ] rules/form.md の手順をそのまま実行して3画面フロー+メール送信(wp-envのメールキャッチャ)が動作する。

### 12.4 AIフレンドリー性の検収

- [ ] レシピ単独試験: AIエージェントに「AGENTS.md+該当レシピ1本」のみを与えて各タスク(CPT追加・フォーム実装・独自テーブル)を実行させ、`composer qa` 通過+WP上の動作確認まで人手介入なしで完遂できる。
- [ ] 禁止事項試験: 「register_post_typeを直接呼んで」等の規約違反を誘発する指示に対し、AGENTS.md/レシピの禁止事項を根拠に正規手順へ誘導されることをレビューで確認する。

### 12.5 保守規約

- [ ] コアのpublic API変更時にrules/のコード例の追従を確認するCIジョブ(§6.3の`rules-verify`)が存在する。starters/の追従は、monorepoの`composer lint`・`composer analyse`と、禁止事項(§4)のgrepで確かめる。
- [ ] 本文書にレシピ本文の複製が無い(レシピの正本は各パッケージの `rules/*.md`)。

---

## DECISION一覧(本文書内で実装者裁量を残した箇所)

なし。次の2点は本文で確定している。

- スターターREADME.md: 同梱必須とし、内容は§8.7で全文確定した(セットアップ手順・create-project例・AGENTS.md/rules/への導線のみ。規約本体はAGENTS.mdに一元化し転載しない)。
- レシピ内コードブロックの検証方式: §6.3の`rules-verify` CIジョブとして確定した(```` ```php ````フェンス抽出→`php -l`による構文の確認+クラス定義例の専用のPHPStan(level max)。10-quality.mdのCIワークフローで実行)。
