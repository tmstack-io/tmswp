# 12. 実装計画書 — TMStack WordPress Framework

## 目的

本書は、設計書一式(01〜11)を入力として TMStack WordPress Framework をゼロから実装するための作業計画を定義する。読者は実装を担当する LLM である。本書は以下を確定させる。

- マイルストーン M0〜M10 の分割・依存関係・実施順序
- 各マイルストーン内のタスク分解(1タスク = 1 PR 粒度)
- 各マイルストーンの受け入れ基準(実行コマンドと期待結果)と Done の定義
- LLM 実装者が各マイルストーンの開始時に読む文書・終了時に実行する検証
- 全体リスクと対策

本書は「何をどの順に作るか」のみを定める。「何をどう設計するか」は各設計文書が正準であり、本書と設計文書の仕様記述が矛盾した場合は**設計文書を優先**する(矛盾の発見はエスカレーション対象。「§2.4 不整合発見時のプロトコル」参照)。

## スコープ(対象パッケージ)

monorepo `tmswp` 全体。すなわち:

- `packages/core`(tmswp/core)
- `packages/thumbnail` / `packages/settings-page` / `packages/acf-bridge` / `packages/ja-toolkit`
- `starters/theme`(tmswp/starter-theme)/ `starters/plugin`(tmswp/starter-plugin)
- リポジトリルートの QA 基盤・CI・配布ワークフロー

## 依存文書

本書は全設計文書を参照する。番号・実ファイル名・主題の対応は以下の通り(全文書のファイル名は確定済み。本文中の「文書 NN」は下表の実ファイルを指す)。

| 番号 | ファイル名 | 主題 | 主担当マイルストーン |
|---|---|---|---|
| 00 | `00-overview.md` | 全体概要・確定済み設計判断(DR-01〜DR-18) | 全体(DR の参照元) |
| 01 | `01-architecture.md` | 全体アーキテクチャ・monorepoレイアウト・設計原則 | 全体(M0で精読) |
| 02 | `02-kernel.md` | Kernel / Container / Discovery / Attribute / Hook | M1 |
| 03 | `03-entities.md` | エンティティ層(PostType / Taxonomy / UserRole / 各Query) | M2 |
| 04 | `04-meta-options.md` | meta / option 宣言層 + PHPStan 拡張 | M3 |
| 05 | `05-form.md` | Form 層(定義・検証・確認画面・ファイル・レンダリング) | M5 |
| 06 | `06-model.md` | Model 層(独自テーブル・QueryBuilder・Migrator) | M6 |
| 07 | `07-support.md` | Support / View / Log | M4 |
| 08 | `08-extensions.md` | 拡張パッケージ(thumbnail / settings-page / ja-toolkit) | M7 |
| 09 | `09-acf-bridge.md` | acf-bridge | M8 |
| 10 | `10-quality.md` | 品質基盤(PHPStan / php-cs-fixer / PHPUnit / CI / composer スクリプト) | M0(全マイルストーンで適用) |
| 11 | `11-ai-docs.md` | スターター + AI 資料(AGENTS.md / rules/) | M9 |
| 12 | `12-implementation-plan.md` | 実装計画(本書) | — |

---

# 1. 前提条件

## 1.1 入力と成果物

- **入力**: 本設計書一式(01〜12)。旧 iwf2b のソースコードは入力に含まれない(実装時に参照しない。設計はすべて文書側で確定済み)。
- **成果物**: GitHub 上の新規リポジトリ `tmswp`(monorepo)。全マイルストーン完了時点で v0.1.0 をタグ付けできる状態。
- 旧 iwf2b との後方互換・移行シムは**一切実装しない**(DR-03)。

## 1.2 実行環境

実装・検証に必要な環境。M0 開始前に利用可能であることを確認する。

| 要素 | 要件 |
|---|---|
| PHP | 8.2 以上(CI は 8.2 / 8.3 / 8.4 のマトリクス) |
| Composer | 2.x |
| Node.js | 20 以上(LTS。`@wordpress/env` の要求に従う) |
| Docker | wp-env が起動できること |
| WP-CLI | wp-env コンテナ内で利用(`npx wp-env run cli wp ...`) |
| Git / GitHub | リポジトリ作成権限、Actions 有効 |

## 1.3 リポジトリ運用規約

- デフォルトブランチ: `main`。`main` への直接 push 禁止。すべて PR 経由。
- ブランチ名: `m{マイルストーン番号}/t{タスク番号}-{短い英語スラッグ}`(例: `m1/t5-discovery-classmap`)。
- **1 タスク = 1 PR**。PR は対応タスクの受け入れ基準をすべて満たすまでマージしない。
- PR 本文に必ず記載する項目: (a) タスクID、(b) 参照した設計文書の節、(c) 受け入れ基準コマンドの実行結果(コピー&ペースト)、(d) 設計文書から逸脱した点(なければ「逸脱なし」と明記)。
- コミットメッセージは日本語。タイトルは変更内容が一目で分かる具体的表現。
- `main` は常時 green を維持する。CI が red になる PR はマージ禁止。
- SemVer + リリースタグ運用(DR-11)。M10 完了まではタグを打たない(プレリリースタグ `v0.1.0-alpha.N` は M7 完了以降に任意で可)。

## 1.4 ライセンス

DECISION: ライセンスは WordPress 互換性の観点から GPL-2.0-or-later を推奨するが、最終決定は人間のオーナーが行う。M0-T1 の時点で未決定の場合は GPL-2.0-or-later を仮置きし、LICENSE ファイルと各 composer.json の `license` フィールドに設定して PR 本文で報告する。

---

# 2. LLM 実装者プロトコル(全マイルストーン共通)

## 2.1 マイルストーン開始時

1. 各マイルストーン詳細(§4)の「開始時に読む文書」をすべて**全文読む**。要約・斜め読みで済ませない。
2. 文書 10 のコーディング規約・QA コマンド定義を再確認する(全マイルストーン共通の前提)。
3. タスク一覧を順に処理する。「並行可」と明記されたタスク以外は記載順に実施する。

## 2.2 タスク実行ループ(1 タスクごと)

```
1. 担当設計文書の該当節を読む
2. テストを先に書く(対象 API のシグネチャは設計文書の「完全な PHP シグネチャ」を転記する。創作しない)
3. 実装する
4. ローカル検証: composer qa:fast(Docker 不要)。統合テストが対象のタスクは composer wp:start のうえ composer qa(文書 10 §1: qa = lint → analyse → test(ユニット+統合))
5. 受け入れ基準コマンドを実行し、期待結果と照合する
6. PR を作成する(§1.3 の記載項目を満たす)
```

- 設計文書に「DECISION:」と明記された箇所のみ実装者裁量で決定してよい。決定内容は PR 本文に記録する。
- 設計文書に書かれていない API を追加する必要が生じた場合、`internal`(非公開・`@internal` アノテーション付き)としてのみ追加可。公開 API の新設は不整合エスカレーション(§2.4)の対象。

## 2.3 マイルストーン終了時

1. 「終了時に実行する検証」のコマンドをすべて実行し、期待結果との一致を確認する。
2. 担当設計文書末尾の「受け入れ基準」チェックリストを 1 項目ずつ実機検証し、結果を記録する(チェックリストの転記 + ✔/✘ + 検証コマンド出力)。
3. 上記の記録をリポジトリの `docs/acceptance/M{N}.md` にコミットする(これが Done の証跡となる)。
4. ✘ が 1 つでも残る場合、そのマイルストーンは Done ではない。修正タスクを追加して解消する。

## 2.4 不整合発見時のプロトコル

設計文書間の矛盾(API シグネチャの不一致、責務の重複、参照先文書に存在しない API への言及)を発見した場合:

1. 実装を**中断**する(推測で埋めない)。
2. 矛盾の内容を「文書A の記述 / 文書B の記述 / 影響範囲 / 推奨解決案」の形式で整理し、人間のオーナーへエスカレーションする(リポジトリ Issue として起票)。
3. 解決の指示を受けてから再開する。指示内容は該当 Issue に記録する。

## 2.5 禁止事項

- 担当マイルストーン外のパッケージ・機能の先行実装(空ディレクトリ・骨格は M0 で作成済みのもののみ)。
- PHPStan baseline の追加(DR-12: baseline なし)。`@phpstan-ignore` は行単位かつ理由コメント必須。新規追加時は PR 本文で件数を報告する。
- テストのスキップ・削除による green 化。
- 設計文書にないグローバル関数・マジックメソッド・`extract()` の使用(共通コーディング規約)。
- 旧 iwf2b コードのコピー&ペースト。

