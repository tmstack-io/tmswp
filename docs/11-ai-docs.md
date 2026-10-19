# 11. AI向け資料一式(AGENTS.md / rules / スターター)設計書

## 目的

TMStack WordPress Framework の明示目的「AIが自立実装できるFW」(要件2・DR-13)を実体化する資料一式を確定させる。本文書は以下の「全文案」を含む。実装者はここに書かれたMarkdown/PHPファイルをそのままリポジトリへ配置すればよい。

1. コアmonorepoリポジトリ直下の `AGENTS.md`(FWコントリビュータ向け)
2. スターター同梱の `AGENTS.md`(FW利用者=テーマ/プラグイン開発AI向け。theme版全文+plugin版差分)
3. `rules/` ディレクトリ(機能別レシピ10本)の構成と各レシピ全文
4. `starters/theme`(tmswp/starter-theme)・`starters/plugin`(tmswp/starter-plugin)のファイルツリーと主要ファイル内容
5. `composer create-project` フロー(post-create-project-cmd によるプロジェクト名置換)

## スコープ(対象パッケージ)

- monorepoルート: `AGENTS.md`
- `packages/core/rules/`(レシピ10本。配布はtmswp/coreパッケージに同梱)
- `starters/theme/`(tmswp/starter-theme)全ファイル
- `starters/plugin/`(tmswp/starter-plugin)全ファイル

PHPクラスの新規定義は行わない。本文書が参照するFWのAPIはすべてCONTRACTおよび各担当文書(02〜10)が定義するものであり、レシピ内のコード例はそれらの利用例である。

## 依存文書

- 01-overview.md(全体アーキテクチャ・monorepo構成)
- 02-kernel.md(Kernel::boot / KernelConfig / Discovery / キャッシュ)
- 03-entity.md(PostType / Taxonomy / UserRole 定義層)
- 04-meta-option.md(MetaSchema / MetaBag / オプショングループ(backed enum + OptionAccess))
- 05-form.md(Form / FormDefinition / Rule群)
- 06-model.md(Model / TableDefinition / QueryBuilder / Migrator)
- 07-view-support.md(Template / Support / Log)
- 08-extensions.md(thumbnail / settings-page / ja-toolkit)
- 09-acf-bridge.md(acf-json運用・コードジェネレータ)
- 10-qa.md(composer qa / PHPStan / CI)

※文書番号は最終的なファイル名に合わせて読み替える。本文書はCONTRACTの正準APIのみを前提とし、CONTRACT外のAPIを参照する箇所は各レシピ内の「整合確認」注記に列挙した。

---

## 1. 設計方針

### 1.1 AI資料の3層構造

| 層 | 実体 | 読者AIの状況 | 更新主体 |
|---|---|---|---|
| 規約層 | `AGENTS.md`(リポジトリ直下) | セッション開始時に必ず読む。プロジェクト全体の規約・禁止事項・コマンドを把握する | FW(スターター由来)+利用者追記 |
| レシピ層 | `vendor/tmswp/core/rules/*.md` | 「CPTを追加して」等のタスク開始時に該当1ファイルだけ読む | FWコア(composer updateで自動更新) |
| 雛形層 | starterが生成した `app/` スケルトン・設定ファイル | コード生成時に既存ファイルを模倣する | create-project時に1回生成 |

### 1.2 rules/の単一真実源戦略

レシピ(rules/)は **tmswp/coreパッケージに同梱し、利用側プロジェクトからは `vendor/tmswp/core/rules/` を参照する**。スターターへの物理コピーは行わない。

- 理由1: コピー配布はFW更新とレシピの乖離(ドリフト)を必ず生む。vendor参照ならインストール済みコアのバージョンと常に一致する。
- 理由2: AIエージェントにとってvendor配下のMarkdownを読むコストはゼロであり、コピーの利点がない。
- 理由3: 旧iwf2bの最大の失敗は「規約がどこにも文書化されず15案件が定型コードをコピペで増殖させた」ことであり、コピー方式はこの失敗の再演になる。

スターターの `AGENTS.md` はタスク種別→レシピファイルの対応表を持ち、AIを該当レシピへ誘導する(§5)。拡張パッケージ固有の詳細(acf-bridgeの内部仕様等)は各拡張パッケージのREADMEが持つが、利用者AIが最初に読むべき導入手順はコアの `rules/acf.md` / `rules/extensions.md` に集約する(コアのrules/だけ見れば次の一手が必ず分かる状態を保証する)。

### 1.3 レシピの完結性要件

各レシピは次を満たす(受け入れ基準§10で検証):

1. **単独完結**: そのレシピ1ファイルだけを読んだAIが、AGENTS.mdの規約前提のもとで該当タスクを完遂できる。他レシピへの参照は「発展」節のみに置く。
2. **完全動作コード**: コード例は省略(`// ...`)なしでコピーして動く完全形。`declare(strict_types=1)`・`use`文・名前空間まで含める。
3. **配置規約の明示**: 「どのディレクトリに・どのファイル名で・どの名前空間で」置くかを冒頭で確定させる。
4. **検証手順**: レシピ末尾に必ず「確認方法」(composerコマンド+WP上での目視確認手順)を置く。
5. **アンチパターン**: 旧FW由来の事故や typicalなAIの誤実装を「してはいけないこと」として明記する。

### 1.4 成果物配置マップ

```
tmswp/ (monorepo)
├── AGENTS.md                       … §4 全文(コントリビュータ向け)
├── CLAUDE.md                       … 「@AGENTS.md」1行のみ(参照ファイル)
├── packages/
│   └── core/
│       └── rules/                  … §6-§9 レシピ11本(パッケージに同梱して配布)
│           ├── post-type.md
│           ├── taxonomy.md
│           ├── meta.md
│           ├── acf.md
│           ├── form.md
│           ├── actions.md
│           ├── model.md
│           ├── options.md
│           ├── hooks.md
│           ├── view.md
│           └── extensions.md
└── starters/
    ├── theme/                      … §7 tmswp/starter-theme
    │   ├── AGENTS.md               … §5 全文(利用者向け・theme版)
    │   └── (ツリーは§7参照)
    └── plugin/                     … §8 tmswp/starter-plugin
        ├── AGENTS.md               … §5 差分(plugin版)
        └── (ツリーは§8参照)
```

`CLAUDE.md` は中身を持たず `@AGENTS.md` への参照1行のみとする(Claude Code系エージェント互換のため。二重管理を禁止する)。スターターにも同形式で同梱する。

---

## 2. 文書間の用語固定

本文書のレシピ・AGENTS.mdが使うコマンド名は10-qa.mdの定義に従い、次に固定する。

| コマンド | 内容 |
|---|---|
| `composer lint` | php-cs-fixer dry-run(差分表示のみ) |
| `composer fix` | php-cs-fixer 自動修正 |
| `composer analyse` | PHPStan level max |
| `composer test:unit` | PHPUnit(ユニット層。WP非依存・Docker不要) |
| `composer test:coverage` | ユニットテスト+カバレッジ計測 |
| `composer test:integration` | PHPUnit(統合層、wp-env必須) |
| `composer test` | `test:unit` → `test:integration` の合成(wp-env/Docker必要) |
| `composer qa:fast` | lint + analyse + test:unit(Docker不要。**AIの実装中の反復ループはこれ**) |
| `composer qa` | lint + analyse + test を直列実行(コミット前必須ゲート。統合テストを含むためDocker必要) |

利用側プロジェクト(スターター)にも同名のcomposer scriptsを定義し、**人間・CI・AIが同一コマンドを叩く**(DR-12)。ただしスターターは統合テストを既定で持たないため、スターター側の `composer test` はユニット層のみを指す(範囲差は§5のAGENTS.mdに注記する)。コマンド意味論の正準は10-quality.md §1であり、本文書内の全コマンド表記はこれとgrep突合で一致させる。

WP-CLIコマンド名も各担当文書の正準名に固定する。本文書のAGENTS.md・全レシピはこの表の表記のみを使う。

| WP-CLIコマンド | 内容 | 正準定義 |
|---|---|---|
| `wp tmswp cache clear` | 探索キャッシュ(ComponentCache)の破棄・再生成 | 02-kernel |
| `wp tmswp migrate` | Model(独自テーブル)のマイグレーション実行 | 06-model |
| `wp tmswp acf sync` | acf-jsonから型付きアクセサクラスを再生成 | 09-acf-bridge |
| `wp tmswp acf check` | acf-jsonと生成クラスのドリフト検査(差分があればexit 1) | 09-acf-bridge |

---

## 4. コアmonorepo直下 AGENTS.md(全文案)

読者は「FW本体を開発・修正するAI(コントリビュータ)」。monorepoルートに配置する。以下のフェンス内が全文。

````markdown
# TMStack WordPress Framework — Contributor Guide

