# TMStack WordPress Framework — 設計書群

本ディレクトリ(`docs/tmswp/`)は、WordPress 用フレームワーク **TMStack WordPress Framework**(以下 TMSWP)をゼロから実装するための設計書一式である。10 年・15 プロジェクトで運用された自社 FW iwf2b の価値を踏襲しつつ、PHP 8.2+ / WordPress 6.4+ 前提で**完全新規**(後方互換なし)に再構築する。

## この設計書群の目的

- **読者は「この設計書群だけを渡されて TMSWP をゼロから実装する LLM」**である。各文書は曖昧表現を排し、クラスごとに完全な PHP シグネチャ・振る舞い仕様・エッジケース・受け入れ基準を確定させている。
- 設計判断は確定済み(DR-01〜DR-19、`00-overview.md` §4)であり、**再議論しない**。実装者裁量が残る箇所のみ各文書に行頭 `DECISION:` で明示してある(本 README 末尾に全件を集約)。
- 正準クラス名・名前空間・ブート API・コーディング規約は全文書共通であり、`00-overview.md` が原則・用語・DR の正準、各担当文書が個別 API の正準を持つ。**本書と担当文書が矛盾した場合、個別 API は担当文書が、原則・DR・用語は `00-overview.md` が優先する**。

### 設計の根幹(要点)

| 原則 | 内容 |
|---|---|
| WP 標準を壊さない(絶対原則) | ビューテンプレートに特殊処理を行わない。`template_include` 乗っ取り・`exit`・`extract()`・グローバル変数展開は全面禁止。FW はテンプレートから呼べる型付きヘルパを提供するだけ |
| コアは外部プラグイン非依存 | `tmswp/core` の runtime 依存は `psr/log ^3` と `psr/container ^2` のみ。ACF/CF7/Yoast 等の制御コードはコアに一切含めない |
| シンプル・イズ・ベスト | 実利用が実証された機能だけを最小 API サーフェスで提供(旧 FW 未使用 37% の教訓)。1 機能 = 1 つの明白なやり方 |
| AI ファースト | マジックメソッド・文字列ディスパッチ・暗黙規約を禁止。Attribute・enum・定数・明示シグネチャで宣言。規約は AGENTS.md / rules/ として機械可読 |
| 型安全 | `declare(strict_types=1)` 必須、全シグネチャに型宣言、PHPStan level max・baseline なし。null センチネル全廃(has()/get() 分離) |

## 文書マップ

番号順がそのまま推奨読書順かつ実装の概略順序である。「依存」は読む前提となる先行文書を指す。