---

# 3. マイルストーン総覧

## 3.1 一覧

| MS | 内容 | 参照文書 | 依存 | 規模目安(PR数) |
|---|---|---|---|---|
| M0 | monorepo 骨格 + QA 基盤(composer qa が空実装で通る) | 01, 10 | — | 8 |
| M1 | Kernel / Container / Discovery | 02 | M0 | 9 |
| M2 | エンティティ層(PostType / Taxonomy / UserRole) | 03 | M1 | 6 |
| M3 | meta / option 層 + PHPStan 拡張 | 04 | M2 | 7 |
| M4 | Support / View / Log / ActionRouter(actions/ 規約) | 07 | M1 | 6 |
| M5 | Form 層 | 05 | M1, M4 | 7 |
| M6 | Model 層 | 06 | M1 | 6 |
| M7 | 拡張 3 パッケージ(thumbnail / settings-page / ja-toolkit) | 08 | M4, M5 | 3 |
| M8 | acf-bridge | 09 | M3 | 4 |
| M9 | スターター + AI 資料 | 11 | M1〜M8 | 4 |
| M10 | ドッグフーディング(サンプルサイト + 全受け入れ基準の通し検証 + リリース) | 全文書 | M9 | 3 |

## 3.2 依存グラフと並行実行

```
M0 ─ M1 ─┬─ M2 ─ M3 ─ M8 ─┐
         ├─ M4 ─ M5 ─ M7 ──┼─ M9 ─ M10
         └─ M6 ────────────┘
```

- M1 完了後、**レーンA(M2→M3→M8)/ レーンB(M4→M5)/ レーンC(M6)は相互独立**であり、並行実装してよい(複数ワーカーで実装する場合のレーン割当に使う)。
- M7 は M4・M5 の完了後(settings-page が Form 層に、thumbnail が Support に依存するため)。
- M9 は M1〜M8 すべての完了後。M10 は最後。
- 単一ワーカーで直列実行する場合の推奨順: M0 → M1 → M4 → M2 → M3 → M6 → M5 → M7 → M8 → M9 → M10(Form 層が最も重いため、先に薄い層で規約に習熟する順序)。

## 3.3 Done の定義(全マイルストーン共通)

以下をすべて満たしたとき、そのマイルストーンは Done である。

1. 全タスクの PR がマージ済みで、`main` の CI(全マトリクス)が green。
2. `composer qa` がローカルで exit code 0(CI では `qa.yml` 全ジョブの green をもって同等とみなす)。
3. 担当設計文書の受け入れ基準チェックリストが全項目 ✔ で、証跡が `docs/acceptance/M{N}.md` にコミット済み。
4. 当該マイルストーンで `@phpstan-ignore` の純増がある場合、各行に理由コメントがあり、PR 本文で報告済み。
5. 未解決の不整合 Issue(§2.4)が当該マイルストーンに紐づいて残っていない。

以降の各マイルストーン詳細では、共通 Done に**追加**される固有条件のみを記す。

---

# 4. マイルストーン詳細

## M0: monorepo 骨格 + QA 基盤

**目的**: リポジトリの器と品質ゲートを先に完成させる。以降の全マイルストーンは「M0 で確立したコマンドが通り続けること」を不変条件として進む。

**開始時に読む文書**: 01(全体)、10(品質基盤)— 全文。11 の §ディレクトリ規約(スターターの配置のみ確認)。

### タスク分解

| ID | タスク(= 1 PR) | 内容 |
|---|---|---|
| M0-T1 | リポジトリ初期化 | `main` ブランチ、ルート `composer.json` は文書 10 §1.1 の完全版(`replace` + autoload 直マージ = `packages/*/src` を PSR-4 で直参照。monorepo 内のパッケージ相互解決に `repositories`(path)は**使わない** = 文書 01 §4.2・文書 10 §6.2 が正準)をそのまま配置、`.gitignore`、`.editorconfig`、`LICENSE`(§1.4)、`README.md`(1画面以内の概要)、PR テンプレート(§1.3 記載項目入り) |
| M0-T2 | パッケージ骨格 | `packages/{core,thumbnail,settings-page,acf-bridge,ja-toolkit}` と `starters/{theme,plugin}` の各 `composer.json`(name / autoload(PSR-4)/ require は文書 01・10 の正準値)、`src/` `tests/` ディレクトリ。各パッケージに QA 配線確認用のダミークラス 1 つ(`src/Bootstrap.php` 等、`@internal` 付き)とダミーテスト 1 つ。コアの runtime 依存が psr/log ^3 と psr/container ^2 のみであること(DR-14)をこの時点で固定 |
| M0-T3 | コードスタイル | php-cs-fixer 設定(PSR-12 + `declare(strict_types=1)` 強制、文書 10 のルールセット)。`composer lint`(検査のみ)/ `composer fix`(自動修正)を定義し、骨格全体が違反 0 |
| M0-T4 | 静的解析 | phpstan.neon(level max、szepeviktor/phpstan-wordpress、baseline なし)。`composer analyse` を定義し、骨格全体がエラー 0 |
| M0-T5 | ユニットテスト基盤 | PHPUnit 11 + Brain Monkey。WP 非依存で実行できる `composer test:unit` / `composer test:coverage` / `composer qa:fast` を文書 10 §1 の定義どおり配線。ダミーテストが pass |
| M0-T6 | 統合テスト基盤 | `.wp-env.json`(WP 6.4 固定環境 + latest 環境の 2 構成)、wp-phpunit ブートストラップ、統合テスト実行コマンド `composer test:integration` および合成スクリプト `composer test`(= `test:unit` → `test:integration`)・`composer qa`・`composer wp:start` / `wp:stop`(いずれも文書 10 §1 の正準スクリプト名)。ダミー統合テスト(`get_bloginfo('version')` が 6.4 以上であることの assert)1 つが wp-env 上で pass |
| M0-T7 | CI | GitHub Actions: 文書 10 §5.1 の `qa.yml`(完全版)を導入する。ジョブ構成は lint / analyse / monorepo / unit(PHP 8.2/8.3/8.4。8.3 セルのみ `composer test:coverage`)/ integration(PHP 8.2/8.3/8.4 × WP 6.4/latest、unit 成功後)。push / pull_request トリガ。加えて本書の追加要件として composer・npm のキャッシュ設定、wp-env 起動のリトライ(最大 2 回)とタイムアウト(15 分)を組み込む(ジョブ名・実行コマンドは文書 10 から変更しない) |
| M0-T8 | 配布ワークフロー | read-only split 配布(DR-11): `main` push・タグ push 時に `packages/*` `starters/*` を各読み取り専用リポジトリへ split する GitHub Actions ワークフロー(文書 10 §5.2 の `split.yml` 完全版 = danharrin/monorepo-split-github-action@v2。タグは `${{ github.ref_name }}` で伝播。文書 10 §5.2・文書 01 §4.4 が正準)。split 先リポジトリが未作成でも dry-run モードで検証可能にしておく。`docs/RELEASING.md` にリリース手順(タグ→split→Packagist)を記載 |

依存: T1 → T2 → (T3, T4, T5 並行可) → T6 → T7 → T8。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `composer install`(ルート) | 成功。`TMSWP\*` がルート autoload(`packages/*/src` 直参照)で解決され、`composer monorepo:validate` が green(replace + autoload 直マージ = 文書 10 §1.1/§6.2) |
| `composer qa` | exit code 0(lint + analyse + test がすべて通る。文書 10 定義の合成スクリプト) |
| `composer lint` | 違反 0 |
| `composer analyse` | `[OK] No errors`(level max、baseline なし) |
| `composer test:unit` | ダミーテスト全 pass(WP・Docker 不要で完走すること) |
| `composer wp:start && composer test:integration` | ダミー統合テスト pass。`npx wp-env run cli wp core version` が 6.4 以上を出力 |
| GitHub Actions | `main` への PR で `qa.yml` の全ジョブ(lint / analyse / monorepo / unit = 3 PHP / integration = 3 PHP × 2 WP)が green |
| `composer validate --strict`(各パッケージ) | 全パッケージで valid |

### Done の定義(追加分)

