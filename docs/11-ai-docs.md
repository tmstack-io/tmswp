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
- 02-kernel.md(Kernel::boot / KernelConfig / Discovery / キャッシュ)
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
│       └── rules/                  … §6 レシピ11本(パッケージに同梱して配布。本文はこのディレクトリが正本)
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

- 対象: PHP 8.3〜8.5 / WordPress 7.0以上。コアのruntime依存は psr/log ^3 と psr/container ^2 のみ。
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
composer test               # ユニット+統合テスト(test:unit → test:integration。wp-env起動が必要: composer wp:start)
composer test:unit          # ユニットテスト(WP非依存・Brain Monkey。Docker不要)
composer test:coverage      # ユニットテスト+カバレッジ計測
composer test:integration   # 統合テスト(wp-env起動が必要: composer wp:start)
composer analyse            # PHPStan level max(baseline禁止)
composer lint               # コードスタイル検査(php-cs-fixer dry-run)
composer fix                # コードスタイル自動修正
```

- wp-env は `npm ci` で入れたローカルの `@wordpress/env`(package.jsonで版を固定)を `composer wp:start` / `wp:stop` / `test:integration` 経由で使う。直接呼ぶときは `node node_modules/@wordpress/env/bin/wp-env` とパスで指定し、コマンド名 `wp-env` を名前解決する呼び出しは使わない。
- PHPStanは **level max・baselineなし** で運用する。エラーを baseline や @phpstan-ignore で握りつぶす変更は拒否される。
  ignoreが本当に必要な場合(WP関数のstub不備等)は理由コメントを併記し、PRで明示すること。
- テストはユニット層(tests/Unit, WP非依存)と統合層(tests/Integration, wp-env + wp-phpunit)の2層。
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
| post-type.md | CPT追加 | 03-entities |
| taxonomy.md | タクソノミー追加 | 03-entities |
| meta.md | 非ACFカスタムフィールド | 04-meta-options |
| acf.md | acf-json運用+型付きアクセサ生成 | 09-acf-bridge |
| form.md | フォーム実装(3画面+メール) | 05-form |
| actions.md | テンプレート前処理(テンプレート同名 actions/ ファイル規約) | 07-support |
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

レシピ内のコード例の名前空間は `App\` 固定(スターター既定)。`KernelConfig` で別名前空間を選んだプロジェクトは読み替える旨を各レシピ冒頭に1行で示す。

レシピの本文は各パッケージの `rules/*.md` が正本であり、本文書は構成と共通フォーマットだけを定める。レシピのコード例が担当文書(02〜10)の確定APIと食い違う場合は **担当文書を正としてレシピ側を修正** する。拡張パッケージ固有のレシピは、そのパッケージの `rules/` に同じ共通フォーマットで置く。

### 6.3 レシピ検証CI(コード例ドリフトの機械検出)

rules/*.md内のコード例とFWのAPIの乖離(ドリフト)は、10-quality.mdのGitHub Actionsワークフローに置くCIジョブ `rules-verify` で機械検出する。実装方式は次に確定する:

1. **抽出**: `tools/extract-rules-code.php`(monorepo同梱。vendor非依存・PHP標準関数のみ)が全パッケージの `packages/*/rules/*.md` から ```` ```php ```` フェンスのコードブロックを抽出する。`<?php` で始まり `namespace` 宣言を含むブロックを「クラス定義例」とみなし、`var/rules-code/app/` 配下へPSR-4整合のパス(例: `App\Post\NewsPost` → `var/rules-code/app/Post/NewsPost.php`)で書き出す。
2. **テンプレート断片**: `namespace` 宣言を含まないブロック(page-contact.php等のWPグローバル文脈コード)は `var/rules-code/templates/` へ `<レシピ名>-<連番>.php` で書き出し、`php -l` による構文チェックのみ行う(PHPStan対象外。§8.6の方針と同一)。
3. **検証**: starter-theme をフィクスチャとして展開し(composer.jsonの repositories(path) で `packages/core` を参照)、手順1のファイルを配置した状態で `composer lint` と `composer analyse`(PHPStan level max)を実行する。いずれかの失敗でジョブを失敗させる。
4. **実行契機**: `packages/*/`(src・rules)または `starters/` に変更を含むすべてのPR。

これにより「コアのpublic API変更にrules/・starters/が追従していない」状態をマージ前に継続検出する(§12.2・§12.5の検収項目に対応)。

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
        "php": ">=8.3",
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
- `phpstan/extension-installer` がPHPStan拡張(szepeviktor/phpstan-wordpress と tmswp/core 同梱拡張)を自動登録する(10-quality.md §7 項目2)。`allow-plugins` を事前記載しているため、create-project後の `composer install` は承認プロンプトなしで完了する。
- バージョン制約(`^0.1` 等)はリリース時の実バージョンに合わせて更新する。
- 統合テスト(`test:integration`)はスターターには含めない(利用側プロジェクトのテストはユニット層のみを既定とする。必要なプロジェクトはコアのwp-env構成を参考に追加する)。したがってスターターの `test` はユニット層のみを指す(コアmonorepoとの範囲差はAGENTS.mdに注記済み。§2参照)。
- `tms:check` は actions/ 規約検査の合成スクリプト(出力・`set_404()` 直呼び検査=FW同梱PHPStanルールを `phpstan-actions.neon` で適用、孤児actions検査=コア同梱 `bin/tms-check`。正準仕様は10-quality.md §3A)。

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
\TMSWP\Core\Kernel\Kernel::boot(
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

- `includes` の手動列挙は不要。PHPStan拡張(szepeviktor/phpstan-wordpress、およびFW同梱の `#[MetaSchema]`/`#[OptionSchema]` 型ナローイング拡張=DR-05)は、§8.2 require-devの `phpstan/extension-installer` が設定なしで自動登録する(10-quality.md §7 項目2の確定方式。vendor配下の拡張パスをスターター側が知る必要がなく、コア側の配置変更にも自動追従する)。
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
        '@PER-CS2x0'           => true,
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
 * Requires at least: 7.0
 * Requires PHP: 8.3
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
\TMSWP\Core\Kernel\Kernel::boot(
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

- [ ] monorepoルートに `AGENTS.md`(§4の内容)が存在し、`CLAUDE.md` はmonorepo・スターターのどこにも存在しない。
- [ ] `packages/core/rules/` に11ファイル(post-type/taxonomy/meta/acf/form/actions/model/options/hooks/view/extensions)が存在し、composer配布物に含まれる(`composer archive` の内容で確認)。
- [ ] 各レシピが共通フォーマット(§6.2: 手順/完全な実装例/してはいけないこと/確認方法)を満たす。
- [ ] `starters/theme` / `starters/plugin` が§8/§9のツリーどおりに存在する。
- [ ] スターター直下に `README.md`(§8.7)が存在し、セットアップ手順・create-project例・AGENTS.md/rules/への導線を含む(規約本文の転載はないこと)。
- [ ] スターターのAGENTS.mdのタスク対応表のパス(`vendor/tmswp/core/rules/*.md`)が実在ファイルと一致する。

### 12.2 コード例の正しさ

- [ ] レシピ・スターター内の全PHPコード例が `declare(strict_types=1)`・完全な `use` 文・finalクラス・型宣言を備え、省略(`// ...`)を含まない(`@generated` クラス例の実装本体コメントを除く)。
- [ ] 全コード例が担当文書(02〜10)の正準API名(名前空間・クラス名・メソッド名)と一致する。
- [ ] コード例をスターター上に実際に配置して `composer qa` が通る(§6.3の `rules-verify` CIジョブが抽出・配置・検証を実行する)。
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

- [ ] コアのpublic API変更時にrules/・starters/の追従を確認するCIジョブ(§6.3の `rules-verify`)が存在する。
- [ ] 本文書にレシピ本文の複製が無い(レシピの正本は各パッケージの `rules/*.md`)。

---

## DECISION一覧(本文書内で実装者裁量を残した箇所)

なし。次の2点は本文で確定している。

- スターターREADME.md: 同梱必須とし、内容は§8.7で全文確定した(セットアップ手順・create-project例・AGENTS.md/rules/への導線のみ。規約本体はAGENTS.mdに一元化し転載しない)。
- レシピ内コードブロックの検証方式: §6.3の `rules-verify` CIジョブとして確定した(```` ```php ````フェンス抽出→フィクスチャ配置→`composer lint` + `composer analyse`。10-quality.mdのCIワークフローで実行)。
