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

スターターの `AGENTS.md` はタスク種別→レシピファイルの対応表を持ち、AIを該当レシピへ誘導する(§5)。拡張パッケージ固有の詳細(acf-bridgeの内部仕様等)は各拡張パッケージのREADMEが持つが、利用者AIが最初に読むべき導入手順はコアの `rules/acf.md` / `rules/extensions.md` に集約する(コアのrules/だけ見れば次の一手が必ず分かる状態を保証する)。

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
├── AGENTS.md                       … §4 全文(コントリビュータ向け)
├── packages/
│   └── core/
│       └── rules/                  … §6 レシピ13本(パッケージに同梱して配布。本文はこのディレクトリが正本)
│           ├── post-type.md
│           ├── taxonomy.md
│           ├── user-role.md
│           ├── meta.md
│           ├── acf.md
│           ├── form.md
│           ├── form-mail.md
│           ├── page.md
│           ├── model.md
│           ├── options.md
│           ├── hooks.md
│           ├── view.md
│           └── extensions.md
└── starters/
    ├── theme/                      … §8 tmswp/starter-theme
    │   ├── AGENTS.md               … §5 全文(利用者向け・theme版)
    │   └── (ツリーは§8参照)
    └── plugin/                     … §9 tmswp/starter-plugin
        ├── AGENTS.md               … §5 差分(plugin版)
        └── (ツリーは§9参照)