| # | ファイル | 内容(1 行要約) | 主な DR | 依存 |
|---|---|---|---|---|
| 00 | [`00-overview.md`](./00-overview.md) | 全体概要。背景・ゴール/非ゴール・設計原則 5 箇条・確定済み設計判断 DR-01〜DR-19・文書マップ・用語集 | 全 DR | なし(起点) |
| 01 | [`01-architecture.md`](./01-architecture.md) | monorepo 構成、各パッケージの composer.json 仕様、依存関係、read-only split 配布、SemVer/リリース運用、ディレクトリレイアウト | DR-01, 02, 11, 14, 15 | 00 |
| 02 | [`02-kernel.md`](./02-kernel.md) | Kernel / KernelConfig / KernelContext / Discovery / ComponentCache / Container(PSR-11)/ Attribute 探索(キャッシュ・決定的ロード順・循環検出・WP フック整合)。`ComponentAttributeInterface` 契約と全属性の初期化タイミング表 | DR-04, 09 | 00, 01 |
| 03 | [`03-entities.md`](./03-entities.md) | PostType / Taxonomy / UserRole 宣言層(Definition オブジェクト、PostQuery / TermQuery / UserQuery、ラベル自動生成 ja/en) | DR-17 | 00, 02 |
| 04 | [`04-meta-options.md`](./04-meta-options.md) | MetaSchema / MetaBag / MetaSchemaRegistry / MetaStorageInterface(has/get 分離)/ PreviewMetaSupport、OptionGroup / OptionRepository、PHPStan 型ナローイング拡張 | DR-05, 18 | 00, 02, 03 |
| 05 | [`05-form.md`](./05-form.md) | Form ライフサイクル(バインド/CSRF/検証/状態遷移)、FormDefinition / Field / Rule 群、確認画面(ConfirmToken / HMAC)、UploadedFile / TempFileStore、FormHtml 純関数レンダラ | DR-07 | 00, 02 |
| 06 | [`06-model.md`](./06-model.md) | Model / TableDefinition / QueryBuilder(常時 `wpdb->prepare`)/ Migrator / Connection(マイグレーション契機、DTO ハイドレーションオプション) | DR-08 | 00, 02 |
| 07 | [`07-support.md`](./07-support.md) | View\Template(part/render、プラグイン同梱 fallback)、ActionRouter / View 変数バッグ(actions 規約・dispatch 正準仕様・tms_var)、Support\Arr/Str/Html、Log(PSR-3 v3 自前実装)、Hook 基底 | DR-14, 16, 19 | 00, 02 |
| 08 | [`08-extensions.md`](./08-extensions.md) | 拡張 3 パッケージ: thumbnail(署名付き URL)/ settings-page / ja-toolkit(日本語サイト対応のオプトインモジュール) | DR-10, 15, 20 | 00, 01, 02, 05(settings-page) |
| 09 | [`09-acf-bridge.md`](./09-acf-bridge.md) | acf-json 真実源、コードジェネレータ、AcfMetaStorage、CI ドリフト検知、重複キーエラー | DR-06, 18 | 00, 02, 04 |
| 10 | [`10-quality.md`](./10-quality.md) | PHPStan / php-cs-fixer / PHPUnit 2 層 / wp-env / GitHub Actions / composer スクリプト体系 | DR-12 | 00, 01 |
| 11 | [`11-ai-docs.md`](./11-ai-docs.md) | AGENTS.md 全文案、rules/ レシピ体系、app/ スケルトン、starter-theme / starter-plugin | DR-13 | 00〜10 |
| 12 | [`12-implementation-plan.md`](./12-implementation-plan.md) | 実装計画。マイルストーン M0〜M10 の分割・依存・受け入れ基準・LLM 実装者プロトコル・リスク表 | 全 DR | 00〜11 |

### 依存関係の要点

- **02(Kernel)が全機能文書の土台**。03〜06 のコンポーネント(エンティティ・メタ・フォーム・Model)はすべて 02 の Discovery / Container / Attribute 機構に登録される側であり、`ComponentAttributeInterface` 契約と初期化タイミング表は 02 が一元的に確定する。
- **04(Meta)と 09(acf-bridge)は DR-18 のコントラクト**(`MetaStorageInterface` の has()/get() 分離、`#[UseMetaStorage]`)で結合する。インターフェース定義は 04 が持ち、09 はその実装者。09 が 04 の API を変更することはできない。
- **08 の settings-page は 05(Form)のクライアント**。Form 層の公開 API のみを使う。
- **07 の ActionRouter / actions 規約は旧 iwf2b の `actions/` 規約の直接踏襲**(DR-19)。ActionRouter の boot 時生成・`template_include` 登録は 02、dispatch 正準仕様・actions ファイル規約・View 変数バッグの意味論は 07 が定義する(2 文書で役割分担)。
- **11(AI 資料)と 12(実装計画)は全文書の写像**であり、終盤に読む。

## 推奨読書順

1. **まず `00-overview.md` を全文読む**。これで設計原則・DR・正準用語・全体像が頭に入る。
2. **`01-architecture.md`**(器の形)→ **`02-kernel.md`**(心臓部)。この 2 文書が他のすべての前提になる。
3. **機能文書を 03 → 04 → 05 → 06 → 07 の順**で読む(エンティティ → メタ/オプション → フォーム → Model → Support/View/actions 規約)。
4. **拡張文書 08 → 09**(コアに含めない付帯機能・ACF 統合)。
5. **品質・AI 資料 10 → 11**。
6. **最後に `12-implementation-plan.md`** を読み、実装をどのマイルストーン順で進めるかを把握する。

実装に着手する場合は、上記を通読したうえで **`12-implementation-plan.md` の M0 から開始する**。各機能文書はマイルストーン詳細(§4)の「開始時に読む文書」として再度精読する設計になっている。

## LLM に実装を依頼する際の標準プロンプト例

設計書群一式を入力として、実装担当 LLM に渡すプロンプトの雛形を示す。実装は必ず実装計画(12)のマイルストーン順・受け入れ基準に従わせる。

### 全体実装(monorepo をゼロから構築)