- 文書 10 の受け入れ基準のうち「設定・コマンド定義」に関する項目がすべて ✔(テスト実体に関する項目は以降のマイルストーンで満たす)。
- ダミークラス・ダミーテストには `// M0 配線確認用。後続マイルストーンで実体に置換し削除する` のコメントが付いている。

**終了時に実行する検証**: 上表の全コマンド + `docs/acceptance/M0.md` のコミット。

---

## M1: Kernel / Container / Discovery

**目的**: FW の心臓部。ブート API・PSR-11 コンテナ・Attribute ベース自動探索・ファイルキャッシュを完成させ、「app/ に置くだけ」体験(DR-04)の土台を作る。

**開始時に読む文書**: 02 — 全文。01 の §ブート API。10 の §テスト規約(ユニット/統合の書き分け)。

### タスク分解

| ID | タスク | 内容 |
|---|---|---|
| M1-T1 | Exception 基盤 | `TMSWP\Core\Exception\` 配下の例外階層(文書 02 定義。最低限: 基底例外、`DiscoveryException`、`ContainerException` 系)。全パッケージ共通の例外規約をここで固定 |
| M1-T2 | Container | `Container\Container`(PSR-11 実装)。ユニットテスト: get/has、未登録 ID の例外(`NotFoundExceptionInterface`)、シングルトン/ファクトリ挙動(文書 02 仕様) |
| M1-T3 | Attribute マーカー群 | `Attribute\` 配下 11 クラス: `AsPostType` `AsTaxonomy` `AsUserRole` `AsModel` `AsHook` `AsOptionGroup` `MetaSchema` `OptionSchema` `UseMetaStorage` に加え、メソッドレベル(TARGET_METHOD)のフック宣言属性 `Action` / `Filter`(フック名 + `priority:` のみ。acceptedArgs の属性引数は持たない = 文書 02)。シグネチャは各担当文書(02/03/04)の正準定義を転記。**マーカー定義のみ**で、処理系(ハンドラ)は各層のマイルストーンで実装 |
| M1-T4 | KernelConfig | `Kernel\KernelConfig`(`theme()` / `plugin()` 名前付きコンストラクタ、appDir / appNamespace / キャッシュパス等)。テーマ/プラグイン両モードの差異(ベース URL・パス・有効化フックの有無)をここに閉じ込める |
| M1-T5 | Discovery | `Kernel\Discovery`: app/ クラスマップ走査 → Attribute 抽出(クラス属性に加え、`#[AsHook]` クラスのメソッド属性 `#[Action]`/`#[Filter]` も同一 scan 内で収集し、acceptedArgs をメソッド引数数から導出)→ コンポーネント登録簿の構築。決定的ロード順(ファイルパス昇順 + 文書 02 の優先順位規則。メソッド属性レコードは (class, method, hook, priority) 複合キーで決定的ソートし DiscoveryResult のバイト単位一致を維持)、循環依存検出(検出時 `DiscoveryException`)、コア名前空間(`TMSWP\`)の走査除外、探索失敗(パースエラー・Attribute 引数不正)の明示エラーをユニットテストで固定 |
| M1-T6 | ComponentCache | `Kernel\ComponentCache`: 探索結果のファイルキャッシュ。スキーマにフックマップ(class / method / hook / priority / acceptedArgs)を含め、`SCHEMA_VERSION` は文書 02 の正準値(メソッド属性対応でインクリメント済み)に従う。無効化条件(ファイル増減・mtime 変化・FW バージョン変化・appNamespace 変化)を文書 02 の擬似コード通りに実装し、各無効化条件を 1 ケースずつユニットテストで固定。WP_DEBUG 時の挙動も文書 02 に従う |
| M1-T7 | Kernel::boot | `Kernel\Kernel::boot(KernelConfig)`: WP フックに整合した初期化(即時同期初期化の禁止、DR-04)。二重 boot 時・WP コンテキスト外実行時のエラー挙動。Container への自己登録 |
| M1-T8 | Hook 基底 + フック配線 | `Hook\Hook`(薄い基底)+ `#[Action]`/`#[Filter]` メソッド属性の配線(Discovery のフックマップ → scheduleAll がクロージャ登録: `add_filter($hook, fn(mixed ...$a) => $container->get($class)->{$method}(...$a), $priority, $acceptedArgs)`。acceptedArgs はメソッド引数数から自動導出され、Hook クラスは対応フック発火まで未ロード = 遅延性をユニットテストで固定)。`register()` は真に動的な登録(ループでフック名生成等)のみの例外手段として維持(文書 02 正準)。登録クロージャの解除 API は作らない(YAGNI)。神クラス化させない(WP フック登録の置き場以上の機能を持たせない) |
| M1-T9 | ブート統合テスト | fixture テーマ(`tests/fixtures/theme/`)と fixture プラグイン(`tests/fixtures/plugin/`)を用意し、wp-env 上で両モードのブート → `#[Action]`/`#[Filter]` メソッドのフック発火(および `register()` 例外経路の動的登録)までを検証。キャッシュ生成 → 2 回目ブートでキャッシュヒット → fixture 変更 → 再走査、の一連も統合テストで検証 |

依存: T1 → T2 → T3 → T4 → T5 → T6 → T7 → T8 → T9(直列。各タスクが前タスクの API を使う)。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `composer qa` | exit code 0 |
| `composer test:unit -- --filter Discovery` | 決定的順序・循環検出・コア名前空間除外・探索失敗エラーの各テストが pass |
| `composer test:unit -- --filter ComponentCache` | 全無効化条件のテストが pass |
| `composer test:integration` | fixture テーマ/プラグイン両方のブート統合テストが pass |
| `npx wp-env run cli wp eval 'var_export(has_action("init"));'` 相当の統合テスト | fixture の `#[Action('init')]` メソッドが init にフック済み(発火までフック対象の Hook クラスがロードされないことも assert) |
| 同一 fixture で 2 回ブート | 2 回目は走査が走らない(キャッシュヒットのカウンタ/ログを assert) |

### Done の定義(追加分)

- 文書 02 の受け入れ基準チェックリスト全項目 ✔。
- M0 のダミークラスのうち core パッケージ分を実体クラスで置換・削除済み。

**終了時に実行する検証**: 上表 + `docs/acceptance/M1.md`。

---

## M2: エンティティ層

**目的**: `#[AsPostType]` / `#[AsTaxonomy]` / `#[AsUserRole]` で「クラスを置くだけ」で CPT・タクソノミー・ロールが登録される体験を完成させる。

**開始時に読む文書**: 03 — 全文。02 の §Discovery 登録フロー(エンティティがどう拾われるか)。01 の §エンティティ定義正準形。

### タスク分解

| ID | タスク | 内容 |
|---|---|---|
| M2-T1 | PostTypeDefinition | `Post\PostTypeDefinition`(fluent ビルダ → `register_post_type` 引数配列)。en/ja ラベル自動生成テンプレート(DR-17: `labelLanguage` 設定、デフォルト en、テキストドメイン `tmswp`)。ラベル生成はユニットテストでスナップショット固定(en/ja 両方) |
| M2-T2 | PostType 基底 + 配線 | `Post\PostType` 基底、`AsPostType` ハンドラ(Discovery 登録簿 → init フックで `register_post_type`)。`definition()` 未実装・post_type 名重複時の明示エラー |
| M2-T3 | PostQuery | `Post\PostQuery`(文書 03 定義の型付きクエリ API) |
| M2-T4 | Taxonomy 一式 | `Tax\TaxonomyDefinition` / `Tax\Taxonomy` 基底 + `AsTaxonomy` ハンドラ / `Tax\TermQuery`。CPT との関連付け(object_type)の仕様は文書 03 に従う |
| M2-T5 | UserRole 一式 | `User\UserRoleDefinition` / `User\UserRole` 基底 + `AsUserRole` ハンドラ / `User\UserQuery`。ロールは register が冪等であること(毎リクエスト `add_role` しない)を文書 03 仕様の通りに実装 |
| M2-T6 | エンティティ統合テスト | wp-env 上: fixture の ProductPost / ProductCategoryTax / MemberRole がブート後に `post_type_exists` / `taxonomy_exists` / `wp_roles()->is_role` で確認できる。`has_archive`・rewrite・REST 公開等の代表パラメータが `get_post_type_object` に反映されている |