このリポジトリは TMStack WordPress Framework の monorepo である。
あなた(AIエージェント)がここで行うのは **フレームワーク本体の開発** である。
利用側プロジェクト(テーマ/プラグイン)の開発規約は starters/*/AGENTS.md と packages/core/rules/ にある。混同しないこと。

## アーキテクチャ要約

- 対象: PHP 8.2+ / WordPress 6.4+。コアのruntime依存は psr/log ^3 と psr/container ^2 のみ。
- 思想: 「app/ にクラスを置くだけ」の規約ベース自動探索(PHP8 Attribute + クラスマップ + ファイルキャッシュ)。
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
| starters/theme | tmswp/starter-theme | App\ | create-project用テーマ雛形 |
| starters/plugin | tmswp/starter-plugin | PluginApp\ | create-project用プラグイン雛形 |

コアのモジュールマップ(TMSWP\Core\ 配下の正準名)は docs/ 配下の設計書群を一次資料とする。
設計書に定義のないpublic APIを新設する場合は、必ず設計書の更新を同一PRに含めること。

## 必須コマンド(コミット前ゲート)

```bash
composer qa                 # lint + analyse + test。これが通らない変更はコミット禁止(統合テストを含むため wp-env/Docker が必要)
composer qa:fast            # lint + analyse + test:unit。Docker不要の高速ループ(実装中の反復はこちらを使う)
composer test               # ユニット+統合テスト(test:unit → test:integration。wp-env起動が必要: npx wp-env start)
composer test:unit          # ユニットテスト(WP非依存・Brain Monkey。Docker不要)
composer test:coverage      # ユニットテスト+カバレッジ計測
composer test:integration   # 統合テスト(wp-env起動が必要: npx wp-env start)
composer analyse            # PHPStan level max(baseline禁止)
composer lint               # コードスタイル検査(php-cs-fixer dry-run)
composer fix                # コードスタイル自動修正
```

- PHPStanは **level max・baselineなし** で運用する。エラーを baseline や @phpstan-ignore で握りつぶす変更は拒否される。
  ignoreが本当に必要な場合(WP関数のstub不備等)は理由コメントを併記し、PRで明示すること。
- テストはユニット層(tests/Unit, WP非依存)と統合層(tests/Integration, wp-env + wp-phpunit)の2層。
  新規クラスにはユニットテストを必ず追加する。WP関数に触れるロジックは薄い境界クラスに寄せ、境界より内側をユニットテスト可能に保つ。

## コーディング規約

- `declare(strict_types=1);` 全ファイル必須。PSR-12 + php-cs-fixer設定(.php-cs-fixer.dist.php)に従う。
- クラスは原則 `final`。継承を許す基底(PostType/Taxonomy/UserRole/Form/Model/Hook)のみ非final
  (オプショングループはbacked enumであり基底クラスを持たない。04-meta-options.md参照)。
- 全プロパティ・引数・戻り値に型宣言。`mixed` の使用は境界(WP API・$_POST等)に限定し、内側で即座に絞り込む。
- **禁止**: `__call` / `__callStatic` / `__get` / `__set` 等のマジックメソッド、`extract()`、`eval()`、
  グローバル変数の新設、`exit`/`die`(リダイレクト直後を除く)、WP標準テンプレート階層への介入(template_includeの差し替え)。
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
3. ルートの `phpstan.neon` の paths と `phpunit.xml.dist` の testsuite に新パッケージを追加する。
4. `.github/workflows/` のテストマトリクスと split.yml の matrix(read-only split対象リポジトリ)に追加する。
5. Packagist登録はメンテナが行う。PRには split 先リポジトリ名の提案を含める。
6. 拡張パッケージは **コアのAPIのみに依存** する。拡張同士の依存は原則禁止(必要ならPRで合意を取る)。

## 禁止事項(コントリビュータ向け)

- コアに外部プラグイン(ACF/CF7/Yoast等)の関数・クラス参照を追加すること。
- コアのruntime依存(psr/log, psr/container以外)を追加すること。
- `register_post_type` 等のWP登録系を `init` 等の正規フック外で即時実行すること。
- packages/* 間でソースをコピーすること(共有したいコードはcoreのSupportへ昇格を検討)。
- starters/ 配下とrules/ 配下の規約・コード例を、コアのAPI変更に追従させずに放置すること
  (public API変更時は `grep -r <旧API> packages/core/rules starters` で追従漏れを確認する)。

## リリース

- SemVer。タグ `vX.Y.Z` をmonorepoに打つと、CIが各パッケージへsplitしタグを伝播する。
- リリース手順の詳細は docs/ のQA/リリース設計書を参照。
````

---

## 5. スターター同梱 AGENTS.md(全文案)

読者は「このテーマ/プラグインを実装するAI(FW利用者)」。starter-theme・starter-pluginの直下に配置し、create-projectでそのままプロジェクトに残る。theme版を全文、plugin版を差分で示す。

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
| 投稿/ターム/ユーザーにカスタムフィールド(非ACF)を持たせる | vendor/tmswp/core/rules/meta.md |
| ACFでフィールドを管理する(acf-json運用) | vendor/tmswp/core/rules/acf.md |
| フォーム(問い合わせ・申込み等)を実装する | vendor/tmswp/core/rules/form.md |
| テンプレートの前処理(データ取得・フォーム駆動)を分離する | vendor/tmswp/core/rules/actions.md |
| 独自DBテーブルを作って読み書きする | vendor/tmswp/core/rules/model.md |
| サイト設定値(オプション)を定義する | vendor/tmswp/core/rules/options.md |
| add_action/add_filter をまとめて登録する | vendor/tmswp/core/rules/hooks.md |
| テンプレートパーツの読み込み・ロジック分離 | vendor/tmswp/core/rules/view.md |
| サムネイル/設定ページ/日本語対応の追加 | vendor/tmswp/core/rules/extensions.md |

## プロジェクト構成

```
.
├── style.css            # テーマヘッダ
├── functions.php        # FWブートのみ。ロジックを書かない
├── index.php ほか       # WordPress標準のテンプレート階層(通常のWPテーマと完全に同じ)
├── actions/             # テンプレート前処理ファイル。ページテンプレートと同名(rules/actions.md)
├── parts/               # テンプレートパーツ(Template::part()/get_template_part()の読込先)
├── app/                 # PHPクラスはすべてここ(名前空間 App\、PSR-4)
│   ├── Post/            # 投稿タイプ定義(App\Post\*Post)
│   ├── Tax/             # タクソノミー定義(App\Tax\*Tax)
│   ├── User/            # ロール定義(App\User\*User)
│   ├── Form/            # フォーム定義(App\Form\*Form)
│   ├── Model/           # 独自テーブル(App\Model\*Model)
│   ├── Option/          # オプション定義(App\Option\*Option。backed enum)
│   └── Hook/            # フック集約(App\Hook\*Hook)
├── tests/               # ユニットテスト
├── composer.json
├── phpstan.neon
└── .php-cs-fixer.dist.php
```

## ロジックの置き場(配置決定木)

書こうとしているコードがどこに属するかは、次の1問で決まる。

- **特定のページテンプレートの「表示前」処理**(そのテンプレートでだけ必要なデータ取得・整形・フォーム送信処理)なら
  → `actions/{テンプレート相対パス}.php`(ページテンプレートと同名のファイル。例: `page-contact.php` → `actions/page-contact.php`)。
  WordPressがテンプレートを決めた直後に1回だけ実行され、`return` した配列がテンプレートで `tms_var()` から読める(rules/actions.md)。
- **それ以外のロジック**(投稿タイプ・タクソノミー・メタ・フォーム定義・独自テーブル・オプション・WPフック)は
  すべて `app/` 配下の `final class`(下表)。リクエスト種別を問わず動くフック処理は `app/Hook/`(rules/hooks.md)。

`actions/` はクラスではなくデータ準備専用のPHPファイルである(Attributeも継承も不要)。出力(echo)・フック登録・`$wp_query->set_404()` の直呼びは禁止(rules/actions.md。出力・`set_404()` 直呼びはFW同梱のPHPStanルール、孤児actionsは `bin/tms-check` が `composer qa` 内で機械検査する)。全テンプレート共通の前処理用ファイル(`actions/common.php` 等)は持たない。共通ロジックは `app/Hook/` か、パーツ内でモデルのアクセサを直接読む形にする。

## クラス追加の基本手順(全機能共通)

1. `app/` 配下の該当ディレクトリに `final class` を1ファイル1クラスで作る(PSR-4: `App\Post\NewsPost` → `app/Post/NewsPost.php`)。
2. クラスに対応するAttribute(`#[AsPostType]` 等)を付け、FWの基底クラスを継承する。
3. **登録コードは書かない。** app/ に置くだけでKernelが探索・登録する(functions.phpへの追記は不要)。
4. `composer qa` を実行して通す。
5. 反映確認はWP上で行う(各レシピの「確認方法」参照)。クラスを追加してもWPに現れない場合は
   `wp tmswp cache clear` で探索キャッシュを破棄して再確認する。

## 必須コマンド

```bash
composer qa        # lint + analyse + tms:check + test。タスク完了前に必ず通すこと
composer analyse   # PHPStan level max(app/ が対象)
composer tms:check # actions/ 規約検査(FW同梱PHPStanルール + bin/tms-check)
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
7. **探索キャッシュファイルの手動編集・依存**。探索キャッシュ(ComponentCache)はプロジェクト外の
   `wp-content/cache/tmswp/` に生成される。内容を手で編集・コミットせず、デプロイ時は
   `wp tmswp cache clear` で破棄・再生成する。

## デバッグ

- ログ: `\TMSWP\Core\Log\Log::info('message', ['context' => $value]);`(チャンネル分割は `Log::channel('mail')->error(...)`)。
  出力先・閾値はfunctions.phpのブート設定で構成済み。`var_dump`/`print_r` をコミットに残さない。
- 探索まわりの不調(クラスを置いたのに有効化されない)は、まずクラス名とファイルパスのPSR-4整合、次にAttribute付け忘れ、最後に `wp tmswp cache clear` の順で確認する。
````

### 5.2 plugin版(starters/plugin/AGENTS.md)— theme版との差分

plugin版はtheme版と同一の章立て・文面とし、次の箇所のみ差し替える。

1. 冒頭文: 「WordPressテーマである」→「WordPressプラグインである」。
2. 「プロジェクト構成」のツリーを次に差し替え:

```
.
├── my-plugin.php        # プラグイン本体ヘッダ+FWブートのみ(create-projectで実名にリネームされる)
├── app/                 # (theme版と同一)
├── templates/           # プラグイン同梱テンプレート(Template::part()のフォールバック先)
├── tests/
├── composer.json
├── phpstan.neon
└── .php-cs-fixer.dist.php
```

3. 「テンプレートパーツ」関連の記述: テーマの `parts/` ではなく「`templates/` がプラグイン同梱フォールバック。テーマ側に同名ファイルがあればテーマが優先される(rules/view.md参照)」と差し替え。
4. 「禁止事項」3項のテンプレート階層の説明は維持(プラグインでも `template_include` 乗っ取り禁止)。加えて「有効化フックで必要な処理(Modelのマイグレーション)はFWが自動配線するため、`register_activation_hook` を直接書かない(rules/model.md参照)」を追記。
5. **`actions/` 規約はプラグインでは使わない**。プラグインはトップレベルのテンプレート供給機構を持たず ActionRouter が生成されないため、タスク対応表の「テンプレートの前処理」行・「ロジックの置き場(配置決定木)」節・プロジェクト構成ツリーの `actions/` を削除する。「必須コマンド」節からも `composer tms:check`(actions/ 規約検査)の行を削除し、`composer qa` の説明を「lint + analyse + test」とする。テンプレート単位の前処理に相当する処理が必要なら `app/Hook/` の `#[AsHook]` クラス(rules/hooks.md)で行う。
6. テキストドメイン: 「プラグイン本体ヘッダのText Domainを使う」。

---

## 6. rules/ ディレクトリ構成と共通フォーマット

### 6.1 構成

`packages/core/rules/` に以下11ファイルを置く。tmswp/coreパッケージの配布物に含める(composer.jsonのarchive excludeに入れない)。

| ファイル | 対象タスク | 主依存文書 |
|---|---|---|
| post-type.md | CPT追加 | 03-entity |
| taxonomy.md | タクソノミー追加 | 03-entity |
| meta.md | 非ACFカスタムフィールド | 04-meta-option |
| acf.md | acf-json運用+型付きアクセサ生成 | 09-acf-bridge |
| form.md | フォーム実装(3画面+メール) | 05-form |
| actions.md | テンプレート前処理(テンプレート同名 actions/ ファイル規約) | 07-view-support |
| model.md | 独自テーブル | 06-model |
| options.md | サイト設定値 | 04-meta-option |
| hooks.md | WPフック登録の置き場 | 02-kernel |
| view.md | テンプレートパーツ運用 | 07-view-support |
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

レシピ内のコード例の名前空間は `App\` 固定(スターター既定)。`KernelConfig` で別名前空間を選んだプロジェクトは読み替える旨を各レシピ冒頭に1行で示す。

以降§7に10本の全文案を示す。コード例が参照するAPIのうちCONTRACT正準形に含まれないもの(ビルダメソッド名等)は、各レシピ末尾の「整合確認」注記(設計書側のみの注記であり、配布されるレシピ本文には含めない)に列挙した。担当文書(03〜09)の確定APIと食い違う場合は **担当文書を正としてレシピ側を修正** する。

### 6.3 レシピ検証CI(コード例ドリフトの機械検出)

rules/*.md内のコード例とコアAPIの乖離(ドリフト)は、10-qa.mdのGitHub Actionsワークフローに追加するCIジョブ `rules-verify` で機械検出する。実装方式は次に確定する:

1. **抽出**: `tools/extract-rules-code.php`(monorepo同梱。vendor非依存・PHP標準関数のみ)が `packages/core/rules/*.md` から ```` ```php ```` フェンスのコードブロックを抽出する。`<?php` で始まり `namespace` 宣言を含むブロックを「クラス定義例」とみなし、`tmp/rules-ci/app/` 配下へPSR-4整合のパス(例: `App\Post\NewsPost` → `tmp/rules-ci/app/Post/NewsPost.php`)で書き出す。
2. **テンプレート断片**: `namespace` 宣言を含まないブロック(page-contact.php等のWPグローバル文脈コード)は `tmp/rules-ci/templates/` へ `<レシピ名>-<連番>.php` で書き出し、`php -l` による構文チェックのみ行う(PHPStan対象外。§8.6の方針と同一)。
3. **検証**: starter-theme をフィクスチャとして展開し(composer.jsonの repositories(path) で `packages/core` を参照)、手順1のファイルを配置した状態で `composer lint` と `composer analyse`(PHPStan level max)を実行する。いずれかの失敗でジョブを失敗させる。
4. **実行契機**: `packages/core/`(src・rules)または `starters/` に変更を含むすべてのPR。

これにより「コアのpublic API変更にrules/・starters/が追従していない」状態をマージ前に継続検出する(§12.2・§12.5の検収項目に対応)。

---

## 7. レシピ全文案

### 7.1 rules/post-type.md

````markdown
# カスタム投稿タイプ(CPT)を追加する

投稿タイプ1つにつきクラス1つを `app/Post/` に置くだけで、登録・ラベル生成・クエリヘルパまで揃う。
`register_post_type()` を直接呼んではいけない。
(名前空間はスターター既定の `App\`。変更している場合は読み替える)

## 手順

1. `app/Post/<名前>Post.php` を作る。クラス名は英語単数形+`Post` サフィックス(例: お知らせ→`NewsPost`)。
2. `#[AsPostType]` を付けて `TMSWP\Core\Post\PostType` を継承し、`definition()` で設定を返す。
3. `composer qa` を通し、WP管理画面に投稿タイプが現れることを確認する。

## 完全な実装例

`app/Post/NewsPost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class NewsPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('news')
            ->label('お知らせ')
            ->labelLanguage(LabelLanguage::Ja)           // 日本語サイトではラベル言語を明示する
            ->menuIcon('dashicons-megaphone')
            ->supports('title', 'editor', 'thumbnail')
            ->hasArchive();
    }
}
```

ポイント:

- スラッグ(`'news'`)は20文字以内・英小文字とアンダースコアのみ。`wp_` と `_` 始まりは禁止。
- `labelLanguage(LabelLanguage::Ja)` を明示すると、管理画面の全ラベルが日本語テンプレートから自動生成される。
  未指定時は英語テンプレートで生成される。
- 公開しない内部用CPTは `->public(false)` を併用する。

## テンプレートでの使い方

WordPress標準のテンプレート階層がそのまま使える: `archive-news.php` / `single-news.php` を作るだけでよい。
ループも標準の `WP_Query` / `have_posts()` で書く。

クラスは型安全なヘルパを提供する(テンプレートではFQCNで呼ぶ):

```php
<a href="<?php echo esc_url( (string) \App\Post\NewsPost::archiveUrl() ); ?>">お知らせ一覧</a>

<?php foreach ( \App\Post\NewsPost::query()->limit( 5 )->get() as $news ) : ?>
    <a href="<?php echo esc_url( \App\Post\NewsPost::permalink( $news ) ); ?>">
        <?php echo esc_html( get_the_title( $news ) ); ?>
    </a>
<?php endforeach; ?>
```

## アイキャッチ未設定時のフォールバック画像

`PostType::thumbnail()` はアイキャッチが未設定のときに `tmswp/post/thumbnail_fallback` フィルタを呼ぶ。フィルタは `?TMSWP\Core\Post\Thumbnail $thumbnail, WP_Post $post, string|array $size` を受け取り、代替画像がある場合だけ `TMSWP\Core\Post\Thumbnail` DTO を返す。それ以外の戻り値は無視される。

本文先頭の `<img src>` を使う場合は、抽出規則を app 側の要件に合わせて実装する。遅延読み込み属性や `srcset` を優先したい場合も、このクラス内で拡張する。

`app/Hook/ThumbnailFallbackHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;
use TMSWP\Core\Post\Thumbnail;
use WP_Post;

#[AsHook]
final class ThumbnailFallbackHook extends Hook
{
    /**
     * @param string|array<int> $size
     */
    #[Filter('tmswp/post/thumbnail_fallback')]
    public function firstContentImage(?Thumbnail $thumbnail, WP_Post $post, string|array $size): ?Thumbnail
    {
        if ($thumbnail instanceof Thumbnail) {
            return $thumbnail;
        }

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $matches) !== 1) {
            return null;
        }

        $url = esc_url_raw($matches[1], ['http', 'https']);

        if ($url === '') {
            return null;
        }

        $dimensions = $this->dimensions($size);

        return new Thumbnail($url, $dimensions['width'] ?? null, $dimensions['height'] ?? null, '');
    }

    /**
     * @param string|array<int> $size
     *
     * @return array{width: int, height: int}|null
     */
    private function dimensions(string|array $size): ?array
    {
        if (!is_array($size) || !isset($size[0], $size[1]) || !is_int($size[0]) || !is_int($size[1])) {
            return null;
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }
}
```

## してはいけないこと

- `register_post_type()` / `add_action( 'init', ... )` を自分で書く(クラスを置けばFWが正しいタイミングで登録する)。
- 1クラスに複数の投稿タイプを定義する(1クラス=1投稿タイプ)。
- `#[AsPostType]` の付け忘れ(置いただけでは探索されない)。
- functions.php に投稿タイプ関連のコードを書く。

## 確認方法

1. `composer qa` が通ること。
2. 管理画面の左メニューに「お知らせ」が表示され、追加・公開できること。
3. `hasArchive()` を指定した場合、パーマリンク設定を保存し直してから `/news/` で一覧が表示されること。
4. クラスを追加したのに現れない場合: `wp tmswp cache clear` を実行して再確認。

## 発展

- カスタムフィールドを持たせる → rules/meta.md(非ACF) / rules/acf.md(ACF)
- 専用カテゴリを持たせる → rules/taxonomy.md
````

> 整合確認(03-entity.md): `PostTypeDefinition::create/label/menuIcon/supports/hasArchive/public`、`PostType::query()`(`PostQuery::limit/get`)、静的ヘルパ `archiveUrl(): ?string` / `permalink(\WP_Post|int $post): string`、WP-CLI `wp tmswp cache clear`(02-kernel)。

### 7.2 rules/taxonomy.md

````markdown
# カスタムタクソノミーを追加する