```

`CLAUDE.md` 等のエージェント固有の規約ファイルは置かない。規約は monorepo・スターターとも `AGENTS.md` だけに置く(二重管理を避けるため)。

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
| `composer qa:fast` | lint + analyse + test:unit(Docker不要。**AIの実装中の反復ループはこれ**) |
| `composer qa` | lint + analyse + test を直列実行(コミット前必須ゲート。統合テストを含むためDocker必要) |

利用側プロジェクト(スターター)にも同名のcomposer scriptsを定義し、**人間・CI・AIが同一コマンドを叩く**(DR-12)。ただしスターターは統合テストを既定で持たないため、スターター側の `composer test` はユニット層のみを指す(範囲差は§5のAGENTS.mdに注記する)。コマンド意味論の正準は10-quality.md §1であり、本文書内の全コマンド表記はこれとgrep突合で一致させる。

WP-CLIコマンド名も各担当文書の正準名に固定する。本文書のAGENTS.md・全レシピはこの表の表記のみを使う。

| WP-CLIコマンド | 内容 | 正準定義 |
|---|---|---|
| `wp tmswp discover` | 探索結果ファイル(`tmswp-discovery.php`)の生成。`--check` はファイルが最新かの照合(古ければexit 1) | 02-kernel |
| `wp tmswp migrate` | Model(独自テーブル)のマイグレーション実行 | 06-model |
| `wp tmswp acf sync` | acf-jsonから型付きアクセサクラスを再生成 | 09-acf-bridge |
| `wp tmswp acf check` | acf-jsonと生成クラスのドリフト検査(差分があればexit 1) | 09-acf-bridge |

acf-bridgeは、WordPressを起動せずにテーマ・プラグインのルートの`tmswp-kernel.php`の構成で同じ生成・照合を行う`vendor/bin/tms-acf sync|check`も持つ(09-acf-bridge §5.1。E101だけは検査しない)。スターターはacf-bridgeに依存しないため、`acf:sync`・`acf:check`のscriptsを持たない。acf-bridgeを入れるときに足す(§8.2)。

---

## 4. コアmonorepo直下 AGENTS.md(全文案)

読者は「FW本体を開発・修正するAI(コントリビュータ)」。monorepoルートに配置する。以下のフェンス内が全文。

````markdown
# TMStack WordPress Framework — Contributor Guide

このリポジトリは TMStack WordPress Framework の monorepo である。
あなた(AIエージェント)がここで行うのは **フレームワーク本体の開発** である。
利用側プロジェクト(テーマ/プラグイン)の開発規約は starters/*/AGENTS.md と packages/core/rules/ にある。混同しないこと。

## アーキテクチャ要約

- 対象: PHP 8.3〜8.5 / WordPress 7.0以上。コアのruntime依存は psr/log ^3 と psr/container ^2 のみ。
- 思想: 「app/ にクラスを置くだけ」の規約ベース自動探索(PHP8 Attribute + クラスマップ + ビルド時に生成する探索結果ファイル)。
  ビューはWordPress標準のテンプレート階層を一切壊さない(絶対原則)。
  外部プラグイン(ACF/CF7等)依存コードはコアに置かない(ACFは packages/acf-bridge のみ)。
- 配布: monorepo開発 + Packagistへread-only split。SemVer。

## パッケージ構成

| パス | composer名 | 名前空間 | 役割 |
|---|---|---|---|
| packages/core | tmswp/core | TMSWP\Core\ | Kernel/Container/エンティティ定義/meta/option/Form/Model/Log/View/Support |
| packages/thumbnail | tmswp/thumbnail | TMSWP\Thumbnail\ | 署名付きサムネイルURL(WP_Image_Editor) |
| packages/settings-page | tmswp/settings-page | TMSWP\SettingsPage\ | 管理画面設定ページ(Form層ベース) |
| packages/acf-bridge | tmswp/acf-bridge | TMSWP\AcfBridge\ | acf-json→型付きアクセサ生成・AcfMetaStorage |
| packages/ja-toolkit | tmswp/ja-toolkit | TMSWP\JaToolkit\ | 日本語サイト対応(全モジュールオプトイン) |
| starters/theme | tmswp/starter-theme | TmswpStarterTheme\(create-projectでスラッグから生成した名前空間に書き換える) | create-project用テーマ雛形 |
| starters/plugin | tmswp/starter-plugin | TmswpStarterPlugin\(同上) | create-project用プラグイン雛形 |

コアのモジュールマップ(TMSWP\Core\ 配下の正準名)は docs/ 配下の設計書群を一次資料とする。
設計書に定義のないpublic APIを新設する場合は、必ず設計書の更新を同一PRに含めること。

## 必須コマンド(コミット前ゲート)

```bash
composer qa                 # lint + analyse + test。これが通らない変更はコミット禁止(統合テストを含むため wp-env/Docker が必要)
composer qa:fast            # lint + analyse + test:unit。Docker不要の高速ループ(実装中の反復はこちらを使う)
composer test               # ユニット+統合テスト(test:unit → test:integration。wp-env起動が必要: composer wp:start)
composer test:unit          # ユニットテスト(WP非依存・Brain Monkey。Docker不要)
composer test:coverage      # ユニット・統合テストの合算カバレッジの計測と閾値判定(wp-env起動が必要: composer wp:start:coverage)
composer test:integration   # 統合テスト(wp-env起動が必要: composer wp:start)
composer analyse            # PHPStan level max(baseline禁止)
composer lint               # コードスタイル検査(php-cs-fixer dry-run)
composer fix                # コードスタイル自動修正
```

- wp-env は `npm ci` で入れたローカルの `@wordpress/env`(package.jsonで版を固定)を `composer wp:start` / `wp:stop` / `test:integration` 経由で使う。直接呼ぶときは `node node_modules/@wordpress/env/bin/wp-env` とパスで指定し、コマンド名 `wp-env` を名前解決する呼び出しは使わない。
- PHPStanは **level max・baselineなし** で運用する。エラーを baseline や @phpstan-ignore で握りつぶす変更は拒否される。
  ignoreが本当に必要な場合(WP関数のstub不備等)は理由コメントを併記し、PRで明示すること。
- テストはユニット層(tests/Unit, WP非依存)と統合層(tests/Integration, wp-env + WordPress のテストライブラリ(WP_TESTS_DIR))の2層。
  新規クラスにはユニットテストを必ず追加する。WP関数に触れるロジックは薄い境界クラスに寄せ、境界より内側をユニットテスト可能に保つ。

## コーディング規約

- `declare(strict_types=1);` 全ファイル必須。PER-CS 2.0 + php-cs-fixer設定(.php-cs-fixer.dist.php)に従う。
- クラスは原則 `final`。継承を許す基底(PostType/Taxonomy/UserRole/Form/Model/Hook)のみ非final
  (オプショングループはbacked enumであり基底クラスを持たない。04-meta-options.md参照)。
- 全プロパティ・引数・戻り値に型宣言。`mixed` の使用は境界(WP API・$_POST等)に限定し、内側で即座に絞り込む。
- **禁止**: `__call` / `__callStatic` / `__get` / `__set` 等のマジックメソッド、`extract()`、`eval()`、
  グローバル変数の新設、`exit`/`die`(00-overview.md 原則1の例外=PRGと設定ページ保存後の `wp_safe_redirect()` 直後を除く)、WP標準テンプレート階層への介入(template_includeの差し替え)。
- 例外は `TMSWP\Core\Exception\` 配下に定義し、`\RuntimeException` 等の素の例外をthrowしない。
- WP関数の直接呼び出しは各パッケージの境界クラス(例: Meta\WpMetaStorage, Model\Connection)に閉じ込める。
- 名前空間: コアは `TMSWP\Core\`。コア名前空間はDiscoveryの走査対象外である前提を崩さない。
- i18n: テキストドメインは `tmswp`。翻訳対象文字列は必ず `__( '...', 'tmswp' )` 形式。
- コメント・docblockは日本語可。識別子は英語。

## 変更時の手順

1. 該当パッケージの設計書(docs/)を読み、API契約を確認する。
2. テストを先に書くか、最低でも変更と同一PRでテストを追加する。
3. `composer qa` を通す。
4. 破壊的変更(public APIのシグネチャ変更・削除)はCHANGELOG.mdの Unreleased > Breaking に必ず記録する。

## 新規パッケージの追加手順

1. `packages/<name>/` を作成し、最低限のファイルを置く:
   - `composer.json`(name=tmswp/<name>, type=library, PSR-4: `TMSWP\<PascalName>\` => `src/`,
     require に `"tmswp/core": "^X.0"` と PHP/拡張要件。コアに要件を足さないこと)
   - `src/` `tests/Unit/` `README.md` `rules/`(利用者向けレシピが必要な場合はコアの rules/extensions.md にも導入手順を追記)
2. `composer monorepo:merge` を実行し、ルート `composer.json` の autoload(-dev) / `replace` へ新パッケージを反映する
   (monorepo内のパッケージ相互解決に path リポジトリは使わない。正準手順は品質設計書(10)§6.2)。
3. ルートの `phpstan.neon.dist` の paths と `phpunit.unit.xml.dist` / `phpunit.integration.xml.dist` の testsuite に新パッケージを追加する。
4. `.github/workflows/` のテストマトリクスと split.yml の matrix(read-only split対象リポジトリ)に追加する。
5. Packagist登録はメンテナが行う。PRには split 先リポジトリ名の提案を含める。
6. 拡張パッケージは **コアのAPIのみに依存** する。拡張同士の依存は原則禁止(必要ならPRで合意を取る)。

## 禁止事項(コントリビュータ向け)

- コアに外部プラグイン(ACF/CF7/Yoast等)の関数・クラス参照を追加すること。
- コアのruntime依存(psr/log, psr/container以外)を追加すること。
- `register_post_type` 等のWP登録系を `init` 等の正規フック外で即時実行すること。
- packages/* 間でソースをコピーすること(共有したいコードはcoreのSupportへ昇格を検討)。
- starters/ 配下とrules/ 配下の規約・コード例を、コアのAPI変更に追従させずに放置すること
  (public API変更時は `grep -r <旧API> packages/*/rules starters` で追従漏れを確認する)。

## リリース

- SemVer。タグ `vX.Y.Z` をmonorepoに打つと、CIが各パッケージへsplitしタグを伝播する。
- リリース手順の詳細は docs/RELEASING.md を参照。
````

---

## 5. スターター同梱 AGENTS.md(全文案)

読者は「このテーマ/プラグインを実装するAI(FW利用者)」。starter-theme・starter-pluginの直下に配置し、create-projectでそのままプロジェクトに残る。theme版を全文、plugin版を差分で示す。

文案の名前空間`App\`は、プロジェクトの名前空間を表す。実物のAGENTS.mdには、スターターの名前空間(`TmswpStarterTheme\`・`TmswpStarterPlugin\`)を書き、create-projectのsetup.phpがスラッグから生成した名前空間に書き換える(§10)。実物のAGENTS.mdは、レシピ(`vendor/tmswp/core/rules/`)のコード例の`App\`を、その名前空間に読み替える旨も示す。

### 5.1 theme版全文(starters/theme/AGENTS.md)

````markdown
# このテーマの開発規約(TMSWP)

このプロジェクトは TMStack WordPress Framework を使うWordPressテーマである。
あなた(AIエージェント)はこのファイルの規約に従って実装する。
**フレームワーク本体(vendor/)はここでは開発しない。**

## 最初に読むもの

タスクに応じて、下表の該当レシピを **必ず読んでから** 実装を始めること。
レシピはインストール済みフレームワークに同梱されており、このプロジェクトのFWバージョンと常に一致している。

| やりたいこと | 読むファイル |
|---|---|
| カスタム投稿タイプを追加する | vendor/tmswp/core/rules/post-type.md |
| カスタムタクソノミーを追加する | vendor/tmswp/core/rules/taxonomy.md |
| ユーザーロールを追加する | vendor/tmswp/core/rules/user-role.md |
| 投稿/ターム/ユーザーにカスタムフィールド(非ACF)を持たせる | vendor/tmswp/core/rules/meta.md |
| ACFでフィールドを管理する(acf-json運用) | vendor/tmswp/core/rules/acf.md |
| フォーム(問い合わせ・申込み等)を実装する | vendor/tmswp/core/rules/form.md |
| フォームの完了処理でメールを送る | vendor/tmswp/core/rules/form-mail.md |
| テンプレートの前処理(データ取得・フォーム駆動)を書く | vendor/tmswp/core/rules/page.md |
| 独自DBテーブルを作って読み書きする | vendor/tmswp/core/rules/model.md |
| サイト設定値(オプション)を定義する | vendor/tmswp/core/rules/options.md |
| add_action/add_filter をまとめて登録する | vendor/tmswp/core/rules/hooks.md |
| テンプレートパーツの読み込み・ロジック分離 | vendor/tmswp/core/rules/view.md |
| 独自ブロックを追加する | vendor/tmswp/core/rules/blocks.md |
| サムネイル/設定ページ/日本語対応の追加 | vendor/tmswp/core/rules/extensions.md |

## プロジェクト構成

```
.
├── style.css            # テーマヘッダ
├── functions.php        # FWブートのみ。ロジックを書かない
├── tmswp-kernel.php     # Kernelの構成(KernelConfig)を返す。拡張はここに書く。WordPressの関数を呼ばない
├── tmswp-discovery.php  # 探索結果ファイル(wp tmswp discoverの生成物。コミットする)
├── index.php ほか       # WordPress標準のテンプレート階層(通常のWPテーマと完全に同じ)
├── parts/               # テンプレートパーツ(Template::part()/get_template_part()の読込先)
├── blocks/              # 独自ブロックのソース(rules/blocks.md)
├── build/blocks/        # ブロックのビルドの出力(npm run build。コミットする)
├── app/                 # PHPクラスはすべてここ(名前空間 App\、PSR-4)
│   ├── Post/            # 投稿タイプ定義(App\Post\*Post)
│   ├── Tax/             # タクソノミー定義(App\Tax\*Tax)
│   ├── User/            # ロール定義(App\User\*User)
│   ├── Form/            # フォーム定義(App\Form\*Form)
│   ├── Model/           # 独自テーブル(App\Model\*Model)
│   ├── Option/          # オプション定義(App\Option\*Option。backed enum)
│   ├── Page/            # テンプレートの前処理クラス(App\Page\*Page)
│   └── Hook/            # フック集約(App\Hook\*Hook)
├── tests/               # ユニットテスト
├── composer.json
├── package.json         # ブロックのビルド(npm run build)
├── phpstan.neon
└── .php-cs-fixer.dist.php
```

## ロジックの置き場(配置決定木)

書こうとしているコードがどこに属するかは、次の1問で決まる。

- **特定のページテンプレートの「表示前」処理**(そのテンプレートでだけ必要なデータ取得・整形・フォーム送信処理)なら
  → `app/Page/<Name>Page.php` の前処理クラス。テンプレートの先頭(`get_header()` の前)で `$vm = <Name>Page::handle();` と1回だけ呼び、
  返された値(`public readonly` のプロパティ)をテンプレートで読む(rules/page.md)。
- **それ以外のロジック**(投稿タイプ・タクソノミー・メタ・フォーム定義・独自テーブル・オプション・WPフック)は
  すべて `app/` 配下の `final class`(下表)。リクエスト種別を問わず動くフック処理は `app/Hook/`(rules/hooks.md)。

前処理クラスはAttributeも継承も不要な `final class` で、テンプレートが使う値を型付きのプロパティで返す。出力(echo)・`$wp_query->set_404()` の直呼びはしない(rules/page.md)。全テンプレート共通の前処理は持たない。共通ロジックは `app/Hook/` か、パーツ内でモデルのアクセサを直接読む形にする。

## クラス追加の基本手順(全機能共通)

1. `app/` 配下の該当ディレクトリに `final class` を1ファイル1クラスで作る(PSR-4: `App\Post\NewsPost` → `app/Post/NewsPost.php`)。
2. クラスに対応するAttribute(`#[AsPostType]` 等)を付け、FWの基底クラスを継承する。
3. **登録コードは書かない。** app/ に置くだけでKernelが探索・登録する(functions.phpへの追記は不要)。
4. `wp tmswp discover` で探索結果ファイル(テーマ/プラグインのルート直下の `tmswp-discovery.php`)を
   作り直し、クラスと一緒にコミットする。開発環境(Kernel の debug = true)は毎回 app/ を走査するため
   作り直す前でも反映されるが、本番は探索結果ファイルだけを読む。
5. `composer qa` を実行して通す。
6. 反映確認はWP上で行う(各レシピの「確認方法」参照)。

## 必須コマンド

```bash
composer qa        # lint + analyse + test。タスク完了前に必ず通すこと
composer analyse   # PHPStan level max(app/ が対象)
composer fix       # コードスタイル自動修正
composer test      # ユニットテスト
```

※ コアmonorepoでは `composer test` がユニット+統合テスト(Docker必要)を指すが、
本プロジェクト(スターター)は統合テストを既定で持たないため `test` = ユニットテストである。

## コーディング規約

- `declare(strict_types=1);` 全PHPファイル必須(テンプレートファイル含む)。
- app/ のクラスは `final`、全シグネチャに型宣言。マジックメソッド(`__call`/`__callStatic`等)と `extract()` は禁止。
- メタキーは必ず `#[MetaSchema]` 付きのクラス定数、オプションキーは必ず `#[OptionSchema]` 付きのenumケースで宣言し、文字列リテラルを直書きしない。
- 頻出するメタ値アクセスは、定義クラスに明示アクセサメソッド(例: `ProductPost::price($post): int`)を書く。
- テンプレート内では `use` 文が使えないため、FQCN静的呼び出し(`\App\Post\NewsPost::...`)を使ってよい。
- 出力は必ずエスケープする: `esc_html()` / `esc_attr()` / `esc_url()`。FWのフォームレンダラ(`$form->field()->input()`)はエスケープ済みHTMLを返すのでそのままechoしてよい。
- テキストドメインはこのテーマ自身のもの(style.cssのText Domain)を使う。`tmswp` ドメインを使わない。

## 禁止事項(違反はレビューで差し戻し)

1. **vendor/ 配下の編集**。FWの挙動を変えたい場合はフック/設定/継承で行い、それでも不可能ならFW本体への課題として報告する。
2. **FWコアの改変・コピー**。vendor/tmswp/* のクラスをapp/へコピーして改変しない。
3. **WordPress標準テンプレート階層の迂回**。`template_include` でテンプレートを乗っ取らない。`exit` でWPのテンプレートローダを止めない(フォームPOST処理後の `wp_safe_redirect()` + `exit` のみ例外)。ページの出し分けはWP標準のテンプレート階層(page-{slug}.php等)とConditional Tagsで行う。
4. **登録系WP関数の直接呼び出し**。`register_post_type` / `register_taxonomy` / `register_post_meta` / `add_role` をapp/やfunctions.phpで直接呼ばない。必ずFWの定義クラスを使う(該当レシピ参照)。
5. **$wpdb の直接利用**。独自テーブルはModel経由(rules/model.md)。WP標準テーブルへのクエリはWP_Query/get_posts等の標準APIを使う。
6. **functions.php へのロジック追加**。functions.phpはFWブートのみ。フックは app/Hook/(rules/hooks.md)へ。
7. **探索結果ファイルの手動編集**。`tmswp-discovery.php` は `wp tmswp discover` の生成物である。
   手で編集せず、app/ を変えたら作り直してコミットする。本番はこのファイルが無いと起動時に例外になり、
   古いと追加・変更したクラスが反映されない。

## デバッグ

- ログ: `\TMSWP\Core\Log\Log::info('message', ['context' => $value]);`(チャンネル分割は `Log::channel('mail')->error(...)`)。
  出力先・閾値は`tmswp-kernel.php`のKernelの構成で構成済み。`var_dump`/`print_r`をコミットに残さない。
- 探索まわりの不調(クラスを置いたのに有効化されない)は、まずクラス名とファイルパスのPSR-4整合、次にAttribute付け忘れ、最後に `wp tmswp discover --check` で探索結果ファイルが最新か(古ければ `wp tmswp discover` で作り直す)の順で確認する。
````

### 5.2 plugin版(starters/plugin/AGENTS.md)— theme版との差分

plugin版はtheme版と同一の章立て・文面とし、次の箇所のみ差し替える。

1. 冒頭文: 「WordPressテーマである」→「WordPressプラグインである」。
2. 「プロジェクト構成」のツリーを次に差し替え:

```
.
├── my-plugin.php        # プラグイン本体ヘッダ+FWブートのみ(create-projectで実名にリネームされる)
├── tmswp-kernel.php     # Kernelの構成(KernelConfig)を返す。拡張はここに書く。WordPressの関数を呼ばない
├── tmswp-discovery.php  # 探索結果ファイル(wp tmswp discoverの生成物。コミットする)
├── app/                 # (theme版と同一)
├── templates/           # プラグイン同梱テンプレート(Template::part()のフォールバック先)
├── tests/
├── composer.json
├── phpstan.neon
└── .php-cs-fixer.dist.php
```

3. 「テンプレートパーツ」関連の記述: テーマの `parts/` ではなく「`templates/` がプラグイン同梱フォールバック。テーマ側に同名ファイルがあればテーマが優先される(rules/view.md参照)」と差し替え。
4. 「禁止事項」3項のテンプレート階層の説明は維持(プラグインでも `template_include` 乗っ取り禁止)。加えて「Modelのテーブル定義は`wp tmswp migrate`で適用する(有効化では適用しない)。独自マイグレーション用に`register_activation_hook`を直接書かない(rules/model.md参照)」を追記。
5. **テンプレートの前処理クラスはプラグインでは使わない**。プラグインはトップレベルのテンプレートを供給しないため、タスク対応表の「テンプレートの前処理」行・「ロジックの置き場(配置決定木)」節の前処理クラスの項・プロジェクト構成ツリーの `Page/` を削除する。テンプレートの外で前処理が必要なら `app/Hook/` の `#[AsHook]` クラス(rules/hooks.md)で行う。
6. テキストドメイン: 「プラグイン本体ヘッダのText Domainを使う」。

---

## 6. rules/ ディレクトリ構成と共通フォーマット

### 6.1 構成

`packages/core/rules/` に以下13ファイルを置く。tmswp/coreパッケージの配布物に含める(composer.jsonのarchive excludeに入れない)。

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
| extensions.md | 拡張パッケージ導入 | 08-extensions |

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

これにより「コアのpublic API変更にrules/のコード例が追従していない」状態をマージ前に継続検出する(§12.2・§12.5の検収項目に対応)。このジョブはスターターを展開せず、スターター自身の`composer lint`・`composer analyse`も実行しない。starters/のPHPは、monorepoの`composer lint`(starters/のPHPのうち、.php-cs-fixer.dist.php等のドットファイルを除くもの)と`composer analyse`(starters/theme/app・starters/plugin/app)の対象であり、API変更時の追従は禁止事項(§4)のgrepでも確かめる。

---

## 7. レシピの正本

レシピの本文は各パッケージの `rules/*.md` が正本であり、本文書には複製を置かない(§1.2・§6)。

---

## 8. starter-theme(tmswp/starter-theme)

### 8.1 ファイルツリー

```
starters/theme/
├── AGENTS.md                 # §5.1 全文
├── README.md                 # §8.7 全文(人間向け導入文書)
├── composer.json             # §8.2
├── style.css                 # §8.3(プレースホルダ入り)
├── functions.php             # §8.4(FWブートのみ)
├── tmswp-kernel.php          # §8.4(Kernelの構成)
├── index.php                 # §8.5(最小のWP標準ループ)
├── header.php                # 最小(<!DOCTYPE html>〜wp_head〜<body>)
├── footer.php                # 最小(wp_footer〜</html>)
├── parts/
│   └── .gitkeep
├── assets/
│   └── css/
│       └── main.css          # 空(ThemeSetupHookがenqueueする実在ファイル)
├── app/
│   ├── Hook/
│   │   └── ThemeSetupHook.php   # rules/hooks.md の実装例と同一内容(動くサンプル兼雛形)
│   ├── Post/.gitkeep
│   ├── Tax/.gitkeep
│   ├── User/.gitkeep
│   ├── Form/.gitkeep
│   ├── Model/.gitkeep
│   └── Option/.gitkeep
├── tests/
│   ├── bootstrap.php         # require vendor/autoload のみ
│   └── Unit/.gitkeep
├── bin/
│   └── setup.php             # §10.2(post-create-project-cmd。実行後に自己削除)
├── phpunit.xml.dist          # §8.6
├── phpstan.neon              # §8.6
├── .php-cs-fixer.dist.php    # §8.6
└── .gitignore                # §8.6
```

方針: スケルトンの `app/` は **動くサンプル1つ(ThemeSetupHook)+空ディレクトリ構造** とする。サンプルCPT等は置かない(消す手間と「消し忘れた雛形CPTが本番に出る」事故を避ける。AIはrules/のコード例から生成する)。

### 8.2 composer.json(全文)

```json
{
  "name": "tmswp/starter-theme",
  "description": "Starter theme for TMStack WordPress Framework.",
  "type": "project",
  "license": "proprietary",
  "require": {
    "php": ">=8.3",
    "tmswp/core": "^0.1"
  },
  "require-dev": {
    "brain/monkey": "^2.6",
    "friendsofphp/php-cs-fixer": "^3.64",
    "phpstan/extension-installer": "^1.4",
    "phpstan/phpstan": "^2.1",
    "phpunit/phpunit": "^11.5",
    "szepeviktor/phpstan-wordpress": "^2.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "app/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "App\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "lint": "php-cs-fixer fix --dry-run --diff",
    "fix": "php-cs-fixer fix",
    "analyse": "phpstan analyse --memory-limit=1G",
    "test": "phpunit",
    "qa": [
      "@lint",
      "@analyse",
      "@test"
    ],
    "post-create-project-cmd": "@php bin/setup.php"
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

- `name` はPackagist登録名のまま配布し、create-project後に `bin/setup.php` が `local/<slug>` へ書き換える(§10)。
- `phpstan/extension-installer` がPHPStan拡張(szepeviktor/phpstan-wordpress と tmswp/core 同梱拡張)を自動登録する(10-quality.md §7 項目2)。`allow-plugins` を事前記載しているため、create-project後の `composer install` は承認プロンプトなしで完了する。
- バージョン制約(`^0.1` 等)はリリース時の実バージョンに合わせて更新する。
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

Kernelの構成はテーマのルートの`tmswp-kernel.php`が返し、functions.phpは`KernelConfig::load()`で読んだその構成でbootする(02-kernel利用例)。WordPressを起動しない`tms-acf`(09-acf-bridge §5.1)も`tmswp-kernel.php`を読むため、このファイルではWordPressの関数を呼ばない(拡張のコンストラクタも同じ)。

`tmswp-kernel.php`:

```php
<?php

declare(strict_types=1);

// テーマのKernelの構成。functions.phpのブートと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。
// WordPressの関数はここで呼ばない(拡張のコンストラクタも同じ)
return TMSWP\Core\Kernel\KernelConfig::theme(
    appDir: __DIR__ . '/app',
    appNamespace: 'App',
    // @PSR12(starter)とPER-CS(monorepo)のどちらでも同じ整形になるよう、アロー関数でなく無名関数で書く
    logChannels: static function (): array {
        return [
            'default' => new TMSWP\Core\Log\LogChannel(new TMSWP\Core\Log\SimpleLogger()),
        ];
    },
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

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->append([
        __FILE__,
        __DIR__ . '/footer.php',
        __DIR__ . '/functions.php',
        __DIR__ . '/header.php',
        __DIR__ . '/index.php',
        __DIR__ . '/tmswp-kernel.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'single_line_empty_body' => true,
        'strict_param' => true,
    ])
    ->setFinder($finder);
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

`tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
```

### 8.7 README.md(全文)

人間の開発者向けの導入文書として必須で同梱する。規約の真実源はAGENTS.mdであり、READMEは導線に徹する(規約本文を転載して二重管理しない)。以下のフェンス内が全文。

````markdown
# {{project_name}}

TMStack WordPress Framework を使用したWordPressテーマ。

## セットアップ

```bash
composer install
composer qa
```

`composer create-project --no-scripts` で作成した場合は、先にセットアップを実行する。

```bash
php bin/setup.php
```

新規プロジェクトはこの雛形から作成する。

```bash
composer create-project tmswp/starter-theme my-theme
```

## 開発規約

- 開発規約・禁止事項・必須コマンド: [AGENTS.md](./AGENTS.md)
- タスク別レシピ: `vendor/tmswp/core/rules/*.md`
````

- `{{project_name}}` はsetup.phpの置換対象(§10.2の置換対象に `README.md` を含める)。
- plugin版は「WordPressテーマ」を「WordPressプラグイン」、`starter-theme` / `my-theme` を `starter-plugin` / `my-plugin` に差し替えた同一構成とする。

`.gitignore`:

```
/vendor/
/composer.lock
/.php-cs-fixer.cache
/.phpunit.result.cache
/.phpunit.cache/
```

- 探索結果ファイル `tmswp-discovery.php`(テーマのルート直下。02-kernel.md §6)はコミットしてデプロイする
  生成物のため、.gitignore で除外しない。

---

## 9. starter-plugin(tmswp/starter-plugin)

### 9.1 ファイルツリー

```
starters/plugin/
├── AGENTS.md                 # §5.2(theme版との差分適用済み全文)
├── README.md                 # §8.7と同一構成(「テーマ」→「プラグイン」、starter-theme→starter-pluginに差し替え)
├── composer.json             # §9.2(theme版との差分のみ記載)
├── plugin.php                # §9.3(プラグイン本体。setup.phpが <slug>.php にリネームする)
├── tmswp-kernel.php          # §9.3(Kernelの構成。setup.phpが本体ファイル名のプレースホルダを置換する)
├── templates/
│   └── .gitkeep              # プラグイン同梱テンプレート(Template::part のフォールバック先)
├── app/
│   ├── Hook/
│   │   └── PluginSetupHook.php  # §9.4(動くサンプル兼雛形)
│   ├── Post/.gitkeep
│   ├── Tax/.gitkeep
│   ├── User/.gitkeep
│   ├── Form/.gitkeep
│   ├── Model/.gitkeep
│   └── Option/.gitkeep
├── tests/
│   ├── bootstrap.php         # theme版と同一
│   └── Unit/.gitkeep
├── bin/
│   └── setup.php             # theme版と同一スクリプト(§10.2)
├── phpunit.xml.dist          # theme版と同一
├── phpstan.neon              # theme版と同一
├── .php-cs-fixer.dist.php    # §9.5(差分あり)
└── .gitignore                # theme版と同一
```

### 9.2 composer.json(theme版との差分)

- `"name": "tmswp/starter-plugin"`、`"description": "Starter plugin for TMStack WordPress Framework."`
- scripts: `qa` はtheme版と同じく `["@lint", "@analyse", "@test"]` とする。
- autoloadは `PluginApp\` / `PluginApp\Tests\` を `app/` / `tests/` へ割り当てる。テーマスターターの `App\` とは分け、同一WordPress上でテーマとプラグインを併用できるようにする。
- それ以外(require / config)はtheme版(§8.2)と同一。

### 9.3 tmswp-kernel.php・plugin.php(全文)

プラグイン本体のパス(`file:`)は`tmswp-kernel.php`の位置から決める(`__FILE__`は使わない)。プラグインのbootとtms-acfのどちらから読んでも同じ値になる。本体ファイル名は`{{project_slug}}.php`とし、setup.phpが本体のリネームと同じスラッグで置換する(§10)。

`tmswp-kernel.php`:

```php
<?php

declare(strict_types=1);

// プラグインのKernelの構成。本体ファイルのブートと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。
// 本体ファイルのパスはこのファイルの位置から決めるため、どちらの経路でも同じ値になる。
// WordPressの関数はここで呼ばない(拡張のコンストラクタも同じ)
return TMSWP\Core\Kernel\KernelConfig::plugin(
    file: __DIR__ . '/{{project_slug}}.php',
    appDir: __DIR__ . '/app',
    appNamespace: 'PluginApp',
    // @PSR12(starter)とPER-CS(monorepo)のどちらでも同じ整形になるよう、アロー関数でなく無名関数で書く
    logChannels: static function (): array {
        return [
            'default' => new TMSWP\Core\Log\LogChannel(new TMSWP\Core\Log\SimpleLogger()),
        ];
    },
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
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

TMSWP\Core\Kernel\Kernel::boot(TMSWP\Core\Kernel\KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
```

### 9.4 app/Hook/PluginSetupHook.php(全文)

```php
<?php

declare(strict_types=1);

namespace PluginApp\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class PluginSetupHook extends Hook
{
    public function register(): void {}
}
```

### 9.5 .php-cs-fixer.dist.php(theme版との差分)

- `->append()`の`footer.php`・`functions.php`・`header.php`・`index.php`を`plugin.php`に置き換える(`__FILE__`と`tmswp-kernel.php`はtheme版と同じ)。`->in()`の対象はそのまま(`app`, `tests`)。

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
5. **本体リネーム**(プラグインのみ): `plugin.php` → `<slug>.php`。
6. **探索結果ファイルの削除**:スターターの`tmswp-discovery.php`は、書き換える前の名前空間とブロック名で作ったものなので消す。
7. **composer.json書き換え**:上の置き換えに加えて、`name`を`local/<slug>`、`license`を`proprietary`に変更し、`config.autoloader-suffix`を生成した名前空間にし、`post-create-project-cmd`エントリを削除する。字下げは元のcomposer.jsonのものを保つ。`autoloader-suffix`は、Composerのオートローダのクラス名(`ComposerAutoloaderInit<suffix>`)の接尾辞である。既定の接尾辞は、create-projectが依存を入れた時点のcomposer.lockのハッシュで、同じ版のスターターから作ったプロジェクトどうしで同じになる。そのため、同じスターターから作ったプラグインを同じサイトで有効にすると、クラスの重複で止まる。スターター自身のlicenseはGPL-2.0-or-later(Packagistに公開してcreate-projectで使うため)で、作った案件のプロジェクトはクライアントの非公開のコードのため`proprietary`にする。スターターにLICENSEのファイルは置かない。
8. **自己削除**: `bin/setup.php`と、空になった`bin/`を削除する(再実行事故の防止)。
9. **composer.lockの更新**: create-projectが作った`composer.lock`があれば、Composer(`COMPOSER_BINARY`。Composerがスクリプトに渡す)で`composer update --lock --no-install`を実行し、書き換えたcomposer.jsonのハッシュを記録し直す(パッケージの版は変えない。`composer validate`がlockの古さを報告しないため)。`--no-install`で作った場合は`composer.lock`が無いため、何もしない。Composerから実行していない(手で実行した)場合は、`composer update --lock`の実行を案内する。
10. 次の一手を標準出力に案内する。オートローダの作り直し(`composer dump-autoload`)、`WP_DEBUG`を有効にしたサイト(開発環境)でテーマ・プラグインを有効にしての探索結果ファイルの作成(テーマは`npm ci && npm run build`、プラグインは`composer discover`。どちらも`wp tmswp discover`を実行する)とコミット、`composer qa`、AGENTS.mdである。
11. **オートローダの作り直し**: Composerは依存を入れてオートローダを作った後で`post-create-project-cmd`を実行するため、その時点のオートローダ(psr-4とclassmap)はスターターの名前空間のままである。`post-create-project-cmd`は、setup.phpに続けて`@composer dump-autoload`を実行し、書き換えた名前空間で作り直す。Composerはスクリプトの一覧を読み込み済みのため、setup.phpがcomposer.jsonから`post-create-project-cmd`を消しても、続きは実行される。

探索結果ファイルは、setup.phpの中では作り直さず、作り直す手順を案内する。`wp tmswp discover`は、テーマ・プラグインを有効にしたWordPressのサイトとWP-CLIを要する(探索コマンドは起動中のKernelを走査する。02-kernel.md §6)が、create-projectの時点では、プロジェクトがサイトの中にあるとも、サイトのDBが動いているとも限らないためである。debug(開発環境)のbootは毎回`app/`と`build/blocks/`を走査するため、オートローダを作り直した後(手順11)は、探索結果ファイルを作り直す前でも動く。debugでないサイト(本番)は、作り直すまで探索結果ファイルが無いことを起動時のエラーで示す。`wp tmswp discover`は起動したKernelを走査するため、debugでないサイトでは作り直すこともできない。そのため、作り直しは`WP_DEBUG`を有効にしたサイトで行う。スターターの`composer qa`は探索結果ファイルの照合(`discover:check`。`wp tmswp discover --check`)を含むため、作り直すまで失敗する。

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
 * テーマとプラグインのスターターで同じ内容で、Composerのvendor/には依存しない(PHPの標準関数だけを使う)。
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

    $composerSource = $rewrites[$composerFile] ?? readTextFile($composerFile);
    $composer = decodeComposerJson($composerFile, $composerSource);
    $composer->name = 'local/' . $slug;
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

    try {
        $composerJson = reindentJson(
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            jsonIndent($composerSource),
        ) . "\n";
    } catch (JsonException $exception) {
        throw new RuntimeException("cannot encode {$composerFile}: {$exception->getMessage()}", 0, $exception);
    }

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
    $kind = is_file($root . '/style.css') ? 'theme' : 'plugin';
    $build = $kind === 'theme' ? 'npm ci && npm run build' : 'composer discover';
    fwrite(STDOUT, <<<TEXT

          Initialized {$name} (slug: {$slug}, namespace: {$namespace}\\).

          Next steps:
            1. Run `composer dump-autoload` so that the autoloader uses the new namespace
               (`composer install` if the dependencies are not installed yet).
               composer create-project runs `composer dump-autoload` right after this script.{$lockStep}
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
    return decodeComposerJson($file, readTextFile($file));
}

/**
 * @throws RuntimeException JSONのオブジェクトでない場合
 */