依存: T1 → T2 → T3、T4・T5 は T2 完了後に並行可 → T6。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `composer qa` | exit code 0 |
| `composer test:unit -- --filter Definition` | ラベル自動生成(ja/en)のスナップショットテスト pass |
| `composer test:integration` | fixture エンティティの登録確認テストが全 pass |
| 重複 post_type fixture の統合テスト | 文書 03 規定の例外が boot 時に送出される |

### Done の定義(追加分)

- 文書 03 の受け入れ基準チェックリスト全項目 ✔。
- 正準形コード(`#[AsPostType] final class ProductPost extends PostType { ... }`)が fixture としてそのまま動く。

**終了時に実行する検証**: 上表 + `docs/acceptance/M2.md`。

---

## M3: meta / option 層 + PHPStan 拡張

**目的**: `#[MetaSchema]` 属性付き定数 → `register_*_meta` 自動登録 → `MetaBag` 型付きアクセサ、`#[AsOptionGroup]`/`#[OptionSchema]` → `OptionRepository`、および FW 同梱 PHPStan 拡張による型ナローイング(DR-05)を完成させる。

**開始時に読む文書**: 04 — 全文。03 の §エンティティとメタの接続(`PostType::meta()`)。02 の §Attribute 定義。DR-18(nullセンチネル全廃・has/get 分離・エンティティ単位オプトイン)を再読。

### タスク分解

| ID | タスク | 内容 |
|---|---|---|
| M3-T1 | ストレージ抽象 | `Meta\ObjectType`(enum: Post/Term/User)、`Meta\MetaStorageInterface`(**has()/get() 分離**。null センチネル禁止)、`Meta\WpMetaStorage`。DefineMetaTrait の「継承文脈から暗黙に対象種別を推定する」旧設計を踏襲しないこと(ObjectType を明示で持つ)をユニットテストで固定 |
| M3-T2 | スキーマ登録 | `Meta\MetaSchemaRegistry`: `#[MetaSchema]` 定数の収集 → `register_post_meta()` / `register_term_meta()` / `register_meta('user', ...)` への自動登録(init フック)。ユーザーメタは `register_user_meta()` という関数が WP コアに存在しないため、必ず `register_meta('user', ...)` を用いる(文書 04 の登録仕様が正準)。型・default・showInRest のマッピングは文書 04 の対応表に従う |
| M3-T3 | MetaBag | `Meta\MetaBag`: `ProductPost::meta($post)->int(ProductPost::META_PRICE)` / `->set()` の型付きアクセサ。型不一致時・未定義キー時の挙動(文書 04 規定)をユニットテストで固定。頻出フィールドの明示アクセサ規約は rules/(M9)で文書化されるため、ここでは fixture に 1 例を置くのみ |
| M3-T4 | ストレージ差し替え + プレビュー | `#[UseMetaStorage]` によるエンティティ単位の明示オプトイン(DR-18: グローバルフィルタ禁止)。`Meta\PreviewMetaSupport`(オプトイン: プレビュー時メタ保存)。統合テスト: プレビュー保存 → プレビュー画面でメタが解決される |
| M3-T5 | option 層 | オプショングループ = backed enum + `Option\OptionAccess` trait(基底クラスなし)+ `AsOptionGroup` ハンドラ / `OptionSchema`(enum case 対象。`ReflectionEnumUnitCase::getAttributes()` 経由で収集 = 文書 04)/ `Option\OptionRepository` / `Option\OptionStorageInterface` / `Option\WpOptionStorage`。meta 層と対称の has/get 分離(宣言形は meta=定数+`#[MetaSchema]` / option=enumケース+`#[OptionSchema]` で異なる点は文書 04 の注記が正準)。キー重複・グループ外キー混入は言語機能が保証するため独自検査は実装しない。未宣言キーの読み取りは `OptionRepository::get()` 直接利用を正式経路とする。autoload 指定等は文書 04 に従う |
| M3-T6 | PHPStan 拡張 | tmswp/core 同梱の PHPStan 拡張: `MetaSchema` 定数のスキーマから `MetaBag` アクセサの戻り値型をナローイング。phpstan の `TypeInferenceTestCase` による fixture 駆動テストを必須とする(fixture: integer/string/bool/array 型 + default あり/なし)。拡張の対象は文書 04 が定義する範囲のみ(スコープを広げない) |
| M3-T7 | meta/option 統合テスト | wp-env 上: fixture エンティティの `register_post_meta` 登録確認(`registered_meta_key_exists`)、REST 経由の showInRest 確認、MetaBag の読み書き往復、OptionRepository の読み書き往復 |

依存: T1 → T2 → T3 → T4、T5 は T1 完了後に並行可、T6 は T3 完了後、T7 は最後。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `composer qa` | exit code 0 |
| `composer test:unit -- --filter MetaBag` | 型付きアクセサ・型不一致・未定義キーのテスト pass |
| `composer test:unit -- --filter TypeInference`(または文書 10 規定の拡張テスト起動方法) | PHPStan 拡張の型推論 fixture が全 pass |
| `composer analyse`(fixture の app/ コードを解析対象に含めた状態) | `ProductPost::meta($post)->int(...)` の戻り値が `int` と推論され、誤用 fixture(string を int として使う)がエラー検出される |
| `composer test:integration` | meta 登録確認・プレビューメタ・option 往復の各テスト pass |

### Done の定義(追加分)

- 文書 04 の受け入れ基準チェックリスト全項目 ✔。
- コードベース全体を grep して null センチネル(「null なら未設定とみなす」分岐)が meta/option 層に存在しないことを確認済み。

**終了時に実行する検証**: 上表 + `docs/acceptance/M3.md`。

---

## M4: Support / View / Log / ActionRouter

**目的**: 依存の少ないユーティリティ層と、テンプレート前処理の actions/ 規約(ActionRouter + View + NotFound + tms_var)を完成させる。以降の Form 層(Html 属性レンダラ)・拡張(Template fallback)の前提となる。

**開始時に読む文書**: 07 — 全文(特に actions/ 規約・ActionRouter::dispatch の擬似コード・戻り値契約)。01 の §設計原則(「WP 標準のテーマ構築手法を壊さない」が View の絶対制約)。10 の §3A(actions/ 規約検査: PHPStan カスタムルール + bin/tms-check)仕様。

### タスク分解

| ID | タスク | 内容 |
|---|---|---|
| M4-T1 | Support\Arr / Support\Str | 文書 07 が定義する実利用メソッドのみ(投機的メソッド追加禁止)。全メソッドにユニットテスト |
| M4-T2 | Support\Html | 属性レンダラ(実利用メソッドのみ。旧 Html 流暢ビルダは復活させない = DR-16)。エスケープ仕様(esc_attr 境界)をテストで固定 |
| M4-T3 | Log | `Log\SimpleLogger`(自前 PSR-3 v3 実装、依存ゼロ)/ `Log\LoggerRegistry` / `Log\Log` ファサード。ログ出力先・ローテーション仕様は文書 07 に従う。PSR-3 準拠をインターフェーステストで担保 |
| M4-T4 | View\Template | `View\Template::part()` / `render()`: WP の `get_template_part` 系と共存する薄いヘルパ + プラグイン同梱テンプレート fallback(テーマ側に同名があればテーマ優先)。`extract()` 不使用・グローバル汚染なし(DR-10: load_global 全廃)をテストで固定。統合テスト: テーマ override あり/なしの両解決順 |
| M4-T5 | View\ActionRouter + View + NotFound + tms_var | `View\View`(変数バッグ。`set`(マージ)/`get`(未定義キー+default=null時 WP_DEBUG 警告)/`has`/`reset`(@internal))、`View\NotFound`(空シグナル値)、グローバルヘルパ `tms_var()`(`helpers.php` で `View::get` へ委譲)、`View\ActionRouter`(`final`)。Kernel::boot がテーマ形態のとき ActionRouter を生成し `template_include`(priority 100)へ `dispatch` を登録(KernelConfig の `actions: bool = true` で無効化可能。actions ディレクトリ名は `'actions'` 固定。プラグイン形態では生成しない)。`dispatch` は文書 07 の擬似コードを逐語実装: 非文字列/空文字は素通し、子→親テーマ配下判定で相対パス取得(テーマ外は素通し)、`{テーマルート}/actions/{相対パス}` を子→親で探索(無ければ素通し)、static クロージャで include(可視変数=`$wp_query` のみ)、戻り値契約(array→`View::set` / `NotFound`→404 強制 / null・int(1)→no-op / それ以外→WP_DEBUG 警告)、404 強制は `$wp_query->set_404()` + 未送信時のみ `status_header(404)`+`nocache_headers()` + `get_404_template()`(不在時 `get_index_template()` フォールバック)、それ以外の全経路で `$template` を無加工返却。`dispatch` の各分岐(素通し・array→tms_var・notFound→404・型不正警告・$template 無加工)をユニットテストで固定 |
| M4-T6 | actions/ 規約検査(PHPStan ルール + bin/tms-check) | actions/ 規約の機械検査を 2 系統で実装(仕様は文書 10 §3A が正準)。(1) FW 同梱 PHPStan 拡張のカスタムルール `NoOutputRule`(actions/ 配下の出力構文)・`NoSet404Rule`(`$wp_query->set_404()` 直呼び)。スターター側の `phpstan-actions.neon`(level 0 = 型検査なし・規約検査のみ、paths: [actions])から適用され、PHPStan の `RuleTestCase` によるフィクスチャ駆動テストを必須とする(exit/die の「直前文が `wp_safe_redirect(...)` なら許可」は AST 兄弟ノード参照で判定)。(2) コア同梱・WP 非依存の素の PHP スクリプト `bin/tms-check`: 孤児 actions(対応する同名テンプレートが存在しない)の検査**のみ**を担う(ディレクトリ走査照合のためルール化せず軽量スクリプトに残置。vendor なしの単独実行性を維持)。検出時 exit 1。composer qa への統合(`tms:check` = PHPStan ルール + 孤児検査の合成)は M9 のスターター側 composer.json で行う(本タスクではルール本体・スクリプト本体とコア用 fixture テストを実装) |