```
あなたは TMStack WordPress Framework の実装担当エンジニアである。
入力は docs/tmswp/ 配下の設計書一式(00〜12)である。

次の規約で実装せよ:
1. docs/tmswp/12-implementation-plan.md の M0 から開始し、M0→M1→…→M10 の順に進める。
   §3.2 の依存グラフに従い、並行可能なレーン(A/B/C)は明示された範囲でのみ並行する。
2. 各マイルストーン開始時に「開始時に読む文書」を全文読む(要約・斜め読み禁止)。
3. 1 タスク = 1 PR。各タスクは §2.2 のループ(担当節を読む→テストを先に書く→実装→
   composer qa→受け入れ基準コマンド照合→PR)で進める。
4. API のシグネチャは設計文書の「完全な PHP シグネチャ」を転記する。創作しない。
   設計文書にない公開 API は新設しない(必要時は §2.4 のエスカレーション)。
5. 各マイルストーンの受け入れ基準(コマンド + 期待結果)をすべて満たし、
   担当文書末尾の受け入れ基準チェックリストを実機検証してから次へ進む。
6. 設計判断は DR-01〜DR-19(00-overview.md §4)に従い再議論しない。
   行頭 DECISION: と明記された箇所のみ実装者裁量で決め、決定内容を PR 本文に記録する。
7. 文書間に矛盾を発見したら実装を中断し、§2.4 の形式でエスカレーションする。

まず M0(monorepo 骨格 + QA 基盤)の受け入れ基準を満たすところから着手せよ。
```

### 単一マイルストーン/機能の実装

```
docs/tmswp/12-implementation-plan.md の M5(Form 層)を実装せよ。
- 開始時に読む文書: 05-form.md(全文)、07-support.md §Html、01-architecture.md §フォーム正準形。
- M5 のタスク分解(M5-T1〜T7)を記載順に処理する(並行可と明記されたもののみ並行)。
- 各タスクのシグネチャは 05-form.md の「完全な PHP シグネチャ」を転記する。
- M5 の受け入れ基準コマンドをすべて実行し期待結果と一致させ、05-form.md 末尾の
  受け入れ基準チェックリストを ✔ にしてから完了とする。
- HMAC 署名検証・一時ファイル解決は 05-form.md の擬似コードを逐語実装する(創作禁止)。
```

### app 側(テーマ/プラグイン)で FW を使う実装

実装済みの FW を使ってテーマ/プラグインを作る場合は、設計書ではなく **生成される `AGENTS.md` / `rules/`**(`11-ai-docs.md` が全文案を定義)を参照させる。設計書(00〜12)は FW 本体の実装者向けであり、app 開発者向けではない。

## 旧 FW の実ソースについて

旧 FW の実ソースは `htdocs/iwf2b/src/` にある(仕様確認のための参照のみ。コードの移植・コピーは禁止 = DR-03)。

## 実装者裁量(DECISION:)が残る箇所

設計書は原則として実装者裁量を残さないが、以下の 4 箇所のみ `DECISION:` で裁量を明示している(うち 3 件は行頭 `DECISION:`、08 の GC は本文中の `(DECISION: …)` 形式)。実装時は各記載の指示に従い、決定内容を PR 本文に記録すること。

| 文書 | 箇所 | 内容と既定の扱い |
|---|---|---|
| `03-entities.md` | 例外階層 | FW 共通の基底例外(例: `TMSWPException`)を全体方針として導入する場合、エンティティ層の 3 例外クラスをそれに継承させてよい(**継承元の変更のみ可**。クラス名・名前空間・プロパティは本文書が正準) |
| `06-model.md` | QueryBuilder の raw 断片検証 | 規約 1〜3 の機械検査に加え、raw 断片の許可トークン集合(識別子・数値・演算子・許可 SQL キーワード)サブセット検証を追加してよい。実装する場合は許可キーワードリストを定数で確定し回避手段を設けない |
| `08-extensions.md` | thumbnail の GC | キャッシュ自動 cron は持たない(運用判断)。必要なら app 側で `wp_schedule_event` から CLI 相当処理を呼ぶレシピを rules/ に記載する |
| `12-implementation-plan.md` | ライセンス | WordPress 互換の観点から GPL-2.0-or-later を推奨。最終決定は人間オーナー。M0-T1 時点で未決定なら GPL-2.0-or-later を仮置きして PR 本文で報告する |

なお `06-model.md` には `DECISION:` 表記を**しない**(=実装者裁量にしない)確定事項が 2 箇所ある(`ORDER BY RAND()` は非対応とし乱択は PHP 側で行う等)。これらは裁量箇所ではない。