タクソノミー1つにつきクラス1つを `app/Tax/` に置くだけで登録が完了する。
`register_taxonomy()` を直接呼んではいけない。
(名前空間はスターター既定の `App\`。変更している場合は読み替える)

## 手順

1. `app/Tax/<名前>Tax.php` を作る。クラス名は英語+`Tax` サフィックス(例: `NewsCategoryTax`)。
2. `#[AsTaxonomy]` を付けて `TMSWP\Core\Tax\Taxonomy` を継承し、`definition()` で設定を返す。
3. 紐づけ先の投稿タイプは **クラス参照**(`NewsPost::class`)で指定する。スラッグ文字列を二重管理しない。
4. `composer qa` を通し、対象投稿タイプの編集画面にタクソノミーが現れることを確認する。

## 完全な実装例

`app/Tax/NewsCategoryTax.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tax;

use App\Post\NewsPost;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;

#[AsTaxonomy]
final class NewsCategoryTax extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('news_category')
            ->label('お知らせカテゴリ')
            ->labelLanguage(LabelLanguage::Ja)
            ->objectType(NewsPost::class)   // 紐づけ先CPT(複数指定可)。クラス参照で書く
            ->hierarchical();               // カテゴリ型。タグ型なら外す
    }
}
```

ポイント:

- スラッグ(`'news_category'`)は32文字以内。WP予約語(`post`, `page`, `type` 等)と衝突させない。
- 標準投稿に紐づける場合のみ文字列 `'post'` を渡せる: `->objectType('post')`。

## テンプレートでの使い方

テンプレート階層は標準どおり: `taxonomy-news_category.php` が使える。ターム一覧・投稿のターム取得は型安全ヘルパを使う:

```php
<?php foreach ( \App\Tax\NewsCategoryTax::query()->get() as $term ) : ?>
    <a href="<?php echo esc_url( (string) get_term_link( $term ) ); ?>">
        <?php echo esc_html( $term->name ); ?>
    </a>
<?php endforeach; ?>

<?php foreach ( \App\Post\NewsPost::terms( get_the_ID(), \App\Tax\NewsCategoryTax::class ) as $term ) : // 投稿のターム取得は投稿タイプ側のヘルパ ?>
    <span><?php echo esc_html( $term->name ); ?></span>
<?php endforeach; ?>
```

## してはいけないこと

- `register_taxonomy()` を直接呼ぶ。
- `->objectType('news')` のようにCPTスラッグを文字列で書く(必ず `NewsPost::class`。スラッグ変更に自動追従させるため)。
- タクソノミー定義クラスをCPTクラスより先に読ませようとして順序調整コードを書く(登録順はFWが解決する)。

## 確認方法

1. `composer qa` が通ること。
2. 対象CPTの編集画面サイドバーにタクソノミーが表示され、ターム追加・割り当てできること。
3. パーマリンク設定を保存し直してからタームアーカイブ(`/news_category/{term}/`)が表示されること。

## 発展

- タームにカスタムフィールドを持たせる → rules/meta.md の「タームメタ」節
````

> 整合確認(03-entity.md): `TaxonomyDefinition::create/label/objectType(string ...$objectTypes。スラッグまたはPostTypeサブクラスFQCN)/hierarchical`、`Taxonomy::query()`(`TermQuery::get`)、`PostType::terms(\WP_Post|int $post, string $taxonomy): array`(戻り値 `list<\WP_Term>`。taxonomyはFQCN可)/ `PostType::firstTerm(): ?\WP_Term`。03-entity.mdの確定APIと突合済み。

### 7.3 rules/meta.md

````markdown
# カスタムフィールド(非ACF)を定義して読み書きする

投稿・ターム・ユーザーのメタを、エンティティ定義クラスの属性付き定数として宣言する。
宣言すると `register_post_meta` 等への登録・型付きアクセス・REST公開が自動化される。
ACF管理のフィールドにはこのレシピを使わない(rules/acf.md へ)。
(名前空間はスターター既定の `App\`。変更している場合は読み替える)

## 手順

1. 対象のエンティティ定義クラス(`app/Post/*Post.php` 等)に、`#[MetaSchema]` 付きのpublic定数を追加する。
   定数名は `META_` プレフィックス、値がメタキー文字列。
2. 読み書きは `クラス::meta($post)` が返す型付きアクセサ(MetaBag)経由で行う。
3. テンプレート等で頻出するフィールドは、定義クラスに **明示アクセサメソッドを書く**(必須規約)。
4. `composer qa` を通す。

## 完全な実装例(投稿メタ)

`app/Post/ProductPost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use WP_Post;

#[AsPostType]
final class ProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0, showInRest: true)]
    public const META_PRICE = 'price';

    #[MetaSchema(type: MetaValueType::String, default: '')]
    public const META_MAKER = 'maker';

    #[MetaSchema(type: MetaValueType::Boolean, default: false)]
    public const META_IS_LIMITED = 'is_limited';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')
            ->label('製品')
            ->supports('title', 'editor', 'thumbnail')
            ->hasArchive();
    }

    /** 税抜価格(円)。頻出フィールドは必ずこの形の明示アクセサを書く。 */
    public static function price(WP_Post|int $post): int
    {
        return self::meta($post)->int(self::META_PRICE);
    }

    /** メーカー名。 */
    public static function maker(WP_Post|int $post): string
    {
        return self::meta($post)->string(self::META_MAKER);
    }
}
```

読み書き:

```php
use App\Post\ProductPost;

// 読み取り(型付き。defaultが適用されるため戻り値は非null)
$price = ProductPost::price( $post );                                   // int(明示アクセサ。推奨)
$maker = ProductPost::meta( $post )->string( ProductPost::META_MAKER ); // string(汎用形)
$has   = ProductPost::meta( $post )->has( ProductPost::META_PRICE );    // bool(値が保存されているか)

// 書き込み・削除
ProductPost::meta( $post )->set( ProductPost::META_PRICE, 19800 );
ProductPost::meta( $post )->delete( ProductPost::META_IS_LIMITED );
```

## タームメタ・ユーザーメタ

定義方法は投稿とまったく同じ。`Taxonomy` / `UserRole` 継承クラスに `#[MetaSchema]` 定数を置く。
タームメタの完全例(`app/Tax/NewsCategoryTax.php`):

```php
<?php

declare(strict_types=1);

namespace App\Tax;

use App\Post\NewsPost;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;
use WP_Term;

#[AsTaxonomy]
final class NewsCategoryTax extends Taxonomy
{
    #[MetaSchema(type: MetaValueType::String, default: '')]
    public const META_COLOR = 'color';

    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('news_category')
            ->label('お知らせカテゴリ')
            ->labelLanguage(LabelLanguage::Ja)
            ->objectType(NewsPost::class)
            ->hierarchical();
    }

    /** カテゴリの表示色(CSSカラー文字列)。 */
    public static function color(WP_Term|int $term): string
    {
        return self::meta($term)->string(self::META_COLOR);
    }
}
```

利用側:

```php
$color  = \App\Tax\NewsCategoryTax::color( $term );   // 明示アクセサ(推奨)

// ユーザーメタも同形: UserRole継承クラス(app/User/)に #[MetaSchema] 定数を置き、meta($userId)で読み書きする
$gender = \App\User\MemberUser::meta( $userId )->string( \App\User\MemberUser::META_GENDER );
```

## MetaSchemaの主な引数

| 引数 | 型 | 既定 | 意味 |
|---|---|---|---|
| type | MetaValueType | 必須 | `MetaValueType::String` `::Integer` `::Number` `::Boolean` `::Array` `::Object`(WP register_meta準拠の6型に対応するenum。`use TMSWP\Core\Meta\MetaValueType;`) |
| default | mixed | 必須 | 未保存時にアクセサが返す値 |
| showInRest | bool | false | REST API/ブロックエディタへ公開するか |
| single | bool | true | 単一値か複数値か |

## してはいけないこと

- `get_post_meta()` / `update_post_meta()` 等を直接呼ぶ(型保証・default・REST登録が迂回される)。
- メタキー文字列のリテラル直書き(`->int('price')` は禁止。必ず `ProductPost::META_PRICE`)。
- `META_` 定数を `#[MetaSchema]` なしで置く(登録されず、PHPStanの型ナローイングも効かない)。
- 同じメタキーをACF(acf-json)とこのレシピの両方で宣言する(起動時エラーになる。真実源はどちらか一方)。
- 存在しない値の判定に `null` やfalsyを使う(未保存判定は `->has()` を使う。defaultが返るため `get` 系では区別できない)。

## 確認方法

1. `composer qa` が通ること(FW同梱のPHPStan拡張が `#[MetaSchema]` の型と `->int()`/`->string()` の整合を検査する。
   型不一致は `composer analyse` で検出される)。
2. `showInRest: true` のフィールドが `wp-json/wp/v2/product` のレスポンス `meta` に含まれること。
3. `set()` した値が管理画面のカスタムフィールド欄(表示を有効化した場合)とDBに反映されること。

## 発展

- 編集画面の入力UIが必要な場合: ACF利用なら rules/acf.md。非ACFで管理画面UIまで作る場合はブロックエディタの
  メタ対応(showInRest: true + ブロック側実装)を使う。FWは入力UIを生成しない。
- プレビュー時にもメタを保存したい場合: `TMSWP\Core\Meta\PreviewMetaSupport`(オプトイン)を参照。
````

> 整合確認(04-meta-option.md): `MetaBag::int/string/bool/has/set/delete`、`MetaSchema(type: MetaValueType, default:, showInRest:, single:)`(type引数は文字列でなく公開enum `TMSWP\Core\Meta\MetaValueType` を受ける)、`PostType::meta(\WP_Post|int)` / `Taxonomy::meta(\WP_Term|int)` / `UserRole::meta(\WP_User|int)`、PHPStan拡張による型ナローイング(DR-05)。

### 7.4 rules/acf.md

````markdown
# ACFでフィールドを管理する(acf-json運用+型付きアクセサ生成)

ACF(Advanced Custom Fields)を使うプロジェクトでは **acf-json を唯一の真実源** とし、
コードジェネレータで型付きアクセサクラスを生成して読み書きする。
`get_field()` の文字列キー直書きをアプリコードに散らさないこと。

前提: このプロジェクトに `tmswp/acf-bridge` がインストールされていること。なければ:

```bash
composer require tmswp/acf-bridge
```

## 運用ルール(最重要)

1. フィールドグループはACF管理画面で編集する。テーマ直下の `acf-json/` に自動保存される(ACF標準のLocal JSON機能)。
2. `acf-json/*.json` は必ずgitにコミットする。
3. 他環境でJSONが更新されたら、ACF管理画面の「同期」タブで取り込む(ACF標準のsync)。
4. JSONを変更したら必ずアクセサを再生成する(下記)。生成物の差分もコミットする。

## 手順(フィールド追加〜利用)

1. ACF管理画面でフィールドグループを作成/編集する(例: グループ「製品情報」、位置=投稿タイプ`product`、
   フィールド: `price`(数値)・`maker`(テキスト)・`gallery`(リピーター: 画像`image`+キャプション`caption`))。
2. `acf-json/` にJSONが保存されたことを確認し、アクセサを生成する:

   ```bash
   wp tmswp acf sync
   ```

   `app/Acf/ProductFields.php` のような `@generated` クラスが生成される(グループ単位)。
3. 生成クラス経由で読み書きする。
4. `composer qa` を通し、`acf-json/` と `app/Acf/` を一緒にコミットする。

## 生成されるクラスと使い方

生成物の例(`app/Acf/ProductFields.php`。**手で編集しない**):

```php
<?php

/**
 * @generated by tmswp/acf-bridge — DO NOT EDIT.
 * source: acf-json/group_66f0a1b2c3d4e.json
 */

declare(strict_types=1);

namespace App\Acf;

use WP_Post;

final class ProductFields
{
    /** ACF: price(number, return_format準拠) */
    public static function price(WP_Post|int $post): int { /* get_field委譲 */ }

    /** ACF: maker(text) */
    public static function maker(WP_Post|int $post): string { /* get_field委譲 */ }

    /**
     * ACF: gallery(repeater)
     * @return list<array{image: array<string, mixed>, caption: string}>
     */
    public static function gallery(WP_Post|int $post): array { /* get_field委譲 */ }
}
```

テンプレートでの利用:

```php
<?php $price = \App\Acf\ProductFields::price( get_the_ID() ); ?>
<p>価格: <?php echo esc_html( number_format( $price ) ); ?>円</p>

<?php foreach ( \App\Acf\ProductFields::gallery( get_the_ID() ) as $row ) : ?>
    <figure>
        <img src="<?php echo esc_url( (string) $row['image']['url'] ); ?>" alt="">
        <figcaption><?php echo esc_html( $row['caption'] ); ?></figcaption>
    </figure>
<?php endforeach; ?>
```

## エンティティのMetaBagをACFに委譲する(任意)

`ProductPost::meta($post)` 系のアクセスもACF経由(get_field/update_field)にしたい場合のみ、
エンティティクラスに明示的にオプトインする:

```php
use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\Core\Attribute\UseMetaStorage;

#[AsPostType]
#[UseMetaStorage(AcfMetaStorage::class)]
final class ProductPost extends PostType
{
    // ...
}
```

通常は生成アクセサ(ProductFields)だけで足りる。迷ったらオプトインしない。

## してはいけないこと

- アプリコードでの `get_field( 'price' )` / `the_field()` の直書き(タイポ検出不能・型不明になる。必ず生成アクセサ経由)。
- `app/Acf/` の生成クラスを手で編集する(再生成で消える。ロジックは利用側クラスに書く)。
- ACF管理下のフィールドキーを `#[MetaSchema]` でも宣言する(同一キーの二重宣言は起動時エラー)。
- acf-jsonをコミットせずDBだけでフィールドを管理する(環境間で定義が分岐する)。
- ACFの関数をフォームやModelのコードに混ぜる(ACFはメタ管理のみに使う)。

## 確認方法

1. `wp tmswp acf check` が「ドリフトなし」を報告すること(acf-jsonと生成クラスの一致検査。CIでも実行される)。
2. `composer qa` が通ること。
3. 管理画面でフィールドに値を入れ、テンプレートで生成アクセサ経由の表示が確認できること。

## 発展

- リピーター/グループのarray shapeは生成クラスのPHPDocに出力される。PHPStanがキータイポを検出する。
- ACFオプションページの値も同様に生成される(グループ位置=オプションページの場合、引数なしアクセサになる)。
````

> 整合確認(09-acf-bridge.md): WP-CLIコマンド名 `wp tmswp acf sync` / `wp tmswp acf check`、生成先 `app/Acf/`、`AcfMetaStorage`、`#[UseMetaStorage]`(DR-06/DR-18)。

### 7.5 rules/form.md

````markdown
# フォームを実装する(入力→確認→完了の3画面+メール送信)

フォーム1つにつき定義クラス1つを `app/Form/` に置き、画面はWordPress標準の固定ページテンプレートで作る。
CSRF対策(nonce)・確認画面の状態保持(署名付きhidden)・バリデーションはFWが自動で行う。
セッションは使わない。CF7等のフォームプラグインも使わない。
(名前空間はスターター既定の `App\`。変更している場合は読み替える)

## 全体像

| 役割 | ファイル | 内容 |
|---|---|---|
| フォーム定義 | `app/Form/ContactForm.php` | フィールド・バリデーションルール・確認画面の有無 |
| 入力+確認画面 | `page-contact.php` | 固定ページ(スラッグ `contact`)のWP標準テンプレート |
| 完了画面 | `page-thanks.php` | 固定ページ(スラッグ `thanks`)。送信後にリダイレクトされる静的ページ |

事前準備: WP管理画面で固定ページ「お問い合わせ」(スラッグ `contact`)と「送信完了」(スラッグ `thanks`)を作成しておく。

## 1. フォーム定義クラス

`app/Form/ContactForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Regex;
use TMSWP\Core\Form\Rule\Required;

final class ContactForm extends Form
{
    protected static function definition(): FormDefinition
    {
        return FormDefinition::create('contact')
            ->add(
                Field::text('your_name', 'お名前', [new Required(), new MaxLength(50)]),
                Field::text('email', 'メールアドレス', [new Required(), new Email(), new MaxLength(191)]),
                Field::text('tel', '電話番号', [new Regex('/\A0[0-9\-]{9,12}\z/', message: '電話番号の形式で入力してください')]),
                Field::radios('subject', 'お問い合わせ種別', [
                    'estimate' => 'お見積り',
                    'support'  => 'サポート',
                    'other'    => 'その他',
                ], [new Required()]),
                Field::textarea('message', 'お問い合わせ内容', [new Required(), new MaxLength(2000)]),
                Field::checkbox('agree', '個人情報の取り扱いに同意する', rules: [new Required(message: '同意にチェックしてください')]),
            )
            ->confirm();   // 確認画面あり。確認なしの即送信フォームでは付けない
    }
}
```

ポイント:

- フォーム定義クラスはAttribute不要(自動探索対象ではない。テンプレートから明示的に生成する)。
- フィールドは `Field::text(名前, ラベル, ルール配列)` 形式のファクトリで生成し `->add(...)` でまとめて追加する。
- メールアドレス欄は `Field::text` + `Email` ルールで表現する(`Field::email` というファクトリは存在しない。
  HTMLの `type="email"` はテンプレート側の `input(['type' => 'email'])` で付与する)。
- `Required` は空文字・未チェックを不可にする。`Required` 以外のルールは **値が空のとき検証をスキップ** する
  (任意項目に `Regex` 等を付けてよい)。
- エラーメッセージは全ルールに日本語デフォルトが内蔵されている。変えたいときだけ `message:` を渡す。

## 2. 入力+確認画面テンプレート

`page-contact.php`(WP標準のページテンプレート。固定ページ `contact` に適用される):

```php
<?php

declare(strict_types=1);

use App\Form\ContactForm;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Log\Log;

$form   = ContactForm::create();
$result = $form->handle();   // バインド+CSRF検証+バリデーション+状態遷移をすべて行う

// --- 完了: メール送信して完了ページへリダイレクト(出力前=get_header()前に行うこと) ---
if ( FormStep::Complete === $result->step ) {
    $values  = $result->values;
    $subject = $form->field( 'subject' )->display();   // 選択肢のラベル(例: 'お見積り'。esc_html済み文字列)

    $body = strtr(
        "以下の内容でお問い合わせを受け付けました。\n\n"
        . "お名前: {your_name}\nメールアドレス: {email}\n電話番号: {tel}\n"
        . "種別: {subject}\n内容:\n{message}\n",
        [
            '{your_name}' => (string) $values['your_name'],
            '{email}'     => (string) $values['email'],
            '{tel}'       => (string) $values['tel'],
            '{subject}'   => $subject,
            '{message}'   => (string) $values['message'],
        ]
    );

    $sentAdmin = wp_mail(
        (string) get_option( 'admin_email' ),
        '【お問い合わせ】' . $values['your_name'] . ' 様より',
        $body,
        [ 'Reply-To: ' . $values['email'] ]
    );
    $sentUser = wp_mail(
        (string) $values['email'],
        'お問い合わせありがとうございます',
        $body
    );

    if ( ! $sentAdmin || ! $sentUser ) {
        Log::channel( 'mail' )->error( 'contact mail failed', [
            'admin' => $sentAdmin,
            'user'  => $sentUser,
            'email' => $values['email'],
        ] );
    } else {
        Log::channel( 'mail' )->info( 'contact mail sent', [ 'email' => $values['email'] ] );
    }

    wp_safe_redirect( (string) home_url( '/thanks/' ) );
    exit;   // リダイレクト直後のexitはWP標準作法(これ以外でexitを書かない)
}

get_header();
?>

<main class="l-contact">
<?php if ( FormStep::Confirm === $result->step ) : ?>

    <h1>入力内容の確認</h1>
    <?php echo $form->open(); // <form>開始タグ+CSRF nonce+署名付き確認トークン(改ざん検知付き)を自動出力 ?>
        <dl>
            <dt>お名前</dt><dd><?php echo $form->field( 'your_name' )->display(); ?></dd>
            <dt>メールアドレス</dt><dd><?php echo $form->field( 'email' )->display(); ?></dd>
            <dt>電話番号</dt><dd><?php echo $form->field( 'tel' )->display(); ?></dd>
            <dt>お問い合わせ種別</dt><dd><?php echo $form->field( 'subject' )->display(); // 選択肢のラベルが表示される ?></dd>
            <dt>お問い合わせ内容</dt><dd><?php echo $form->field( 'message' )->display(); // textarea値はnl2br適用済み ?></dd>
        </dl>
        <button type="submit" name="_step" value="back">入力画面に戻る</button>
        <button type="submit" name="_step" value="submit">この内容で送信する</button>
    <?php echo $form->close(); ?>

<?php else : // FormStep::Input(初回表示・エラー時・確認画面から戻った時) ?>

    <h1>お問い合わせ</h1>
    <?php if ( $result->hasErrors() ) : ?>
        <p class="c-form-error-summary">入力内容にエラーがあります。各項目をご確認ください。</p>
    <?php endif; ?>
    <?php echo $form->open(); // <form>開始タグ+CSRF nonce等の必須hiddenを自動出力 ?>

        <div class="c-form-row">
            <label for="your_name">お名前 <span>必須</span></label>
            <?php echo $form->field( 'your_name' )->input( [ 'class' => 'c-input', 'id' => 'your_name' ] ); ?>
            <?php echo $form->field( 'your_name' )->errors(); ?>
        </div>

        <div class="c-form-row">
            <label for="email">メールアドレス <span>必須</span></label>
            <?php echo $form->field( 'email' )->input( [ 'class' => 'c-input', 'id' => 'email' ] ); ?>
            <?php echo $form->field( 'email' )->errors(); ?>
        </div>

        <div class="c-form-row">
            <label for="tel">電話番号</label>
            <?php echo $form->field( 'tel' )->input( [ 'class' => 'c-input', 'id' => 'tel' ] ); ?>
            <?php echo $form->field( 'tel' )->errors(); ?>
        </div>

        <fieldset class="c-form-row">
            <legend>お問い合わせ種別 <span>必須</span></legend>
            <?php echo $form->field( 'subject' )->radios( [ 'class' => 'c-radio' ] ); ?>
            <?php echo $form->field( 'subject' )->errors(); ?>
        </fieldset>

        <div class="c-form-row">
            <label for="message">お問い合わせ内容 <span>必須</span></label>
            <?php echo $form->field( 'message' )->textarea( [ 'class' => 'c-textarea', 'id' => 'message', 'rows' => '8' ] ); ?>
            <?php echo $form->field( 'message' )->errors(); ?>
        </div>

        <div class="c-form-row">
            <?php echo $form->field( 'agree' )->checkbox( [ 'id' => 'agree' ] ); ?>
            <label for="agree">個人情報の取り扱いに同意する</label>
            <?php echo $form->field( 'agree' )->errors(); ?>
        </div>

        <button type="submit" name="_step" value="confirm">確認画面へ</button>
    <?php echo $form->close(); ?>

<?php endif; ?>
</main>

<?php get_footer(); ?>
```

仕組みの要点:

- `handle()` がリクエストメソッド・`_step`(confirm/back/submit)・CSRF・署名を見て
  `FormStep::Input | Confirm | Complete` を決める。テンプレートは `$result->step` で表示を分岐するだけ。
- `open()` は `<form>` 開始タグと必須hidden(`_form_id` / `_nonce`、確認画面では `_confirm_token` も)を出力する。
  `<form>` タグを手書きせず、必ず `open()`/`close()` で囲む。
- 確認画面の入力値は `open()` が埋め込むHMAC署名付き確認トークン(hidden)で引き回される。
  改ざん・期限切れのPOSTは署名検証で弾かれInputに差し戻される。セッション・トランジェントは不要。
- `field(...)->input()/textarea()/radios()/checkbox()/errors()` は **エスケープ済みHTML文字列** を返す。
  そのままechoする。値の再表示・checked/selected復元も自動。
- HTML構造(div/label/class)は完全にテンプレート側の自由。FWはフォーム部品の断片しか生成しない。

## 3. 完了画面テンプレート

`page-thanks.php`(静的なWP標準テンプレート。FWのコードは不要):

```php
<?php

declare(strict_types=1);

get_header();
?>
<main>
    <h1>送信が完了しました</h1>
    <p>お問い合わせありがとうございます。確認のメールをお送りしましたのでご確認ください。</p>
</main>
<?php get_footer(); ?>
```

## 確認画面なしの即送信フォームにする場合

`definition()` の `->confirm()` を外すだけ。`handle()` はInput→Complete を直接遷移する
(テンプレートのConfirm分岐は不要になる)。

## してはいけないこと

- CSRF対策の自作(`wp_nonce_field()` 手書き等)。`open()` が自動で行う。二重に入れない。
- `<form>` 開始タグの手書き(必須hiddenが欠落して `handle()` が遷移を認識できなくなる。必ず `open()`/`close()`)。
- `$_POST` の直接参照。値は必ず `$result->values` か `$form->field()->value()` 経由
  (バリデーション済み・正規化済みの値だけを使う)。
- メール送信・リダイレクトを `get_header()` より後に書く(ヘッダ送出後でリダイレクト不能になる)。
- 確認画面の値表示に `value()`(生値)を直接echoする(確認画面は `display()` を使う。`display()` は
  エスケープ済みHTML文字列を返すのでそのままechoし、`esc_html()` を重ねて二重エスケープしない)。
- セッション(`session_start`)・独自Cookie・トランジェントでの状態保持。
- 送信完了の表示をリダイレクトなしで行う(リロードで二重送信される。必ずPRG: redirect→thanksページ)。
- CF7等のフォームプラグイン併用。
- ファイル添付フィールドの独自実装($_FILESを直接触らない。ファイル付きフォームはFWの
  `Field::file()` + 一時保管トークン機構を使う。実装前に設計書 05-form を参照)。

## 複合フィールド(CompositeField)を自作する

年月日を別々の `<select>` で入力させるなど、複数の入力部品を1つの値として扱う場合は `CompositeField` を継承したフィールドを app 側で定義する。`parts()` は入力部品、`compose()` は送信値から保存用の正準値、`decompose()` は正準値から入力部品の値を返す。部品がすべて空なら未入力、部品の一部だけが空なら `incompleteMessage()`、部品が揃った後の整合性は `validateParts()` で検証する。

`app/Form/BirthDateField.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Rule\RuleInterface;

final class BirthDateField extends CompositeField
{
    /** @param list<RuleInterface> $rules */
    public function __construct(
        string $name,
        string $label,
        array $rules = [],
        private readonly int $minYear = 1940,
        private readonly ?int $maxYear = null,
        private readonly bool $withDay = true,
    ) {
        parent::__construct($name, $label, $rules);
    }

    public function parts(): array
    {
        $parts = [
            'year' => Field::select($this->name . '_year', '年', $this->yearChoices()),
            'month' => Field::select($this->name . '_month', '月', $this->rangeChoices(1, 12)),
        ];

        if ($this->withDay) {
            $parts['day'] = Field::select($this->name . '_day', '日', $this->rangeChoices(1, 31));
        }

        return $parts;
    }

    public function compose(array $partValues): string
    {
        $year = (int) ($partValues['year'] ?? 0);
        $month = (int) ($partValues['month'] ?? 0);
        $day = $this->withDay ? (int) ($partValues['day'] ?? 0) : 1;

        if ($year < 1 || $month < 1 || $day < 1) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public function decompose(string $value): array
    {
        if ($value === '' || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $matches) !== 1) {
            return $this->emptyParts();
        }

        $parts = [
            'year' => (string) (int) $matches[1],
            'month' => (string) (int) $matches[2],
        ];

        if ($this->withDay) {
            $parts['day'] = (string) (int) $matches[3];
        }

        return $parts;
    }

    public function display(string $value): string
    {
        $parts = $this->decompose($value);

        if ($parts['year'] === '' || $parts['month'] === '' || ($this->withDay && $parts['day'] === '')) {
            return '';
        }

        if ($this->withDay) {
            return "{$parts['year']}年{$parts['month']}月{$parts['day']}日";
        }

        return "{$parts['year']}年{$parts['month']}月";
    }

    public function validateParts(array $partValues): bool
    {
        $year = (int) ($partValues['year'] ?? 0);
        $month = (int) ($partValues['month'] ?? 0);
        $day = $this->withDay ? (int) ($partValues['day'] ?? 0) : 1;

        return checkdate($month, $day, $year);
    }

    public function invalidPartsMessage(): string
    {
        return $this->translate('{label}が正しい日付ではありません。');
    }

    /** @return array<string, string> */
    private function emptyParts(): array
    {
        $parts = ['year' => '', 'month' => ''];

        if ($this->withDay) {
            $parts['day'] = '';
        }

        return $parts;
    }

    /** @return array<array-key, string> */
    private function yearChoices(): array
    {
        $choices = ['' => '----'];
        $maxYear = $this->maxYear ?? (int) date('Y');

        for ($year = $maxYear; $year >= $this->minYear; --$year) {
            $choices[(string) $year] = (string) $year;
        }

        return $choices;
    }

    /** @return array<array-key, string> */
    private function rangeChoices(int $min, int $max): array
    {
        $choices = ['' => '--'];

        for ($value = $min; $value <= $max; ++$value) {
            $choices[(string) $value] = (string) $value;
        }

        return $choices;
    }
}
```

## 確認方法

1. `composer qa` が通ること。
2. ブラウザで一連のフローを確認する:
   - 空送信→入力画面にエラー表示が出ること(画面遷移しない)
   - 正常入力→確認画面に全項目が表示されること
   - 「戻る」→入力値が保持されたまま入力画面に戻ること
   - 「送信」→ /thanks/ にリダイレクトされ、管理者宛・自動返信メールが届くこと
   - 確認画面でブラウザバック→再送信してもCSRF/署名エラーで二重送信にならないこと
3. メール送受信のログが `mail` チャンネルに記録されること。

## 発展

- ステップ式の複雑なフォーム(会員登録3ステップ等)・ファイルアップロード・クロスフィールド検証
  (パスワード再入力等)はFWのForm層がサポートする。設計書 05-form の該当節を参照。
- 管理画面にフォーム用の設定値(送信先アドレス等)を持たせたい場合 → rules/options.md
- 受信内容をDBに保存したい場合 → rules/model.md
````

> 整合確認(05-form.md): `FormDefinition::create(string $id)/add(Field|CompositeField ...$fields)/confirm(bool $enabled = true)`、`Field::text/textarea/radios/checkbox/file`(named-argファクトリ `($name, $label, $rules, ...)`。emailはFieldTypeでなく `Rule\Email` で表現)、`Rule\{Required, Email, MaxLength, Regex}`(`message:` named argument)、`Form::create/handle/field/open/close`、`Form::FIELD_STEP = '_step'`(値 confirm/back/submit)、`FormResult::step/values`(publicプロパティ)/`hasErrors()`、`FieldView::input/textarea/radios/checkbox/errors/value/display`。05-form.mdの確定APIと突合済み。

### 7.5A rules/actions.md

````markdown
# テンプレートの前処理を分離する(actions/ ファイル規約)

テンプレートに書きがちなデータ取得・整形・フォーム送信処理を、**ページテンプレートと同名の `actions/` ファイル**へ分離する。
`actions/` はデータを準備して `return` するだけのPHPファイルで、テンプレートはその結果を `tms_var()` で受け取り表示に専念する。
WordPress標準のテンプレート階層は一切変わらない(出し分けは従来どおり `page-{slug}.php` 等とConditional Tagsで行う)。
(名前空間はスターター既定の `App\`。変更している場合は読み替える)

## いつ使うか

- あるページテンプレート(`front-page.php`・`single-product.php`・`page-contact.php`・`404.php` 等)の表示前に、
  そのテンプレートでだけ必要なデータ取得・整形・フォーム送信処理が要るとき。
- 単純な表示だけのテンプレートに `actions/` ファイルは要らない(完全オプトイン。置かなければ完全に素通りする)。

## 仕組み(3行で)

1. テーマ直下の `actions/` に、ページテンプレートと **同じ相対パス・同じファイル名** でPHPを置く
   (`page-contact.php` → `actions/page-contact.php`、`single/product.php` → `actions/single/product.php`)。
2. WordPressがテンプレートを確定した直後、本描画の直前に、対応する `actions/` ファイルが1回だけ実行される。
3. ファイルが `return` した連想配列がページテンプレートで `tms_var('キー')` から読める。

## 手順

1. テーマ直下に `actions/{ページテンプレートと同名}.php` を作る。
2. 役割は **データ準備のみ**。出力(echo/print)・フック登録・`$wp_query->set_404()` の直呼びはしない。
3. テンプレートで使う値を連想配列リテラルにして `return` する(`return ['posts' => $posts, 'form' => $form];` 等。`compact()` は使わない)。
4. ファイル冒頭のdocblockに `@var \WP_Query $wp_query` を書く(可視変数は `$wp_query` のみ)。
5. ページテンプレート側で `tms_var('キー')` から受け取り、`@var` 注釈を添えて型を明示する。
6. `composer qa`(= actions/ 規約検査 `composer tms:check` を含む)を通す。

## 完全な実装例

### 例1: 関連記事を用意して single テンプレートに渡す

`actions/single-product.php`(CPT product の個別ページ `single-product.php` の前処理):

```php
<?php

declare(strict_types=1);

/** @var \WP_Query $wp_query 可視変数はこれのみ。$post 等は使わない */

use App\Post\ProductPost;

$current = $wp_query->get_queried_object();

if ( ! $current instanceof \WP_Post ) {
    return \TMSWP\Core\View\ActionRouter::notFound();   // 対象が無ければ404を表明する
}

$related = ProductPost::query()
    ->notIn( [ $current->ID ] )
    ->limit( 4 )
    ->get();

return [ 'related' => $related ];
```

ページテンプレート `single-product.php`:

```php
<?php

declare(strict_types=1);

/** @var list<\WP_Post> $related */
$related = tms_var( 'related', [] );

get_header();
?>
<main>
    <?php the_post(); ?>
    <h1><?php echo esc_html( get_the_title() ); ?></h1>
    <?php the_content(); ?>

    <?php if ( $related !== [] ) : ?>
        <aside class="related">
            <h2>関連製品</h2>
            <?php foreach ( $related as $item ) : ?>
                <a href="<?php echo esc_url( \App\Post\ProductPost::permalink( $item ) ); ?>">
                    <?php echo esc_html( get_the_title( $item ) ); ?>
                </a>
            <?php endforeach; ?>
        </aside>
    <?php endif; ?>
</main>
<?php get_footer(); ?>
```

### 例2: フォーム駆動の3画面(入力→確認→完了)

なお、本レシピは完了画面を固定ページ `contact` の子ページ(URL `/contact/thanks/`)に置く前提で書かれている。
rules/form.md の自己完結版レシピ(actions/ を使わない形)は完了画面をスラッグ `thanks`(URL `/thanks/`)とする前提であり、
両者は完了ページURLの前提が異なる独立レシピである。リダイレクト先は実際に作成した完了ページのURLに合わせること。

`actions/page-contact.php`(固定ページ `contact` のテンプレート `page-contact.php` の前処理。フォーム定義は rules/form.md 参照):

```php
<?php

declare(strict_types=1);

/** @var \WP_Query $wp_query */

use App\Form\ContactForm;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Log\Log;

$form   = ContactForm::create();
$result = $form->handle();   // バインド+CSRF検証+バリデーション+状態遷移

// 完了時はメール送信して完了ページへリダイレクト。
// actions/ は template_include(出力開始前)で実行されるため wp_safe_redirect+exit が安全に使える(PRG)。
if ( FormStep::Complete === $result->step ) {
    $values = $result->values;
    $sent   = wp_mail(
        (string) get_option( 'admin_email' ),
        '【お問い合わせ】' . $values['your_name'] . ' 様より',
        (string) $values['message'],
        [ 'Reply-To: ' . $values['email'] ]
    );
    Log::channel( 'mail' )->info( 'contact mail', [ 'sent' => $sent, 'email' => $values['email'] ] );

    wp_safe_redirect( home_url( '/contact/thanks/' ) );
    exit;   // リダイレクト直後のexitはWP標準作法(これ以外でexitを書かない)
}

return [ 'form' => $form, 'result' => $result ];
```

ページテンプレート `page-contact.php`:

```php
<?php
/* Template Name: お問い合わせ */

declare(strict_types=1);

use TMSWP\Core\Form\FormStep;

/** @var \App\Form\ContactForm $form */
$form = tms_var( 'form' );
/** @var \TMSWP\Core\Form\FormResult $result */
$result = tms_var( 'result' );

get_header();
?>
<main>
<?php if ( FormStep::Confirm === $result->step ) : ?>
    <?php echo $form->open(); // <form>開始タグ+CSRF nonce+署名付き確認トークンを自動出力 ?>
        <dl>
            <dt>お名前</dt><dd><?php echo $form->field( 'your_name' )->display(); ?></dd>
            <dt>メール</dt><dd><?php echo $form->field( 'email' )->display(); ?></dd>
        </dl>
        <button type="submit" name="_step" value="back">入力に戻る</button>
        <button type="submit" name="_step" value="submit">送信する</button>
    <?php echo $form->close(); ?>
<?php else : ?>
    <?php echo $form->open(); ?>
        <?php echo $form->field( 'your_name' )->input( [ 'class' => 'c-input' ] ); ?>
        <?php echo $form->field( 'your_name' )->errors(); ?>
        <?php echo $form->field( 'email' )->input( [ 'class' => 'c-input' ] ); ?>
        <?php echo $form->field( 'email' )->errors(); ?>
        <?php echo $form->field( 'message' )->textarea( [ 'class' => 'c-textarea' ] ); ?>
        <?php echo $form->field( 'message' )->errors(); ?>
        <button type="submit" name="_step" value="confirm">確認画面へ</button>
    <?php echo $form->close(); ?>
<?php endif; ?>
</main>
<?php get_footer(); ?>
```

## 戻り値の契約

`actions/` ファイルの `return` 値で、FWの挙動が決まる。

| `return` した値 | 挙動 |
|---|---|
| 連想配列 | 各キーが `tms_var()` / `View::get()` で読める(マージ。既存変数は上書きされない) |
| `\TMSWP\Core\View\ActionRouter::notFound()` | 404を強制(ステータス404 + `404.php` 描画) |
| `null`(= `return` 省略) | 何もしない(no-op) |

## してはいけないこと

- **出力(echo/print)をする**。`actions/` はデータ準備専用。出力はテンプレート/パーツで行う(FW同梱のPHPStanルールが検出する)。

  ```php
  // NG: actions/ で出力する
  echo '<h1>' . esc_html( get_the_title() ) . '</h1>';
  ```

- **`compact()` で戻り値を作る**。変数名⇔キーの対応が文字列契約になりgrep・静的解析・リネームで追跡できなくなる
  (テンプレート側の `extract()` 禁止と同じ理由)。必ず明示的な配列リテラルで返す。

  ```php
  // NG: compact() で返す
  return compact( 'form', 'result' );
  // OK: 配列リテラルで返す('form' => と tms_var('form') がgrepで対応する)
  return [ 'form' => $form, 'result' => $result ];
  ```

- **`$wp_query->set_404()` を直接呼ぶ**。404は必ず `return ActionRouter::notFound()` で表明する。

  ```php
  // NG: 直呼び(FW同梱のPHPStanルールが検出する)
  $wp_query->set_404();
  // OK
  return \TMSWP\Core\View\ActionRouter::notFound();
  ```

- **`actions/common.php`(全テンプレート共通の前処理ファイル)を作る**。actions/ はページテンプレートと同名のファイルのみ。
  全テンプレート共通の処理は `app/Hook/`(rules/hooks.md)へ、パーツで使うデータはパーツ内でモデルのアクセサを直接読む。

- **`actions/` でフックを登録する**(`add_action`/`add_filter`)。フック登録は `app/Hook/`(rules/hooks.md)。

- **パーツ(`get_template_part` の読込先)から `tms_var()` / `View::get()` を読む**。データの追跡経路が2本になり追えなくなる。
  パーツへは `get_template_part( $slug, null, $args )` の第3引数で渡し、パーツ側は `wp_parse_args()` で受ける(rules/view.md)。

  ```php
  // NG: パーツ内で tms_var を読む
  $form = tms_var( 'form' );
  // OK: テンプレートから引数で渡す
  get_template_part( 'parts/contact-form', null, [ 'form' => $form ] );
  ```

- **`template_include` / `template_redirect` を自分で `add_filter` してテンプレートを乗っ取る**
  (出し分けはWP標準のテンプレート階層で行う。actions/ はテンプレート解決を一切変更しない)。

- **`return` の直後以外で `exit` / `die` を書く**(フォーム送信後の `wp_safe_redirect()` + `exit` のみ許可)。

- **ページテンプレートと同名でない孤児 `actions/` ファイルを置く**(対応テンプレートが無いと実行されない。bin/tms-checkが検出する)。

## 確認方法

1. `composer qa` が通ること(actions/ 規約検査を含む: 出力・`set_404()` 直呼びはFW同梱のPHPStanルール
   (`phpstan-actions.neon` 経由)が、孤児actionsは `bin/tms-check` が検査する。正準仕様は10-quality.md §3A)。
2. 規約検査は単独でも実行できる: `composer tms:check`(= `phpstan analyse -c phpstan-actions.neon` +
   `php vendor/tmswp/core/bin/tms-check actions`)。
3. 対象テンプレートのページを表示し、`actions/` で用意した値(`tms_var()`)が反映されること。
4. フォーム駆動の場合: 入力→確認→送信→ `/contact/thanks/` リダイレクトの一連が動くこと。
5. 404を表明したテンプレートでHTTPステータスが404になり、`404.php` が描画されること。
6. `actions/` を追加したのに反映されない場合: ファイル名がページテンプレートと一致しているか(`WP_DEBUG` 時は
   使用された `actions/` ファイルパスがHTMLコメントで `wp_footer` に出力される)を確認する。

## 発展

- フォームの定義・バリデーション・確認画面の詳細 → rules/form.md
- テンプレート→パーツへのデータ受け渡し → rules/view.md
- リクエスト種別を問わない処理(管理画面・REST等を含む) → rules/hooks.md
- actions/ 機構の設計詳細(ActionRouter / View / NotFound / tms_var)→ 07-support.md
````

> 整合確認(07-view-support.md): `TMSWP\Core\View\ActionRouter`(`template_include` priority 100 で dispatch を登録。`actions/{相対パス}.php` を子→親テーマの順に解決し static クロージャで include。可視変数は `$wp_query` のみ)、戻り値契約(`array`→`View::set` / `ActionRouter::notFound()`→404 / `null`→no-op)、`TMSWP\Core\View\View`(`set/get/has`)、`TMSWP\Core\View\NotFound`、グローバルヘルパ `tms_var(string $key, mixed $default = null): mixed`、actions/ 規約検査(出力・`set_404()`直呼び=FW同梱PHPStanルール `NoOutputRule`/`NoSet404Rule`(`phpstan-actions.neon` 経由)、孤児actions=コア同梱・WP非依存スクリプト `bin/tms-check`。仕様は10-quality.md §3A)。戻り値の正準表記は明示的配列リテラル(`compact()` 不使用)。`PostQuery::notIn/limit/get`・`PostType::permalink` は 03-entities.md と、フォーム部分のAPI(`Form::open()/close()` / `Form::FIELD_STEP = '_step'` / `FieldView::display()`)は 05-form.md と突合済み。

### 7.6 rules/model.md

````markdown
# 独自DBテーブルを作って読み書きする(Model)

WPの投稿・メタで表現しにくいデータ(申込み履歴・キュー・集計等)は、独自テーブルをModelクラスとして定義する。
スキーマはクラス内で宣言し、マイグレーション(dbDelta)はFWが行う。SQLを手書きしない。
(名前空間はスターター既定の `App\`。変更している場合は読み替える)

## 手順

1. `app/Model/<名前>Model.php` を作る。クラス名は英語単数形+`Model`(例: `InquiryModel`)。
2. `#[AsModel]` を付けて `TMSWP\Core\Model\Model` を継承し、`table()` でスキーマを宣言する。
3. マイグレーションを実行する:
   - プラグイン: 有効化時に自動実行される(スキーマ変更時はバージョン比較で再実行)。手動は `wp tmswp migrate`。
   - テーマ: テーマ有効化時に自動実行される。開発中のスキーマ変更後は `wp tmswp migrate` を実行する。
   - 失敗時のエラー方針は契機で異なる: 管理画面(admin_init)契機では致死せず管理画面通知(notice-error)で
     報告され、原因解消後の次回契機で自動リトライされる。WP-CLI・有効化フック契機では例外がそのまま伝播する
     (詳細は設計書 06-model)。
4. 読み書きはクエリビルダ(`query()`)経由で行う。`composer qa` を通す。

## 完全な実装例

`app/Model/InquiryModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class InquiryModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('inquiries')   // 実テーブル名は {$wpdb->prefix}inquiries
            ->id()                                    // BIGINT UNSIGNED AUTO_INCREMENT 主キー 'id'
            ->string('email', 191)
            ->string('subject', 100)
            ->text('body')
            ->datetime('created_at')
            ->index('email');
    }
}
```

読み書き(クエリビルダ。すべて内部で `$wpdb->prepare` を通る):

```php
use App\Model\InquiryModel;

// INSERT(挿入したID(int)が返る)
$id = InquiryModel::insert( [
    'email'      => 'taro@example.com',
    'subject'    => 'お見積り',
    'body'       => '内容...',
    'created_at' => current_time( 'mysql' ),
] );

// SELECT(結果は連想配列のリスト)
$rows = InquiryModel::query()
    ->where( 'email', 'taro@example.com' )
    ->orderByDesc( 'id' )
    ->limit( 10 )
    ->get();          // list<array<string, mixed>>

$row   = InquiryModel::find( $id );                                    // ?array<string, mixed>
$one   = InquiryModel::query()->where( 'subject', 'お見積り' )->first(); // ?array<string, mixed>
$count = InquiryModel::query()->where( 'subject', 'お見積り' )->count(); // int

// UPDATE / DELETE(条件はwhereで指定。影響行数(int)が返る)
InquiryModel::query()->where( 'id', $id )->update( [ 'subject' => 'サポート' ] );
InquiryModel::query()->where( 'created_at', '<', '2020-01-01 00:00:00' )->delete();
```

## スキーマ変更のしかた

1. `table()` の宣言を変更する(カラム追加・インデックス追加)。
2. `wp tmswp migrate` を実行(本番ではプラグイン再有効化 or デプロイフックで同コマンドを実行)。
3. dbDeltaの制約上、**カラム名の変更(rename)と削除は自動では行われない**。renameが必要な場合は
   新カラム追加→データ移行→旧カラム放置(または手動DROP)の手順を取り、その旨をPRに明記する。

## してはいけないこと

- `$wpdb->query()` / `$wpdb->get_results()` 等の直接呼び出し、SQL文字列の手書き・連結
  (旧FWのSQLインジェクション事故の再発防止。表現力が足りない場合はFW本体への課題として報告する)。
- `CREATE TABLE` / `ALTER TABLE` の手書き、`register_activation_hook` への独自マイグレーション登録
  (FWが有効化フック+バージョン比較で自動配線する)。
- WP標準テーブル(posts/users等)をModelで定義する(標準APIを使う)。
- 主キーなしテーブルの定義(必ず `->id()` を入れる)。
- リレーションの自動解決を期待する(本FWはフルORMではない。JOINが必要な集計はクエリビルダの
  範囲で表現し、超える場合は設計を見直す)。

## 確認方法

1. `composer qa` が通ること。
2. `wp tmswp migrate` 実行後、`wp db query "DESCRIBE wp_inquiries"` でスキーマが宣言どおりであること。
3. 上記の読み書きコードがエラーなく動くこと(`Log` にクエリエラーが出ていないこと)。

## 発展

- 行を型付きオブジェクトで受けたい場合は軽量DTOハイドレーション(任意機能)が使える。設計書 06-model を参照。
- フォームの受信内容を保存する実例 → rules/form.md と組み合わせる。
````

> 整合確認(06-model.md): `TableDefinition::create/id/string/text/datetime/index`、`Model::insert(array): int` / `Model::find(int): ?array`、`QueryBuilder::where(列, [演算子,] 値)/orderByDesc/limit/get/first/count/update/delete`、WP-CLI `wp tmswp migrate`、テーマでのマイグレーション契機(after_switch_theme+バージョン比較)。

### 7.7 rules/options.md

````markdown
# サイト設定値(オプション)を定義して読み書きする

サイト共通の設定値(連絡先メールアドレス・外部APIキー・表示件数等)は、オプショングループ
(backed enum)のケースとして宣言する。`get_option()`/`update_option()` を直接呼ばない。
(名前空間はスターター既定の `App\`。変更している場合は読み替える)

## 手順

1. `app/Option/<名前>Option.php` を作る(例: `SiteOption`)。
2. `#[AsOptionGroup(prefix: '...')]` を付けた `string` のbacked enumとして定義し、
   `TMSWP\Core\Option\OptionAccess` traitをuseする。キーは `#[OptionSchema]` 付きのenumケースで宣言する
   (ケース値=キー文字列)。実際のoption名は `prefix . ケース値` になる
   (例: prefix `'site_'` + ケース値 `'contact_email'` → `site_contact_email`)。
3. 読み書きはケース自身の型付きアクセサ(`SiteOption::ContactEmail->string()` / `->set()` 等)経由。
   頻出値は明示アクセサメソッドを書く(必須規約)。

## 完全な実装例

`app/Option/SiteOption.php`:

```php
<?php

declare(strict_types=1);

namespace App\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;

#[AsOptionGroup(prefix: 'site_')]   // 実際のoption名 = prefix + ケース値(例: site_contact_email)
enum SiteOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: '')]
    case ContactEmail = 'contact_email';

    #[OptionSchema(type: MetaValueType::Integer, default: 10)]
    case NewsPerPage = 'news_per_page';

    #[OptionSchema(type: MetaValueType::Boolean, default: false, autoload: false)]
    case Maintenance = 'maintenance';

    /** お問い合わせ通知の宛先。未設定時はWPの管理者メールにフォールバック。 */
    public static function contactEmail(): string
    {
        $email = self::ContactEmail->string();

        return '' !== $email ? $email : (string) get_option('admin_email');
    }
}
```

読み書き:

```php
use App\Option\SiteOption;

$to      = SiteOption::contactEmail();        // 明示アクセサ(推奨)
$perPage = SiteOption::NewsPerPage->int();    // 型付きアクセサ(OptionAccess trait)
SiteOption::Maintenance->set( true );
SiteOption::Maintenance->delete();
```

ポイント:

- キー=enumケース自身なので、キー文字列の二重書き・他グループのキー混入は型レベルで起こらない。
  ケース値(キー文字列)の重複はPHPのコンパイル時エラーになる(FW側の重複検査は存在しない。言語機能が保証する)。
- グループ内の全キーの列挙は `SiteOption::cases()`(enum標準機能)。

## OptionSchemaの主な引数

| 引数 | 型 | 既定 | 意味 |
|---|---|---|---|
| type | MetaValueType | 必須 | 値の型指定(`MetaValueType::String` / `::Integer` / `::Boolean` 等のbacked enum。一覧は設計書 04-meta-options) |
| default | mixed | 必須 | 未保存時にアクセサが返す値 |
| autoload | bool | true | WP起動時に自動ロードするか(大きい値・低頻度の値はfalse) |

## してはいけないこと

- `get_option()` / `update_option()` の直接呼び出し(default・型・プレフィックスの管理が迂回される)。
- オプション名のリテラル直書き(必ずenumケース経由)。
- 自グループで宣言していないキー(他プラグインのオプション等)をenum経由で読もうとする。enumは宣言済みケースしか
  表現できない。未宣言キーの読み取りが必要な場合は `TMSWP\Core\Option\OptionRepository::get()` を直接使う
  (`get_option()` 直書きはしない)。
- 巨大な配列を1オプションに保存する(必要なら rules/model.md の独自テーブルを検討)。
- 環境ごとに変わる秘密情報(本番APIキー等)をオプションに保存してgit管理外にする運用を崩すこと
  (秘密情報は wp-config.php の定数か環境変数に置き、オプションには保存しない)。

## 確認方法

1. `composer qa` が通ること。
2. `set()` 後に `wp option get site_contact_email` で値が確認できること。
3. 未設定キーの読み取りで `default` が返ること。

## 発展

- 管理画面に設定編集UIが必要な場合 → rules/extensions.md の tmswp/settings-page
  (Form層ベースの設定ページ拡張。オプショングループ(backed enum)と組み合わせて使う)。
````

> 整合確認(04-meta-option.md): backed enum + `OptionAccess` traitの型付きアクセサ(`->int()/->string()/->bool()/->set()/->delete()` をケース自身に対して呼び出し)、`OptionSchema(type: MetaValueType, default:, autoload:)`(enum case対象)、`#[AsOptionGroup(prefix:)]` とoption名規約 `prefix . ケース値`、未宣言キー読み取りの正式経路 `OptionRepository::get()`。04-meta-option.mdの確定APIと突合済み。なおメタ側は対称性のため定数+`#[MetaSchema]` 方式を維持しており、宣言形はmeta=定数/option=enumケースで異なる(04側に注記あり)。

### 7.8 rules/hooks.md

````markdown
# WPフック(add_action / add_filter)の置き場と書き方

テーマ/プラグイン固有のフック処理は `app/Hook/` のHookクラスにまとめ、
メソッドに `#[Action]` / `#[Filter]` 属性を付けて宣言的に登録する。
`add_action()`/`add_filter()` を自分で呼ばない。
functions.php・テンプレート・エンティティクラスにフック登録を書かない。
(名前空間はスターター既定の `App\`。変更している場合は読み替える)

## 手順

1. `app/Hook/<関心事>Hook.php` を作る。**関心事ごとに分割**する(例: `ThemeSetupHook`、`AssetsHook`、
   `MailHook`)。1つの巨大Hookクラスに全部を書かない。
2. クラスに `#[AsHook]` を付けて `TMSWP\Core\Hook\Hook` を継承する。
3. フックに載せたいpublicメソッドに `#[Action('フック名')]` / `#[Filter('フック名')]` を付ける。
   優先度は `priority:` 引数(既定 10)。**引数の個数はメソッドのシグネチャから自動で決まる**(手書きしない)。
4. `composer qa` を通す。

## 完全な実装例

`app/Hook/ThemeSetupHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

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
            (string) wp_get_theme()->get('Version')
        );
    }

    #[Filter('excerpt_more')]
    public function excerptMore(string $more): string
    {
        return '…';
    }
}
```

ルール:

- フック1つにつきメソッド1つ。フック名⇔メソッドが1対1で対応し、grep・静的解析でそのまま追跡できる。
- 属性を付けるメソッドは `public` にする(フック発火時にKernelが呼び出す)。処理本体が長い場合は
  privateメソッドやサービスクラスへ切り出してよい(属性はエントリポイントのメソッドにだけ付ける)。
- 優先度は `#[Action('save_post', priority: 20)]` のように属性引数で指定する。
- **引数の個数(acceptedArgs)はメソッドの引数の数から自動導出される**。
  `add_action('save_post', ..., 10, 2)` の「`, 2`」に相当する手書き指定は存在しない(書けない)。
  例: `#[Action('save_post')] public function onSave(int $postId, \WP_Post $post): void` → 2引数で登録される。
- Hookクラスは対応フックが発火するまでロード・インスタンス化されない(遅延)。
  フック外で処理を即時実行しない(必ず属性でフックに乗せる)。

## 動的な登録だけ register() を使う(例外手段)

フック名をループや設定値から組み立てる等、**属性では表現できない真に動的な登録**に限り、
`register()` をオーバーライドして `add_action`/`add_filter` を直接呼んでよい:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class LocaleLabelHook extends Hook
{
    public function register(): void
    {
        foreach (['ja', 'en'] as $locale) {
            add_filter("myapp_label_{$locale}", $this->normalizeLabel(...));
        }
    }

    private function normalizeLabel(string $label): string
    {
        return trim($label);
    }
}
```

静的に書けるフックを `register()` に列挙しない。第一級の書き方は常に `#[Action]`/`#[Filter]` である。

## 管理画面・wp_head クリーンアップの実装例

管理メニュー・管理バー・ダッシュボード・wp_head 出力の整理は、削除対象がテーマ毎に異なるためFWはコードを提供しない。本レシピのフッククラスとして実装する:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;
use WP_Admin_Bar;

#[AsHook]
final class AdminCleanupHook extends Hook
{
    /** 削除対象はテーマの要件に合わせて決める(以下は一例)。 */
    private const MENUS = ['edit-comments.php', 'index.php'];

    private const ADMIN_BAR_NODES = ['wp-logo', 'comments', 'updates'];

    /** [ウィジェットID, コンテキスト] の組。 */
    private const DASHBOARD_WIDGETS = [
        ['dashboard_primary', 'side'],
        ['dashboard_quick_press', 'side'],
    ];

    #[Action('admin_menu', priority: 999)]
    public function removeAdminMenus(): void
    {
        foreach (self::MENUS as $menu) {
            remove_menu_page($menu);
        }
    }

    #[Action('admin_bar_menu', priority: 999)]
    public function removeAdminBarNodes(WP_Admin_Bar $bar): void
    {
        foreach (self::ADMIN_BAR_NODES as $node) {
            $bar->remove_node($node);
        }
    }

    #[Action('wp_dashboard_setup')]
    public function removeDashboardWidgets(): void
    {
        foreach (self::DASHBOARD_WIDGETS as [$id, $context]) {
            remove_meta_box($id, 'dashboard', $context);
        }

        remove_action('welcome_panel', 'wp_welcome_panel');
    }

    #[Action('init')]
    public function cleanupHead(): void
    {
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'rest_output_link_wp_head');
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        add_filter('emoji_svg_url', '__return_false');
    }
}
```

- 効果を理解した項目だけを削除する。全部入りのコピペをしない。
- 標準ウィジェットの解除が必要な場合も同様に `widgets_init`(priority 11)で `unregister_widget()` を呼ぶメソッドを足す。

## してはいけないこと

- functions.php への add_action / add_filter 直書き(functions.phpはFWブートのみ)。
- テンプレートファイル内でのフック登録。
- エンティティ定義クラス(*Post/*Tax等)内でのフック登録(定義クラスは宣言のみ。挙動はHookへ)。
- 静的に書けるフックの `register()` への列挙(`register()` は動的登録専用の例外手段)。
- 引数個数・コールバックの手書き配線(`,10, 2` 相当の指定は属性に存在しない。メソッドシグネチャが契約)。
- `template_include` でのテンプレート差し替え(WP標準テンプレート階層の迂回は禁止)。
- 日本語スラッグ・全角スペース検索正規化・wptexturize無効化の自作
  (rules/extensions.md の tmswp/ja-toolkit にオプトインモジュールがある。先にそちらを確認する)。
  管理画面メニュー削除・wp_head掃除等のクリーンアップは本レシピの実装例に従いHookクラスで行う。
- 外部プラグイン(ACF等)のフィルタを広域に書き換えること(影響範囲をコメントで明示し最小化する)。

## 確認方法

1. `composer qa` が通ること。
2. 対象フックの効果がWP上で確認できること(例: enqueueしたCSSがページソースに出る)。
3. メソッドを追加したのに効かない場合: `#[Action]`/`#[Filter]` の付け忘れ → メソッドがpublicか →
   `wp tmswp cache clear`(フックマップは探索キャッシュに保存される)の順で確認する。

## 発展

- 優先度付き・複数引数の例: `#[Action('save_post', priority: 20)] public function onSave(int $postId, \WP_Post $post): void`
  (引数個数はシグネチャから自動導出)。
- 多数のフックを持つ大規模な関心事は、Hookクラスを複数に分けるか専用サービスクラスに処理を委譲する。
````

> 整合確認(02-kernel.md): メソッドレベル属性 `TMSWP\Core\Attribute\Action` / `TMSWP\Core\Attribute\Filter`(TARGET_METHOD。`priority:` 引数あり、acceptedArgsはメソッド引数数から自動導出で手書き不可)、`#[AsHook]` の探索仕様(Discoveryがクラス属性+メソッド属性を収集し、(class, method, hook, priority) 複合キーで決定的ソートしてComponentCacheへ保存)、フック発火までHookクラスを遅延ロードするクロージャ登録、`Hook::register(): void`(真に動的な登録のみの例外手段)の呼び出しタイミング。02-kernel.md確定APIと突合する。

### 7.9 rules/view.md

````markdown
# テンプレートパーツの読み込みとロジック分離(Template::part)

テンプレートはWordPress標準のテンプレート階層をそのまま使う(FWはビューに介入しない)。
FWが提供するのは「引数付きパーシャル読み込み+プラグイン同梱テンプレートへのフォールバック」を行う
薄いヘルパ `Template::part()` / `Template::render()` だけである。

## 基本

テーマ内のパーシャル読み込みはWP標準の `get_template_part()` でもFWの `Template::part()` でもよい。
次のどちらかに統一する:

- テーマ専用で完結するサイト → `get_template_part( 'parts/card', null, [ 'post' => $post ] )`(WP標準)
- プラグイン同梱テンプレートを併用する/出力を文字列で受けたい → `Template::part()` / `Template::render()`

```php
<?php

use TMSWP\Core\View\Template;

// パーシャルをecho(WP標準のget_template_partと同じ感覚)
Template::part( 'parts/card', [ 'post' => $post, 'show_date' => true ] );

// 文字列として受け取る(メール本文・フィルタ戻り値などに)
$html = Template::render( 'parts/card', [ 'post' => $post ] );
```

`parts/card.php`(パーシャル側。変数は `$args` 配列で受け取る。**extract()されない**):

```php
<?php

declare(strict_types=1);

/** @var array{post: \WP_Post, show_date?: bool} $args */
$post = $args['post'];
?>
<article class="c-card">
    <a href="<?php echo esc_url( (string) get_permalink( $post ) ); ?>">
        <?php echo esc_html( get_the_title( $post ) ); ?>
    </a>
    <?php if ( $args['show_date'] ?? false ) : ?>
        <time><?php echo esc_html( get_the_date( '', $post ) ); ?></time>
    <?php endif; ?>
</article>
```

## 解決順序(テーマ/プラグイン両対応)

`Template::part( 'parts/card' )` は次の順でファイルを解決する:

1. 子テーマ `parts/card.php`
2. 親テーマ `parts/card.php`
3. (プラグインのKernelから呼ばれた場合)プラグインの `templates/parts/card.php`

つまりプラグインはデフォルトテンプレートを `templates/` に同梱し、利用側テーマは同パスのファイルを
置くだけで上書きできる。テーマ開発では1と2しか使われない(WP標準と同じ挙動)。

## ロジックの分離

テンプレートに複雑なデータ取得・整形を書かない。手順:

1. データ取得・整形はエンティティの明示アクセサ(rules/meta.md)、テンプレート前処理(rules/actions.md)、Hook/サービスクラスに置く。
2. テンプレートは「受け取った値をエスケープして出す」ことに専念する。
3. 1テンプレートで使う変数が多くなったら、パーシャル化して `$args` で渡す。

ページテンプレートが `actions/` から受け取った値(`tms_var()`)をパーシャルへ渡すときは、必ず `get_template_part`/`Template::part` の `$args` に乗せる。**パーシャル内で `tms_var()` を直接読まない**(データの追跡経路が2本になり追えなくなる)。

## してはいけないこと

- `template_include` / `template_redirect` でのテンプレート乗っ取り・`exit`(WP標準階層の迂回は禁止)。
- パーシャル内での `extract()`、グローバル変数(`global $foo`)への依存。
- `$args` を介さない暗黙の変数受け渡し(親テンプレートのローカル変数はパーシャルからは見えない)。
- パーシャル内で `tms_var()` / `View::get()` を読む(値はテンプレートから `$args` で渡す。rules/actions.md)。
- パーシャル内のループ変数に `$post` を使う(`load_template` のglobal展開で上書き事故が起きる。`$item` 等の別名にする)。
- パーシャル内での `query_posts()` やメインクエリの書き換え。
- エスケープなしの出力(`echo $args['title']` は禁止。必ず `esc_html()` 等を通す)。

## 確認方法

1. `composer qa` が通ること。
2. パーシャルが表示され、`$args` で渡した値が反映されること。
3. (プラグインの場合)テーマ側に同名ファイルを置くとそちらが優先されること。
````

> 整合確認(07-view-support.md): `Template::part(string $slug, array $args = []): void` / `Template::render(string $slug, array $args = []): string`、パーシャル内変数名 `$args`(WP `get_template_part` 第3引数と同名規約)、解決順序(子テーマ→親テーマ→プラグインtemplates/)。

### 7.10 rules/extensions.md

````markdown
# 拡張パッケージを導入する

コアに含まれない付帯機能は、composerで個別に追加する公式拡張パッケージとして提供される。
「この機能はFWにないか?」と思ったら、まずこの表を確認する。自作・他プラグイン導入はその後に検討する。

| パッケージ | 機能 | 導入コマンド |
|---|---|---|
| tmswp/thumbnail | 任意サイズのサムネイルURL生成(署名付きURL+WP_Image_Editor) | `composer require tmswp/thumbnail` |
| tmswp/settings-page | 管理画面の設定ページ(Form層ベース・nonce自動) | `composer require tmswp/settings-page` |
| tmswp/acf-bridge | ACF統合(acf-json→型付きアクセサ生成) | `composer require tmswp/acf-bridge` |
| tmswp/ja-toolkit | 日本語サイト対応(全モジュールオプトイン) | `composer require tmswp/ja-toolkit` |

導入後は各パッケージのREADME(`vendor/tmswp/<name>/README.md`)が一次資料。以下は導入直後の最短利用例。

## tmswp/thumbnail — サムネイルURL

```php
// 添付ID or 画像URLから、指定サイズの署名付きサムネイルURLを得る(グローバルヘルパ。
// クラス形式 \TMSWP\Thumbnail\Thumbnail::url() と同一)
$url = tms_thumb( get_post_thumbnail_id(), 480, 320 );
```

- URLは署名付きで、署名のないサイズ指定リクエストは404になる(旧timthumb型の脆弱性対策)。
- アイキャッチ未設定時のフォールバックは、コアの `tmswp/post/thumbnail_fallback` フィルタに app 側で実装する。本文先頭img抽出の例は rules/post-type.md を参照。

## tmswp/settings-page — 設定ページ

設定ページ1つにつきクラス1つを `app/SettingsPage/` に置く。フィールド定義はフォームと同じ
`FormDefinition` を使い、保存先は rules/options.md のオプショングループ(backed enum)を指定する。CSRF(nonce)・
保存処理・通知表示は自動。詳細はREADME。

## tmswp/ja-toolkit — 日本語サイト対応

**全モジュールがオプトイン**(入れただけでは何も起きない)。functions.php のブート直後で有効化する。
モジュールは `TMSWP\JaToolkit\Module\` 配下のクラスで、class-string(既定設定)またはインスタンス(設定明示)のlistを渡す:

```php
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\DisableTexturize;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;

JaToolkit::enable([
    JapaneseSlug::class,               // 日本語タイトル由来のスラッグを自動変換
    FullwidthSearchNormalizer::class,  // 検索語の全角スペース正規化
    DisableTexturize::class,           // 和文で不要なwptexturizeを一括無効化
]);
```

必要なモジュールだけを列挙する。各モジュールの詳細な効果はREADMEの一覧表を参照。
管理画面・wp_head等の汎用クリーンアップは本パッケージでは提供しない(rules/hooks.md の実装例に従いapp側Hookクラスで行う)。

## してはいけないこと

- 拡張が提供する機能の自作(特にサムネイル生成スクリプトの自作・timthumb系の導入は禁止)。
- vendor/ 配下の拡張パッケージのコード改変。
- 拡張の機能が要件に足りない場合に拡張をコピーして改造すること(FW本体への課題として報告し、
  当面はapp/側でフック等により挙動を補う)。
- ja-toolkitの全モジュール無条件有効化(効果を理解したモジュールだけをオプトインする)。

## 確認方法

1. `composer require` 後に `composer qa` が通ること(拡張のPHPStan型情報も自動で読み込まれる)。
2. 各拡張の「最短利用例」が動くこと。
````

> 整合確認(08-extensions.md / 09-acf-bridge.md): `tms_thumb(int|string $src, ?int $width = null, ?int $height = null, array $options = []): string`(= `TMSWP\Thumbnail\Thumbnail::url()` への委譲)、アイキャッチ未設定時の `tmswp/post/thumbnail_fallback` フィルタ、`JaToolkit::enable(array $modules)`(`TMSWP\JaToolkit\Module\` 配下のclass-string|インスタンスのlist。`JapaneseSlug` / `FullwidthSearchNormalizer` / `DisableTexturize` の3モジュール)、settings-pageの配置規約 `app/SettingsPage/`。08/09文書の確定APIと突合済み。

---

## 8. starter-theme(tmswp/starter-theme)

### 8.1 ファイルツリー

```
starters/theme/
├── AGENTS.md                 # §5.1 全文
├── CLAUDE.md                 # 「@AGENTS.md」の1行のみ
├── README.md                 # §8.7 全文(人間向け導入文書)
├── composer.json             # §8.2
├── style.css                 # §8.3(プレースホルダ入り)
├── functions.php             # §8.4(FWブートのみ)
├── index.php                 # §8.5(最小のWP標準ループ)
├── header.php                # 最小(<!DOCTYPE html>〜wp_head〜<body>)
├── footer.php                # 最小(wp_footer〜</html>)
├── actions/             # テンプレート前処理ファイルの置き場(rules/actions.md)
│   └── .gitkeep
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
├── phpstan-actions.neon      # §8.6(actions/ 規約検査用。level 0・規約ルールのみ)
├── .php-cs-fixer.dist.php    # §8.6
└── .gitignore                # §8.6
```

方針: スケルトンの `app/` は **動くサンプル1つ(ThemeSetupHook)+空ディレクトリ構造** とする。サンプルCPT等は置かない(消す手間と「消し忘れた雛形CPTが本番に出る」事故を避ける。AIはrules/のコード例から生成する)。`actions/` はテーマ直下の空ディレクトリ(`.gitkeep` のみ)とし、テンプレート前処理が必要になったときにrules/actions.mdに従って同名ファイルを追加する(完全オプトイン。空のままでもテーマは完全動作する)。

### 8.2 composer.json(全文)

```json
{
    "name": "tmswp/starter-theme",
    "description": "Starter theme for TMStack WordPress Framework.",
    "type": "project",
    "license": "proprietary",
    "require": {
        "php": ">=8.2",
        "tmswp/core": "^0.1"
    },
    "require-dev": {
        "brain/monkey": "^2.6",
        "friendsofphp/php-cs-fixer": "^3.64",
        "phpstan/extension-installer": "^1.4",
        "phpstan/phpstan": "^2.0",
        "phpunit/phpunit": "^11.0",
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
        "analyse": "phpstan analyse",
        "tms:check": [
            "phpstan analyse -c phpstan-actions.neon",
            "@php vendor/tmswp/core/bin/tms-check actions"
        ],
        "test": "phpunit",
        "qa": [
            "@lint",
            "@analyse",
            "@tms:check",
            "@test"
        ],
        "post-create-project-cmd": "@php bin/setup.php"
    },
    "config": {
        "allow-plugins": {
            "phpstan/extension-installer": true
        },
        "sort-packages": true
    }
}
```

- `name` はPackagist登録名のまま配布し、create-project後に `bin/setup.php` が `local/<slug>` へ書き換える(§10)。
- `phpstan/extension-installer` がPHPStan拡張(szepeviktor/phpstan-wordpress と tmswp/core 同梱拡張)を自動登録する(10-quality.md §7.1)。`allow-plugins` を事前記載しているため、create-project後の `composer install` は承認プロンプトなしで完了する。
- バージョン制約(`^0.1` 等)はリリース時の実バージョンに合わせて更新する。
- 統合テスト(`test:integration`)はスターターには含めない(利用側プロジェクトのテストはユニット層のみを既定とする。必要なプロジェクトはコアのwp-env構成を参考に追加する)。したがってスターターの `test` はユニット層のみを指す(コアmonorepoとの範囲差はAGENTS.mdに注記済み。§2参照)。
- `tms:check` は actions/ 規約検査の合成スクリプト(出力・`set_404()` 直呼び検査=FW同梱PHPStanルールを `phpstan-actions.neon` で適用、孤児actions検査=コア同梱 `bin/tms-check`。正準仕様は10-quality.md §3A)。

### 8.3 style.css(全文)

```css
/*
Theme Name: {{project_name}}
Description: Built with TMStack WordPress Framework.
Version: 0.1.0
Requires at least: 6.4
Requires PHP: 8.2
Text Domain: {{project_slug}}
*/
```

### 8.4 functions.php(全文)

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// アプリログの出力先(引数は出力ディレクトリ。{date}.log が日次生成される)。
// チャンネル・レベル等の調整方法はFW設計書(View/Support/Log)を参照
\TMSWP\Core\Log\Log::set(
    'default',
    new \TMSWP\Core\Log\SimpleLogger(WP_CONTENT_DIR . '/logs')
);

// FWブート: app/ 配下のAttribute付きクラスを自動探索・登録する。ここにロジックを足さないこと
\TMSWP\Core\Kernel::boot(
    \TMSWP\Core\Kernel\KernelConfig::theme(
        appDir: __DIR__ . '/app',
        appNamespace: 'App',
    )
);
```

### 8.5 index.php(全文。WP標準そのもの)

```php
<?php

declare(strict_types=1);

get_header();
?>
<main>
    <?php if ( have_posts() ) : ?>
        <?php while ( have_posts() ) : the_post(); ?>
            <article>
                <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                <?php the_excerpt(); ?>
            </article>
        <?php endwhile; ?>
        <?php the_posts_pagination(); ?>
    <?php else : ?>
        <p>記事がありません。</p>
    <?php endif; ?>
</main>
<?php get_footer(); ?>
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

- `includes` の手動列挙は不要。PHPStan拡張(szepeviktor/phpstan-wordpress、およびFW同梱の `#[MetaSchema]`/`#[OptionSchema]` 型ナローイング拡張=DR-05)は、§8.2 require-devの `phpstan/extension-installer` が設定なしで自動登録する(10-quality.md §7.1の確定方式。vendor配下の拡張パスをスターター側が知る必要がなく、コア側の配置変更にも自動追従する)。
- 解析対象は `app/` のみ。テンプレートファイル(WPグローバル文脈)は対象外とする。

`phpstan-actions.neon`(actions/ 規約検査用の軽量構成。`composer tms:check` から使う):

- level 0(型検査なし・規約検査のみ)、paths は `actions` のみ。FW同梱の規約ルール
  `NoOutputRule`(出力構文の検出)/ `NoSet404Rule`(`set_404()` 直呼びの検出)を適用する。
- 完全版の内容(ルール登録のinclude形)は10-quality.md §3Aの正準を転記する。この2ルールはコア同梱の
  extension.neonに意図的に含まれない(extension-installerで全コードへ自動適用されるのを避けるため。10-quality.md §3A)
  ので、この設定ファイルでのみ明示登録する。actions/ は通常の `phpstan.neon`
  (level max)の対象には含めない(規約検査のみを課し型検査は課さない。上記「解析対象は `app/` のみ」の方針と整合)。

`.php-cs-fixer.dist.php`:

```php
<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->append([__FILE__, __DIR__ . '/functions.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'               => true,
        'declare_strict_types' => true,
        'no_unused_imports'    => true,
        'ordered_imports'      => ['imports_order' => ['class', 'function', 'const']],
        'strict_param'         => true,
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
composer qa     # lint + analyse + test が通ることを確認
```

`bin/setup.php` が残っている場合(`composer create-project --no-scripts` で作成した場合)は、
先に `php bin/setup.php` を手動実行すること。

新規プロジェクトはこの雛形から作成する(参考):

```bash
composer create-project tmswp/starter-theme my-theme
```

## 開発規約

- 開発規約・禁止事項・必須コマンド: [AGENTS.md](./AGENTS.md)(人間・AIエージェント共通)
- タスク別レシピ: `vendor/tmswp/core/rules/*.md`(AGENTS.md冒頭の対応表から参照)
````

- `{{project_name}}` はsetup.phpの置換対象(§10.2の置換対象に `README.md` を含める)。
- plugin版は「WordPressテーマ」を「WordPressプラグイン」、`starter-theme` / `my-theme` を `starter-plugin` / `my-plugin` に差し替えた同一構成とする。

`.gitignore`:

```
/vendor/
/node_modules/
.php-cs-fixer.cache
.phpunit.result.cache
```

- Kernelの探索キャッシュ(ComponentCache)の既定出力先はプロジェクト外の `wp-content/cache/tmswp/`
  (02-kernel.mdの正準既定。WP外フォールバックは `sys_get_temp_dir() . '/tmswp-cache'`)のため、
  .gitignoreでの除外は不要。デプロイ時は `wp tmswp cache clear` で破棄・再生成する。

---

## 9. starter-plugin(tmswp/starter-plugin)

### 9.1 ファイルツリー

```
starters/plugin/
├── AGENTS.md                 # §5.2(theme版との差分適用済み全文)
├── CLAUDE.md                 # 「@AGENTS.md」の1行のみ
├── README.md                 # §8.7と同一構成(「テーマ」→「プラグイン」、starter-theme→starter-pluginに差し替え)
├── composer.json             # §9.2(theme版との差分のみ記載)
├── plugin.php                # §9.3(プラグイン本体。setup.phpが <slug>.php にリネームする)
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
- scripts: プラグインは `actions/` 規約を使わない(§5.2)ため、`tms:check` を定義せず、`qa` は `["@lint", "@analyse", "@test"]` とする。`phpstan-actions.neon` も同梱しない。
- autoloadは `PluginApp\` / `PluginApp\Tests\` を `app/` / `tests/` へ割り当てる。テーマスターターの `App\` とは分け、同一WordPress上でテーマとプラグインを併用できるようにする。
- それ以外(require / config)はtheme版(§8.2)と同一。

### 9.3 plugin.php(全文)

```php
<?php

/**
 * Plugin Name: {{project_name}}
 * Description: Built with TMStack WordPress Framework.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Text Domain: {{project_slug}}
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

// アプリログの出力先(引数は出力ディレクトリ。{date}.log が日次生成される)。
// チャンネル・レベル等の調整方法はFW設計書(View/Support/Log)を参照
\TMSWP\Core\Log\Log::set(
    'default',
    new \TMSWP\Core\Log\SimpleLogger( WP_CONTENT_DIR . '/logs' )
);

// FWブート: app/ 配下のAttribute付きクラスを自動探索・登録する。ここにロジックを足さないこと。
// file: __FILE__ により、有効化フック(Modelマイグレーション等)とtemplates/フォールバックが自動配線される
\TMSWP\Core\Kernel::boot(
    \TMSWP\Core\Kernel\KernelConfig::plugin(
        file: __FILE__,
        appDir: __DIR__ . '/app',
        appNamespace: 'PluginApp',
    )
);
```

### 9.4 app/Hook/PluginSetupHook.php(全文)

```php
<?php

declare(strict_types=1);

namespace PluginApp\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class PluginSetupHook extends Hook
{
    #[Action('init')]
    public function onInit(): void
    {
        // プラグインの初期化処理をここに書く(不要になったらこのクラスごと削除してよい)
    }
}
```

### 9.5 .php-cs-fixer.dist.php(theme版との差分)

- `->append([__FILE__, __DIR__ . '/functions.php'])` を `->append([__FILE__])` に変更し、
  `->in()` の対象はそのまま(`app`, `tests`)。プラグイン本体ファイル(リネーム後に名前が変わるため)は
  `->append()` に含めず、`declare(strict_types=1)` の担保はレビューで行う。

---

## 10. composer create-project フロー

### 10.1 利用者(人間/AI)の手順

```bash
# テーマ(wp-content/themes/ 直下で実行)
composer create-project tmswp/starter-theme my-shop-theme

# プラグイン(wp-content/plugins/ 直下で実行)
composer create-project tmswp/starter-plugin my-shop-tools
```

create-project完了時に `post-create-project-cmd`(`bin/setup.php`)が自動実行され、次が行われる:

1. **スラッグ決定**: 設置ディレクトリ名を正規化(`小文字化`+`[a-z0-9-]`以外を`-`に置換)してスラッグとする(例: `my-shop-theme`)。
2. **表示名決定**: 対話端末ならプロンプトで確認(デフォルト=スラッグのTitle Case)。非対話(CI・AIエージェント)ならデフォルトを無確認採用する。
3. **プレースホルダ置換**: `style.css`(テーマ)/`plugin.php`(プラグイン)/`README.md` 内の `{{project_name}}` / `{{project_slug}}` を置換する。
4. **composer.json書き換え**: `name` を `local/<slug>` に変更し、`post-create-project-cmd` エントリを削除する。
5. **本体リネーム**(プラグインのみ): `plugin.php` → `<slug>.php`。
6. **自己削除**: `bin/setup.php` と `bin/` を削除する(再実行事故の防止)。
7. 次の一手(`composer qa` 実行・AGENTS.md参照)を標準出力に案内する。

名前空間の置換は行わない(テーマスターターは `App\`、プラグインスターターは `PluginApp\` 固定が既定。変更したいプロジェクトのみ `KernelConfig` の `appNamespace` とcomposer.jsonのPSR-4を手で揃える)。

### 10.2 bin/setup.php(全文)

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * composer create-project 直後に1回だけ実行されるセットアップスクリプト。
 * 完了後に自分自身を削除する。冪等性は考慮しない(削除済みなら実行されない)。
 */

$root = dirname(__DIR__);

// 1. スラッグ決定(設置ディレクトリ名を正規化)
$slug = strtolower(basename($root));
$slug = (string) preg_replace('/[^a-z0-9\-]+/', '-', $slug);
$slug = trim($slug, '-');
if ('' === $slug) {
    fwrite(STDERR, "ERROR: could not derive project slug from directory name.\n");
    exit(1);
}

// 2. 表示名決定(対話端末のみ確認。CI/エージェント実行ではデフォルト採用)
$defaultName = ucwords(str_replace('-', ' ', $slug));
$name        = $defaultName;
if (defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN)) {
    fwrite(STDOUT, "Project name [{$defaultName}]: ");
    $input = trim((string) fgets(STDIN));
    if ('' !== $input) {
        $name = $input;
    }
}

// 3. プレースホルダ置換(存在するファイルのみ)
foreach ([$root . '/style.css', $root . '/plugin.php', $root . '/README.md'] as $file) {
    if (!is_file($file)) {
        continue;
    }
    $content = (string) file_get_contents($file);
    $content = str_replace(['{{project_name}}', '{{project_slug}}'], [$name, $slug], $content);
    file_put_contents($file, $content);
}

// 4. composer.json の name 書き換え+セットアップスクリプト登録の除去
$composerFile = $root . '/composer.json';
$composer     = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);

$composer['name'] = 'local/' . $slug;
unset($composer['scripts']['post-create-project-cmd']);

file_put_contents(
    $composerFile,
    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

// 5. プラグイン本体のリネーム
if (is_file($root . '/plugin.php')) {
    rename($root . '/plugin.php', $root . '/' . $slug . '.php');
}

// 6. 自己削除
@unlink(__FILE__);
@rmdir(__DIR__);

fwrite(STDOUT, "\n  Project initialized: {$name} ({$slug})\n\n"
    . "  Next steps:\n"
    . "    1. composer qa            # 検証コマンドが通ることを確認\n"
    . "    2. AGENTS.md を読む        # このプロジェクトの開発規約\n"
    . "    3. タスクに応じて vendor/tmswp/core/rules/ のレシピを読む\n\n");
```

実装上の注意:

- スターターのcomposer.json上の `name` はPackagist配布名のまま(プレースホルダにするとPackagist登録・create-project解決が壊れる)。ローカル名への書き換えは必ずsetup.php側で行う。
- `composer create-project --no-scripts` で実行された場合はsetup.phpが走らない。READMEとAGENTS.mdの冒頭に「`bin/setup.php` が残っている場合は `php bin/setup.php` を手動実行せよ」と記載する。
- setup.phpはvendor非依存・PHP標準関数のみで書く(オートロード前提にしない)。

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

- [ ] monorepoルートに `AGENTS.md`(§4の内容)と `CLAUDE.md`(`@AGENTS.md` 1行)が存在する。
- [ ] `packages/core/rules/` に11ファイル(post-type/taxonomy/meta/acf/form/actions/model/options/hooks/view/extensions)が存在し、composer配布物に含まれる(`composer archive` の内容で確認)。
- [ ] 各レシピが共通フォーマット(§6.2: 手順/完全な実装例/してはいけないこと/確認方法)を満たす。
- [ ] `starters/theme` / `starters/plugin` が§8/§9のツリーどおりに存在する。
- [ ] スターター直下に `README.md`(§8.7)が存在し、セットアップ手順・create-project例・AGENTS.md/rules/への導線を含む(規約本文の転載はないこと)。
- [ ] スターターのAGENTS.mdのタスク対応表のパス(`vendor/tmswp/core/rules/*.md`)が実在ファイルと一致する。

### 12.2 コード例の正しさ

- [ ] レシピ・スターター内の全PHPコード例が `declare(strict_types=1)`・完全な `use` 文・finalクラス・型宣言を備え、省略(`// ...`)を含まない(`@generated` クラス例の実装本体コメントを除く)。
- [ ] 全コード例がCONTRACTの正準API名(名前空間・クラス名・メソッド名)と一致する。各レシピの「整合確認」注記に挙げたAPIは、担当文書(03〜09)の確定版と突合済みである。
- [ ] コード例をスターター上に実際に配置して `composer qa` が通る(§6.3の `rules-verify` CIジョブが抽出・配置・検証を実行する)。
- [ ] 本文書内の全composer/WP-CLIコマンド表記が、10-quality.md §1の正準スクリプト名(`lint` / `fix` / `analyse` / `test:unit` / `test:coverage` / `test:integration` / `test` / `qa:fast` / `qa`)および各文書のWP-CLIコマンド定義とgrep突合で一致する(12-implementation-plan.md §6と同基準)。スターター側 `composer test`(=ユニットのみ)の範囲差はAGENTS.md・§8.2の注記で明示されている。

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

- [ ] コアのpublic API変更時にrules/・starters/の追従を確認するCIジョブ(§6.3の `rules-verify`)が存在する。
- [ ] レシピの「整合確認」注記は設計書(本文書)のみに置き、配布されるrules/*.md本文には含まれていない。

---

## DECISION一覧(本文書内で実装者裁量を残した箇所)

なし。初稿でDECISIONとしていた2点は次のとおり確定し、本文へ反映済み。

- スターターREADME.md: 同梱必須とし、内容は§8.7で全文確定した(セットアップ手順・create-project例・AGENTS.md/rules/への導線のみ。規約本体はAGENTS.mdに一元化し転載しない)。
- レシピ内コードブロックの検証方式: §6.3の `rules-verify` CIジョブとして確定した(```` ```php ````フェンス抽出→フィクスチャ配置→`composer lint` + `composer analyse`。10-qa.mdのCIワークフローに追加)。