依存: T1 → T2、T3 は独立で並行可、T4 は T1 完了後、T5 は T4 完了後(Kernel の boot・タイミング表=M1 に依存。Form 駆動の正準形=`actions/page-contact.php` の統合テストは M5 完了後に追記する)、T6 は T5 完了後(検査対象の actions/ 規約が確定してから)。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `composer qa` | exit code 0 |
| `composer test:unit -- --filter 'Arr\|Str\|Html\|Log'` | 全 pass |
| `composer test:unit -- --filter 'ActionRouter\|View'` | dispatch の素通し・array→View::set・notFound→404・型不正の WP_DEBUG 警告・`$template` 無加工返却、View の get/set(マージ)/has/未定義キー警告の各テスト pass |
| `composer test:integration` | Template の解決順(テーマ優先 → プラグイン fallback)テスト pass。actions ファイルを置いたテンプレートで `tms_var()` が値を返す。`actions/` を1つも置かないテーマが完全に素通る(`$template` が無加工)。`ActionRouter::notFound()` を返す actions で HTTP ステータス 404 + 404 テンプレートが描画される。ActionRouter がテーマ形態でのみ生成され REST/AJAX/プラグイン形態では `dispatch` が登録されない |
| `php bin/tms-check tests/fixtures/actions-bad` | 孤児 actions を検出し exit 1。健全な fixture では exit 0 |
| `composer test:unit -- --filter 'NoOutputRule\|NoSet404Rule'`(`RuleTestCase` のフィクスチャ駆動テスト) | echo を含む actions・`set_404()` 直呼びの fixture がエラー検出され、健全な fixture(`wp_safe_redirect` 直後の exit を含む)が pass |
| `grep -rn 'extract(' packages/core/src` | ヒット 0 件 |
| `grep -rn 'template_include\|template_redirect' packages/core/src` | ActionRouter(dispatch 登録・`$template` 無加工返却)以外でヒット 0 件 |

### Done の定義(追加分)

- 文書 07 の受け入れ基準チェックリスト全項目 ✔。
- `bin/tms-check`(孤児 actions 検査)が WP 関数・コア autoload に依存せず素の PHP CLI で単独実行できる(`php bin/tms-check <dir>` が vendor なしで動く)。出力・`set_404()` 検査は PHPStan ルール側(文書 10 §3A)に一本化されており、`bin/tms-check` に重複実装がない。

**終了時に実行する検証**: 上表 + `docs/acceptance/M4.md`。

---

## M5: Form 層

**目的**: FW 最大のサブシステム。Form ライフサイクル(バインド/CSRF/検証/状態遷移)、HMAC 署名付き確認画面、トークン付きファイル一時保管、純関数レンダラを完成させる(DR-07)。

**開始時に読む文書**: 05 — 全文(特に署名検証・一時ファイルの擬似コード)。07 の §Html(レンダラが依存)。01 の §フォーム正準形。

### タスク分解

| ID | タスク | 内容 |
|---|---|---|
| M5-T1 | Field / FormDefinition | `Form\Field` / `Form\FormDefinition`(フィールド定義 DSL)。`Form\FormStep` enum / `Form\FormResult`。定義の不変性(readonly)は文書 05 に従う |
| M5-T2 | Validator / Rule 群 | `Form\Validator` / `Form\ValidationContext` / `Form\Rule\RuleInterface` + 文書 05 が列挙する全 Rule。**Rule 1 つにつき正常系・異常系・境界値のユニットテスト**(全角スペース・マルチバイト・空配列等のエッジは文書 05 の仕様表に従う) |
| M5-T3 | Form ライフサイクル | `Form\Form::handle()`: バインド → CSRF(wp_nonce 自動埋込み・自動検証)→ 検証 → 状態遷移(Input/Confirm/Complete)。GET 初期表示・検証失敗時の再表示・二重送信の各分岐をユニットテストで固定 |
| M5-T4 | ConfirmToken | `Form\ConfirmToken`: 確認画面の署名付き hidden 再 POST(HMAC)。鍵導出・ペイロード正規化・検証手順は文書 05 の擬似コードを逐語実装。**改ざんテストベクタ**(値変更・フィールド追加・署名切詰め・期限切れ)が全て検証失敗となることをテストで固定 |
| M5-T5 | UploadedFile / TempFileStore | トークン付き一時保管。**ユーザ入力からのパス再構築の全面禁止**(トークン → サーバ側マップのみ)。パストラバーサル試行(`../`、絶対パス、null バイト)が全て拒否されるテスト。期限切れ一時ファイルの掃除仕様は文書 05 に従う |
| M5-T6 | FormHtml / FieldView | 純関数レンダラ(`$form->field('email')->input([...])` を echo する書き味)。出力 HTML のスナップショットテスト(エスケープ・属性合成・checked/selected 状態)。3 層コールバック合成は実装しない(DR-16) |
| M5-T7 | Form 統合テスト | wp-env 上で input → confirm → complete の全遷移を HTTP レベルで検証(CSRF 欠落 → 拒否、確認画面改ざん → 拒否、ファイル添付付き確認 → 完了で本保存、を含む) |

依存: T1 → T2 → T3 → T4 → T5 → T6 → T7(直列推奨。T6 は T3 完了後なら並行可)。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `composer qa` | exit code 0 |
| `composer test:unit -- --filter Rule` | 全 Rule のユニットテスト pass |
| `composer test:unit -- --filter ConfirmToken` | 改ざんテストベクタ全拒否を含め pass |
| `composer test:unit -- --filter TempFileStore` | パストラバーサル拒否テスト pass |
| `composer test:integration` | input→confirm→complete の E2E 遷移テスト pass |
| `grep -rn 'wp_verify_nonce' packages/core/src/Form` | Form ライフサイクル内の自動検証経路にのみ存在(テンプレート側に手動検証を要求していない) |

### Done の定義(追加分)

- 文書 05 の受け入れ基準チェックリスト全項目 ✔。
- 正準形コード(`$form = ContactForm::create(); $result = $form->handle();`)が fixture でそのまま動く。

**終了時に実行する検証**: 上表 + `docs/acceptance/M5.md`。

---

## M6: Model 層

**目的**: スキーマ宣言 → 自動マイグレーション(dbDelta)、全クエリ `wpdb->prepare` 経由のクエリビルダを完成させる(DR-08)。

**開始時に読む文書**: 06 — 全文(特に TableDefinition → dbDelta SQL 生成規則と Migrator の契機)。02 の §プラグイン有効化フック(KernelConfig::plugin 経由)。

### タスク分解