function decodeComposerJson(string $file, string $json): stdClass
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
 * JSONの1段の字下げ(最初に字下げした行の空白)を返す。字下げが無ければ4つの空白を返す。
 */
function jsonIndent(string $json): string
{
    return preg_match('/^([ \t]+)\S/m', $json, $matches) === 1 ? $matches[1] : '    ';
}

/**
 * json_encode()のJSON_PRETTY_PRINT(4つの空白)の字下げを、$indentに変える。
 */
function reindentJson(string $json, string $indent): string
{
    $reindented = preg_replace_callback(
        '/^(?: {4})+/m',
        static fn(array $matches): string => str_repeat($indent, intdiv(strlen($matches[0]), 4)),
        $json,
    );

    if ($reindented === null) {
        throw new RuntimeException('cannot indent composer.json: ' . preg_last_error_msg());
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

- [ ] monorepoルートに `AGENTS.md`(§4の内容)が存在し、`CLAUDE.md` はmonorepo・スターターのどこにも存在しない。
- [ ] `packages/core/rules/` に13ファイル(post-type/taxonomy/user-role/meta/acf/form/form-mail/page/model/options/hooks/view/extensions)が存在し、composer配布物に含まれる(`composer archive` の内容で確認)。
- [ ] 各レシピが共通フォーマット(§6.2: 手順/完全な実装例/してはいけないこと/確認方法)を満たす。
- [ ] `starters/theme` / `starters/plugin` が§8/§9のツリーどおりに存在する。
- [ ] スターター直下に `README.md`(§8.7)が存在し、セットアップ手順・create-project例・AGENTS.md/rules/への導線を含む(規約本文の転載はないこと)。
- [ ] スターターのAGENTS.mdのタスク対応表のパス(`vendor/tmswp/core/rules/*.md`)が実在ファイルと一致する。

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