| ID | タスク | 内容 |
|---|---|---|
| M6-T1 | Connection | `Model\Connection`(wpdb ラッパ)。prefix 付与・charset collate の取得・実行ログ(Log 連携)を薄く提供。Reflection による wpdb 透過プロキシは実装しない(旧 FW の `__callStatic` 委譲の廃止) |
| M6-T2 | TableDefinition | `Model\TableDefinition`(fluent: `id()->string('email',191)->text('body')->datetime('created_at')` 等)→ **dbDelta 準拠 SQL の機械生成**(2 スペース・KEY 構文・行フォーマット等の dbDelta 癖は文書 06 の生成規則に閉じ込め、利用者に書かせない)。生成 SQL のスナップショットテスト |
| M6-T3 | QueryBuilder | `Model\QueryBuilder`: `where/orderByDesc/limit/get` 等(文書 06 の API 一覧)。**値が SQL 文字列に到達する経路はすべて `wpdb->prepare` を通る**ことを、生成 SQL の検証テスト(プレースホルダ化の確認)+ 注入試行 fixture(`' OR 1=1 --` 等が値として安全に扱われる)で固定。任意の軽量 DTO ハイドレーションはオプション機能として文書 06 の範囲のみ実装(フル ORM 禁止) |
| M6-T4 | Model 基底 + 配線 | `Model\Model` 基底 + `AsModel` ハンドラ(Discovery 登録)。`InquiryModel::query()` の静的入口(Container 経由の二層構成 = DR-09) |
| M6-T5 | Migrator | `Model\Migrator`: スキーマバージョン比較 → dbDelta 実行 → バージョン記録。契機は (a) プラグイン有効化フック / after_switch_theme、(b) WP-CLI `wp tmswp migrate`(文書 06 正準。`--force` / `--dry-run` オプションあり)、(c) `admin_init`(priority 5)でのハッシュ比較付き自動実行。**契機ごとのエラー方針は 2 種**(文書 06 §5 正準): `admin_init` 契機のみ `migrateAll()` を try/catch で非致死に捕捉し、`wp_admin_notice()`(notice-error。無条件)+ WP_DEBUG 時のみ `wp_trigger_error(E_USER_WARNING)` で報告する(ハッシュ未更新のため原因解消後の次回契機で自動リトライ)。有効化フック・after_switch_theme・WP-CLI 経路は例外伝播のまま。**毎リクエストの dbDelta 実行はしない**(ハッシュ一致時は即 return)。dbDelta 実行後に `DESCRIBE` で実スキーマと宣言の一致を照合し、不一致時は明示エラー(dbDelta の silent 失敗対策) |
| M6-T6 | Model 統合テスト | wp-env の実 MySQL 上で: 初回マイグレーション → CRUD 往復 → スキーマ定義にカラム追加 → 再マイグレーションで反映 → インデックス追加の反映、を一連で検証。dbDelta 非対応操作(カラム削除・リネーム)を宣言した場合の挙動が文書 06 の規定通りであることも検証 |

依存: T1 → T2 → T3 → T4 → T5 → T6。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `composer qa` | exit code 0 |
| `composer test:unit -- --filter TableDefinition` | dbDelta 準拠 SQL のスナップショットテスト pass |
| `composer test:unit -- --filter QueryBuilder` | プレースホルダ検証 + 注入 fixture テスト pass |
| `composer test:integration` | 実 MySQL でのマイグレーション/再マイグレーション/CRUD テスト pass |
| `npx wp-env run cli wp tmswp migrate --dry-run`(fixture プラグイン有効化状態) | 適用予定スキーマ差分が表示され exit 0(コマンド名・オプションは文書 06 正準) |
| マイグレーション失敗 fixture(不正スキーマ等)の統合テスト | `admin_init` 契機では致死せず管理画面が応答し、`wp_admin_notice()` の notice-error が出力される(WP_DEBUG 時は `wp_trigger_error` も発火)。WP-CLI 契機(`wp tmswp migrate`)では例外が伝播し exit code ≠ 0(文書 06 §5 のエラー方針 2 種) |
| `grep -rn '\$wpdb->query\|->get_results\|->get_row\|->get_var\|->get_col' packages/core/src/Model` | すべて prepare 済み SQL を渡す経路のみ(レビューで確認し PR に明記) |

### Done の定義(追加分)

- 文書 06 の受け入れ基準チェックリスト全項目 ✔。
- 正準形コード(`#[AsModel] final class InquiryModel extends Model { ... }` と `InquiryModel::query()->where(...)->get()`)が fixture でそのまま動く。

**終了時に実行する検証**: 上表 + `docs/acceptance/M6.md`。

---

## M7: 拡張 3 パッケージ

**目的**: コアに含めない付帯機能を composer 追加導入の拡張プラグインとして完成させる(DR-15)。acf-bridge は M8 で別建て。

**開始時に読む文書**: 08 — 全文。05(settings-page が Form 層ベース)、07(thumbnail が View/Support を利用)の各 §公開 API。

### タスク分解

| ID | タスク | 内容 |
|---|---|---|
| M7-T1 | tmswp/ja-toolkit | 日本語サイト対応(日本語スラッグ・全角スペース検索正規化・wptexturize 無効化)を**オプトインモジュール**として実装(DR-10: デフォルトで何もしない。モジュール単位の明示有効化)。汎用 WP クリーンアップはコード収載せず rules/hooks.md のレシピで提供。各モジュールに統合テスト |
| M7-T2 | tmswp/thumbnail(本体) | timthumb 全面置換: 署名付き URL + `WP_Image_Editor` によるリサイズ・キャッシュ。署名検証の擬似コード(文書 08)を逐語実装し、**改ざん URL(寸法変更・パス変更・署名切詰め)が全て 403 相当で拒否**されるテストを固定。`ext-gd` 依存はこのパッケージの composer.json にのみ置く(DR-14) |
| M7-T3 | tmswp/settings-page | Form 層ベースの設定画面構築(nonce 自動 = Form の CSRF 機構を利用)。OptionRepository(M3)への保存。統合テスト: 管理画面での表示 → 保存 → option 反映 |

依存: T1〜T3 は相互独立(**全タスク並行可**)。ただし T3 は M5・M3 完了が前提。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `composer qa` | exit code 0(全拡張パッケージ含む) |
| `composer test:unit -- --filter Thumbnail` | 署名検証・改ざん拒否テスト pass |
| `composer test:integration` | ja-toolkit 各モジュールのオプトイン有効化テスト、settings-page の保存往復テスト、thumbnail の実画像リサイズテスト pass |
| `composer why ext-gd`(packages/core) | core は ext-gd に依存していない(thumbnail のみが依存) |
| ja-toolkit を require しただけの状態の統合テスト | いかなるフック改変も発生しない(オプトイン前は無作用) |

### Done の定義(追加分)

- 文書 08 の受け入れ基準チェックリスト全項目 ✔。
- 各拡張がコアと独立に `composer require` 可能(パッケージ単体の `composer validate --strict` と依存解決を確認)。

**終了時に実行する検証**: 上表 + `docs/acceptance/M7.md`。

---

## M8: acf-bridge

**目的**: acf-json を真実源とする型付きアクセサ生成・AcfMetaStorage・ドリフト検知を完成させる(DR-06)。

**開始時に読む文書**: 09 — 全文(特に return_format → PHP 型のマッピング表と生成テンプレート)。04 の §MetaStorageInterface / §UseMetaStorage。

### タスク分解

| ID | タスク | 内容 |
|---|---|---|
| M8-T1 | acf-json パーサ + 型マッピング | acf-json の field group JSON を読み、フィールド型 + return_format → PHP 型(repeater は PHPDoc array shape)へ解決する内部モデル。**ACF プラグイン本体なしで動く**(純粋な JSON 変換として実装し、ユニットテストのみで検証可能にする)。未知フィールド型の扱いは文書 09 の規定に従う |
| M8-T2 | コードジェネレータ + WP-CLI | `app/Acf/*Fields.php`(`@generated` ヘッダ付き)を生成する `wp tmswp acf sync`。生成は決定的(同一入力 → バイト一致出力)。固定 fixture(代表全フィールド型 + repeater + ネスト)からの期待出力をリポジトリにコミットし、スナップショット比較 |
| M8-T3 | AcfMetaStorage + 重複検知 | `MetaStorageInterface` 実装として `get_field`/`update_field` へ委譲。ACF 管理下フィールドは `register_*_meta` を発行しない(MetaSchemaRegistry との連携仕様は文書 09)。**PHP 手書き `#[MetaSchema]` 宣言と acf-json の同一キー重複は boot 時エラー**(テストで固定) |
| M8-T4 | ドリフト検知 CI | acf-json と生成済みクラスの乖離を検知する CI ステップ(`wp tmswp acf check` — 文書 09 正準。差分があれば exit 1)。GitHub Actions の qa ワークフローに組み込み、starter/fixture で動作確認 |

依存: T1 → T2 → T3 → T4。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `composer qa` | exit code 0 |
| `composer test:unit -- --filter AcfTypeMap` | 型マッピング表(文書 09)の全行に対応するユニットテスト pass(ACF 本体不要で完走) |
| fixture acf-json に対するジェネレータ実行(`wp tmswp acf sync` 相当。fixture の与え方は独自 CLI オプションを創作せず、文書 10 §1.2 の規定どおり `packages/acf-bridge/tests/` のフィクスチャ駆動テストとして実装)→ 生成出力を `tests/fixtures/expected-app/Acf` の期待値と比較 | 固定 fixture から期待クラスが生成され **diff ゼロ**(バイト一致) |
| 同テストを 2 回連続実行 | 2 回目も diff ゼロ(生成の決定性) |
| 重複キー fixture(PHP 宣言 + acf-json 同一キー)の統合テスト | boot 時に文書 09 規定の例外 |
| CI(ドリフト fixture を意図的に変更した検証ブランチ) | drift-check ステップ(`wp tmswp acf check`)が fail する |

### Done の定義(追加分)

- 文書 09 の受け入れ基準チェックリスト全項目 ✔。
- packages/core の composer.json・コードに ACF への言及が一切ないこと(`grep -rin 'acf' packages/core/src` が 0 件)を確認済み。

**終了時に実行する検証**: 上表 + `docs/acceptance/M8.md`。

---

## M9: スターター + AI 資料

**目的**: `composer create-project` で始められるスターターと、AI が自立実装するための AGENTS.md / rules/ を完成させる(DR-13)。

**開始時に読む文書**: 11 — 全文。01 の §ブート API。各層文書(03〜08)の「利用例(app側コード)」節(rules/ のレシピの正準ソース)。

### タスク分解

| ID | タスク | 内容 |
|---|---|---|
| M9-T1 | starter-theme | tmswp/starter-theme: functions.php(正準ブート)、app/ スケルトン(名前空間 `App\`)、WP 標準テンプレート構成(index.php / single.php 等 — **特殊処理なしの素の WP テーマであること**)、composer.json(create-project 対応)、テーマ側 `composer qa` 設定 |
| M9-T2 | starter-plugin | tmswp/starter-plugin: プラグイン本体ファイル(正準ブート + 有効化フック → Migrator 接続)、app/ スケルトン、composer.json |
| M9-T3 | AGENTS.md + rules/ | リポジトリおよびスターター同梱の AI 資料: AGENTS.md(FW 全体の規約・コマンド・禁止事項)、rules/(機能別レシピ: CPT 追加・メタ追加・フォーム作成・独自テーブル・ACF 利用指針・外部プラグイン利用指針(コア非依存の app/ 側実装ガイド)等、文書 11 の目次に従う)。各レシピのコード例は**実際に fixture でコンパイル・実行検証したもののみ**載せる |
| M9-T4 | create-project 検証 | ローカル path リポジトリ経由で `composer create-project tmswp/starter-theme` / `starter-plugin` を実行し、生成物が wp-env 上でブート・`composer qa` まで通ることを検証する CI ジョブを追加 |

依存: T1・T2 並行可 → T3 → T4。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `composer create-project tmswp/starter-theme my-theme --repository='{"type":"path","url":"starters/theme"}'`(CI では path、リリース後は Packagist) | 生成成功。`my-theme` 内で `composer install && composer qa` が exit 0 |
| 同上(starter-plugin) | 同様に exit 0 |
| wp-env にスターターテーマを配置して有効化 | フロントが 200 を返し、サンプル CPT がブートされる(統合テスト) |
| rules/ 内コード例の抽出テスト(文書 11 規定の方式) | 全コード例が lint + analyse を通過する |
| AGENTS.md | 文書 11 のチェックリスト(必須セクションの網羅)を満たす |

### Done の定義(追加分)

- 文書 11 の受け入れ基準チェックリスト全項目 ✔。
- スターターの app/ スケルトンが M1〜M8 の正準形 API のみを使用している(独自 API の創作なし)。

**終了時に実行する検証**: 上表 + `docs/acceptance/M9.md`。

---

## M10: ドッグフーディング + リリース

**目的**: starter-theme を使って「実案件相当」のサンプルサイトを構築し、FW 全機能を実利用の文脈で通し検証する。全文書の受け入れ基準を最終確認し、v0.1.0 をリリースする。

**開始時に読む文書**: 全文書の「受け入れ基準」節(01〜11)。`docs/acceptance/M0〜M9.md`(既存証跡)。`docs/RELEASING.md`。

### タスク分解

| ID | タスク | 内容 |
|---|---|---|
| M10-T1 | サンプルサイト構築 | `examples/sample-site/`(または文書 11 規定の置き場)に starter-theme から `create-project` した実サンプルを構築する。必須実装: 製品 CPT + タクソノミー + `#[MetaSchema]` メタ(一覧・詳細テンプレート)、確認画面付きお問い合わせフォーム(ファイル添付あり、送信内容を Model の独自テーブルへ保存)、thumbnail で一覧サムネイル、settings-page でサイト設定、ja-toolkit の 1 モジュール有効化、acf-bridge は acf-json fixture での sync 動作確認。構築は **AGENTS.md / rules/ のみを参照して行い**(設計文書を見ない)、資料の不足・誤りを発見したら M9 成果物への修正 PR を出す |
| M10-T2 | 全受け入れ基準の通し検証 | 文書 01〜11 の全受け入れ基準チェックリストを `main` の最新状態で再実行し、`docs/acceptance/FINAL.md` に統合記録する。CI 全マトリクス(PHP 8.2/8.3/8.4 × WP 6.4/latest)の green を確認。✘ 項目は修正タスクを起こして解消するまでリリースしない |
| M10-T3 | リリース | split 先 read-only リポジトリの作成、split ワークフローの実地検証(dry-run → 本実行)、`v0.1.0` タグ、Packagist 登録(全パッケージ + スターター)、`composer create-project tmswp/starter-theme`(Packagist 経由)の最終疎通確認。CHANGELOG.md 初版 |

依存: T1 → T2 → T3。

### 受け入れ基準

| コマンド | 期待結果 |
|---|---|
| `npx wp-env start`(sample-site 構成) | サンプルサイトが起動し、トップ・製品一覧・製品詳細・問い合わせフォームの各ページが 200 |
| フォーム E2E(統合テスト or 文書 10 規定の E2E 手段) | input → confirm(添付あり)→ complete → 独自テーブルに 1 行保存、まで通る |
| `docs/acceptance/FINAL.md` | 文書 01〜11 の全チェックリスト項目が ✔ |
| `git tag v0.1.0 && git push --tags` → split ワークフロー | 全 split 先リポジトリに v0.1.0 が伝播 |
| `composer create-project tmswp/starter-theme`(Packagist) | 外部環境で成功 |

### Done の定義(追加分)

- M10-T1 で発見した AI 資料の不備がすべて修正 PR としてマージ済み(発見 0 件の場合はその旨を記録)。
- プロジェクト全体として §3.3 の共通 Done をすべて満たし、v0.1.0 が公開されている。

**終了時に実行する検証**: 上表 + `docs/acceptance/FINAL.md`。

---

# 5. 全体リスク表と対策

| # | リスク | 影響 | 確率 | 主対策(予防 / 検知 / 発生時) |
|---|---|---|---|---|
| R1 | **dbDelta の癖**(2 スペース等の厳格フォーマット、カラム削除・リネーム不可、型変更の silent 失敗) | M6 でマイグレーションが「成功したように見えて反映されない」 | 高 | 予防: dbDelta 準拠 SQL は TableDefinition が機械生成し人間/LLM に書かせない(M6-T2)。検知: マイグレーション後に `DESCRIBE` で宣言と実スキーマを照合し不一致を明示エラー(M6-T5)、実 MySQL での統合テスト必須(M6-T6)。発生時: 生成規則のスナップショットテストに失敗ケースを追加して回帰固定 |
| R2 | **wp-env CI の不安定さ**(Docker 起動フレーク、イメージ pull 遅延、ポート競合) | 統合テストが間欠的に red になり main の green 維持が崩れる | 高 | 予防: ユニットテストを WP 非依存(Brain Monkey)で分離し常時高速 green を確保(M0-T5)、Docker/composer/npm キャッシュ + 起動リトライ + タイムアウト(M0-T7)。検知: 失敗ログから「環境起因 / コード起因」を判別する手順を CI ジョブのサマリに出す。発生時: 環境起因なら re-run(最大 2 回)、3 回連続環境起因失敗は Issue 化してランナー構成を見直す。恒常化する場合は統合テストを MySQL サービスコンテナ + wp-phpunit 直接実行へ切替える(文書 10 と要調整) |
| R3 | **PHPStan level max・baseline なしの到達難度**(WP 関数のスタブ不足、フックコールバックの型、wpdb の戻り値型) | analyse が通らず開発が停滞、または ignore が乱発される | 中 | 予防: 最初(M0)から max で開始し「後から上げる」を禁止、szepeviktor/phpstan-wordpress + 必要スタブを M0-T4 で整備、WP 関数呼び出しを薄い境界に寄せる規約(共通規約)で解析面を縮小。検知: `@phpstan-ignore` 件数を PR ごとに報告(§2.5)。発生時: ignore は理由必須の行単位のみ。特定 WP API の型問題はスタブ追加で解決し ignore で逃げない |
| R4 | **PHPStan 独自拡張(MetaBag 型ナローイング)の開発難度** | M3-T6 が長期化、または誤った型推論で利用者を誤導 | 中 | 予防: 拡張の機能範囲を文書 04 の定義(属性付き定数 → アクセサ戻り値型)に厳格限定、`TypeInferenceTestCase` の fixture 駆動で開発。検知: 誤用 fixture(負のテスト)を受け入れ基準に含める(M3 受け入れ基準)。発生時: 推論不能ケースは「拡張なしでも MetaBag の宣言型で安全側に倒れる」設計(文書 04)のため、fixture を追加して段階的に対応 |
| R5 | **Discovery キャッシュの無効化バグ**(ファイル変更が反映されない / 本番で毎回走査) | 開発体験の毀損・本番性能劣化・「置いたのに動かない」 | 中 | 予防: 無効化条件を文書 02 の擬似コードで確定済み、条件ごとのユニットテスト必須(M1-T6)。検知: キャッシュヒット/ミスのカウンタを Log に出し統合テストで assert(M1 受け入れ基準)。発生時: 明示的キャッシュ再生成手段(文書 02 定義)を案内し、無効化条件のテストを追加 |
| R6 | **HMAC 確認画面・一時ファイルのセキュリティ欠陥**(鍵管理不備、リプレイ、パストラバーサル) | 脆弱性として致命的 | 低〜中 | 予防: 署名・検証・パス解決の手順は文書 05 の擬似コードを逐語実装(創作禁止)、改ざん/トラバーサルの負のテストベクタを受け入れ基準に組込み(M5-T4/T5)。検知: M10 の通し検証で E2E 再確認。発生時: 該当テストベクタを追加し、修正を最優先タスクとして割込み実施 |
| R7 | **monorepo split 配布の運用事故**(タグが split 先に伝播しない、autoload 設定の齟齬) | composer require 利用者が壊れる | 中 | 予防: split ワークフローを M0-T8 で先行整備し dry-run 可能に、`composer validate --strict` を全パッケージ受け入れ基準に含める。検知: M10-T3 で実地検証してからリリース。発生時: `docs/RELEASING.md` の手順を更新し、リリースは常に手順書経由で実施 |
| R8 | **ACF 依存の検証制約**(ACF PRO は有償で CI 導入が難しい) | M8 の統合検証が不完全になる | 中 | 予防: ジェネレータと型マッピングを「acf-json → PHP の純粋変換」として ACF 本体非依存に設計(M8-T1。文書 09 と整合済み)。検知: AcfMetaStorage の get_field/update_field 境界は委譲インターフェースをモックしてユニット検証、固定 fixture スナップショットで生成物を担保。発生時: 実 ACF での動作確認は人間オーナーのローカル環境で実施する手順を `docs/acceptance/M8.md` に明記し、未実施項目として残さない |
| R9 | **WP 6.4 と latest の API 差異 / PHP 8.4 の非推奨警告**(implicit nullable 等) | 特定マトリクスのみ fail | 中 | 予防: CI マトリクスを M0 から全組合せで回す(差異の早期検出)、php-cs-fixer で nullable 型の明示を強制。検知: マトリクス別の失敗を CI が分離報告。発生時: 下限(WP 6.4 / PHP 8.2)を壊さない方向で修正し、どうしても分岐が必要な箇所は文書 01 の互換方針に従い Issue で記録 |
| R10 | **LLM 実装ドリフト**(設計文書からの逸脱、API の創作、文書間不整合の独断補完) | 設計と実装の乖離が蓄積し後続マイルストーンが破綻 | 高 | 予防: シグネチャは文書から転記(§2.2)、公開 API の新設禁止(§2.2)、不整合は中断 + エスカレーション(§2.4)。検知: PR 必須記載の「逸脱なし」宣言、マイルストーン終了時のチェックリスト実機検証(§2.3)、M10-T1 で資料のみ参照のドッグフーディング。発生時: 乖離発見分は修正タスク化し、原因(文書の曖昧さ / 実装の独断)を Issue に記録して再発防止 |
| R11 | **Form 層の複雑性過小評価**(確認画面 + ファイル + 状態遷移の組合せ爆発) | M5 が長期化しレーンB が遅延 | 中 | 予防: M5 を 7 タスクに分割し直列依存を明示、レンダラ(T6)をライフサイクル(T3)と分離して並行可能に。検知: タスク単位の受け入れ基準で進捗を計測。発生時: M6(レーンC)・M2/M3(レーンA)は独立して進められるため全体は停止しない(§3.2) |
| R12 | **スコープクリープ**(投機的機能・便利メソッドの追加) | コードベース肥大、シンプル・イズ・ベスト原則(要件3)の毀損 | 中 | 予防: Support は「実利用メソッドのみ」(M4-T1/T2)、フル ORM 禁止(M6-T3)、DR-16 の廃止リストを実装対象外として明記。検知: PR レビュー観点に「設計文書にない公開 API はないか」を含める。発生時: 該当 API を `@internal` 化または削除する修正 PR |

---

# 6. 本書自体の受け入れ基準

実装開始前のレビュー時に確認する。

- [ ] M0〜M10 のすべてに、参照文書・タスク分解(1 タスク = 1 PR)・依存関係・受け入れ基準(コマンド + 期待結果)・Done の定義が記載されている。
- [ ] 依存グラフ(§3.2)に循環がなく、並行レーンの割当が文書間依存(settings-page→Form 等)と矛盾しない。
- [ ] 各マイルストーンの受け入れ基準コマンドが、文書 10 §1 の正準スクリプト名(`composer lint` / `fix` / `analyse` / `test:unit` / `test:coverage` / `test:integration` / `test` / `qa:fast` / `qa` / `wp:start` / `wp:stop`)および各文書の WP-CLI コマンド定義(`wp tmswp migrate` = 文書 06、`wp tmswp thumb *` = 文書 08、`wp tmswp acf sync|check` = 文書 09)と grep 突合済みで一致している。WP 非依存のユニットテストには `composer test` ではなく `composer test:unit` を用いる(文書 10 で `test` は統合テストを含む合成スクリプト)。
- [ ] リスク表が dbDelta・wp-env CI・PHPStan max・split 配布・ACF 検証制約・LLM ドリフトを網羅している。
- [ ] 本書のどの記述も、確定済み設計判断 DR-01〜DR-20 と矛盾しない。

検証コマンド(本書はドキュメントのため静的確認のみ):

```
# マイルストーン節の網羅確認
grep -c '^## M' docs/tmswp/12-implementation-plan.md   # → 11 (M0〜M10)
# 受け入れ基準節の網羅確認
grep -c '^### 受け入れ基準' docs/tmswp/12-implementation-plan.md   # → 11
```
