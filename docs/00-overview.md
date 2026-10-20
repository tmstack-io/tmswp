# 00. TMStack WordPress Framework — 全体概要

## 目的

本書は TMStack WordPress Framework(以下 TMSWP)設計書群の起点文書である。以下を定義する。

1. 本FWを新規構築する背景(旧FW iwf2b の価値と課題)
2. ゴールと非ゴール(作るもの・意図的に作らないもの)
3. 全文書を貫く設計原則 5箇条
4. 確定済み設計判断 DR-01〜DR-20 の全表(各文書はこれに従い、再議論しない)
5. 文書マップ(01〜12 の読書順と依存関係)
6. 用語集(全文書で共通使用する正準用語)

読者は「この設計書群だけを渡されて TMSWP をゼロから実装する LLM」である。本書は実装対象の API を定義しない。API の正準定義は各担当文書(01〜11)が持つ。本書と各文書が矛盾する場合、個別 API の詳細は担当文書が優先し、設計原則・DR・用語は本書が優先する。

## スコープ(対象パッケージ)

全パッケージ横断(tmswp/core、tmswp/thumbnail、tmswp/settings-page、tmswp/acf-bridge、tmswp/ja-toolkit、tmswp/starter-theme、tmswp/starter-plugin)。

## 依存文書

なし(本書が読書順の起点)。

---

## 1. 背景 — 旧 iwf2b の価値と課題

TMSWP は、開発者が約10年・15プロジェクト(テーマ11+プラグイン4)で運用してきた自社FW iwf2b を、PHP 8.3〜8.5 / WordPress 7.0 以上を前提に完全に新規再構築するものである。コードの移植ではなく「仕様の再実装」であり、旧FWの価値(下記 1.1)を現代的な型システムで作り直し、負債(下記 1.2)を設計段階で排除する。

### 1.1 旧FWの価値(踏襲する資産)

実案件15プロジェクトの利用実態分析から、旧FWの本質的価値は次の4点に集約される。

| # | 資産 | 利用実態(定量) | TMSWP での扱い |
|---|------|------|------|
| 1 | **規約ベース起動**: 「WPエンティティ1種=1クラスを app/ に置くだけ」で Loader が自動発見・初期化。composer=vendor/、ビジネスロジック=app/、ブートは functions.php / プラグイン本体の数行という構成 | 全15プロジェクトが完全に同一構造。ブートコードは全件コピペ定型 | 踏襲。PHP 8 Attribute + クラスマップ走査 + ビルド時に生成する探索結果ファイルで再実装(DR-04)。「app/ に置くだけ」体験は維持 |
| 2 | **宣言的 CPT / Taxonomy / Role 定義**: static プロパティ宣言 → register_post_type / register_taxonomy 自動実行、ラベル自動生成、スコープ付きクエリヘルパ、テンプレートからの静的呼び出し(`BookPost::get_archive_link()` 等) | AbstractPost 継承 58クラス(11プロジェクト)、AbstractTax 継承 42クラス(11プロジェクト)、AbstractUser 継承 5クラス(3プロジェクト)。テンプレート静的呼び出しは T04 単体で100箇所超 | 踏襲(本FW最大の価値)。`PostTypeDefinition` 等の型付き定義オブジェクト + Attribute へモダン化(文書03) |
| 3 | **フォーム定義+バリデーション**: フィールド定義・値バインド・一括検証・エラー集約・確認画面遷移 | Field 参照 142(8プロジェクト)、RequiredRule 104 / MaxLengthRule 88 / ChoiceRule 50 参照。最大利用の T05 は会員登録3ステップ等30本のフォーム | 踏襲しつつ再設計。「ライフサイクルの持ち主」として Form オブジェクトを新設(DR-07、文書05) |
| 4 | **独自テーブル対応(Model)**: スキーマ宣言 → dbDelta 自動マイグレーション + 静的 CRUD ファサード | AbstractModel 継承 9クラス(5プロジェクト)。キューテーブル・翻訳グループ等 | 踏襲。クエリビルダ(常時 `wpdb->prepare`)でブラッシュアップ(DR-08、文書06) |

このほか、チャンネル式ログ(全15プロジェクトがブートで設定、`iwf_log()` 呼び出し300箇所/8プロジェクト)、テンプレートを汚さずロジックを分離する actions ファイルパターンの思想、プラグイン同梱テンプレートへのフォールバック付きパーシャル読込(View::element)も、形を変えて踏襲する。

### 1.2 旧FWの課題(設計段階で排除する負債)

| # | 課題 | 具体内容(一次資料で確認済み) | TMSWP での解消策 |
|---|------|------|------|
| 1 | **PHP 5.3 起源の古い言語水準** | CI マトリクスは PHP 5.3〜7.2 前提(travis-ci.org 終了で死亡)、composer 制約は `php>=7.4` だが本体は型宣言ゼロ・readonly / enum / Attribute 未使用。2025年の「PHP8対応」も3ファイルのみの部分対応 | PHP 8.3 下限。readonly / enum / named arguments / Attribute / 第一級 callable / 型付きクラス定数 / `#[\Override]` をフル活用(DR-02)。`declare(strict_types=1)` と全シグネチャ型宣言を必須化 |
| 2 | **神クラス AbstractHook** | WPクリーンアップ・日本語対応・View乗っ取り・ACF/CF7/Yoast 直結合(AbstractHook.php L60-119)を1クラスに内包。ACF 非使用の5プロジェクトは継承を回避し独自 Hook を再実装する分裂を招いた | 解体。日本語対応は tmswp/ja-toolkit のオプトインモジュールへ、汎用クリーンアップは rules/hooks.md のレシピ(app 側 Hook クラス)へ、ACF 連携は tmswp/acf-bridge へ、CF7/Yoast 連携は廃止(DR-10) |
| 3 | **マジックメソッド依存で静的解析不能** | メタ(`SomePost::MK_FOO($id)`)・オプション(`Settings::ON_FOO()`)・Model(wpdb 透過プロキシ)が `__callStatic` + 文字列 constant 解決。IDE 補完・PHPStan・AI 追跡がすべて不能 | マジックメソッド全面禁止。属性付き定数(#[MetaSchema])+型付きアクセサ(MetaBag)+FW同梱 PHPStan 拡張へ置換(DR-05) |
| 4 | **外部プラグイン制御コードのコア混入** | AbstractHook が ACF 同梱パス・CF7 フィルタ・Yoast フィルタを直結合。しかも ACF 差し替えフィルタは null センチネル + フィルタ名不一致バグ(meta: delete vs clear、option 側も同型)で実際には機能していなかった | コアから完全排除。MetaStorageInterface は has()/get() 分離、差し替えはエンティティ単位の明示オプトイン `#[UseMetaStorage]`(DR-06 / DR-18) |
| 5 | **API サーフェスの 37% が未使用** | FW 73クラス中 27クラスが全15プロジェクトで直接参照ゼロ(Html 流暢ビルダ約185行、Form/FormChoice ファクトリ、比較系 Rule 5種、CSVインポート、Debug/iwf_dump、Http の大半) | 実利用メソッドのみ収載(DR-14)。未使用機能は収載しない(DR-16)。付帯機能は拡張パッケージへ(DR-15) |
| 6 | **WP標準テンプレートフローの破壊** | template_include priority 100 で乗っ取り → actions ファイル読込 → `load_global()` が query_vars をグローバル展開 → `exit` で WP 標準ローダをバイパス | 完全廃止(DR-10)。ビューテンプレートに特殊処理を一切行わない(設計原則1) |
| 7 | **セキュリティ欠陥** | AbstractModel::make_conditions のエスケープなし文字列補間(SQLインジェクション、L364-377)、FieldUpload のユーザ入力からのパス再構築(WP_CONTENT_DIR 全域)、is_child_path の realpath 正規化なし(パストラバーサル検出不能)、timthumb(脆弱性の代名詞)前提、CSRF 手書き任意(検証漏れの実案件あり) | クエリは必ず `wpdb->prepare`(DR-08)、ファイルはトークン付き一時保管+パス再構築全面禁止、CSRF 自動埋込み・自動検証(DR-07)、timthumb は署名付き URL + WP_Image_Editor で全面置換(DR-15) |
| 8 | **テスト不能な構造と QA 体制の形骸化** | AbstractSingleton の `final private __construct` で DI/モック不可。Model/View/SettingsPage はテストゼロ。phpcs 設定は scaffold 未修正で形骸、PHPUnit はローカル実行不能 | PSR-11 コンテナ+静的ファサードの二層構成(DR-09)、PHPStan level max・baseline なし、2層テスト+GitHub Actions(DR-12) |
| 9 | **自動探索の性能・信頼性問題** | 毎リクエスト app/ 全 .php を Reflection で実ロード、コア自身も自己スキャン(コア同梱の具象クラスが意図せず初期化対象)、ロード順 OS 依存、間接循環依存で無限再帰、依存未解決はサイレント失敗 | 探索結果ファイルのビルド時生成(本番は走査しない)、コア名前空間は走査対象外、決定的ロード順・循環依存検出・探索失敗の明示エラーを必須化(DR-04) |
| 10 | **バージョン運用の不在** | git tag ゼロ。全15プロジェクトが `dev-master` 依存で実コミットは2020〜2025年の8世代に分散。`composer update` で最大5年分の破壊的変更を取り込む危険 | monorepo + read-only split 配布、SemVer + リリースタグ運用必須(DR-11) |

### 1.3 結論

旧FWの価値は「宣言とともに app/ に置けば動く」という DX にあり、課題はそれを支える実装(リフレクション全スキャン・シングルトン・マジックメソッド・神クラス)が2026年の言語水準・静的解析・AI 協働開発に耐えないことにある。よって TMSWP は旧 API との互換を一切持たない完全新規パッケージとして、同じ DX を型安全な手段で再実装する(DR-01 / DR-03)。

---

## 2. ゴールと非ゴール

### 2.1 ゴール(達成すべきこと)

1. **WP標準テーマ構築手法の完全尊重**: テンプレートは素の WordPress テンプレート階層のまま。FW はテンプレートから呼べる型付きヘルパを提供するだけで、レンダリングフローに介入しない。
2. **「app/ に置くだけ」DX の型安全な再実装**: Attribute 宣言+探索結果ファイルのビルド時生成による自動探索で、旧FWの規約ベース起動体験を維持しつつ、決定的・高速・静的解析可能にする。
3. **AI が自立実装できる FW**: コード自体が自己記述的(型・Attribute・final・strict_types)+ 規約が機械可読(AGENTS.md / rules/)+ 定型が雛形化(スターター)+ 検証が同一コマンド(composer qa)の4点で実現する。
4. **テーマ・プラグイン両対応**: 同一の Kernel ブート API(`KernelConfig::theme()` / `KernelConfig::plugin()`)で両者をサポートする。
5. **コアの最小化と拡張の分離**: コアの runtime 依存は psr/log ^3 と psr/container ^2 のみ。付帯機能は composer で追加導入する拡張パッケージ(DR-15)。
6. **フォーム・Model のモダン化**: フォームはライフサイクル集約+確認画面の改ざん防止(HMAC)+CSRF 自動化。Model はスキーマ宣言→自動マイグレーション+安全なクエリビルダ。

### 2.2 非ゴール(意図的に作らないもの)

1. **旧 iwf2b からの移行シム・互換レイヤ**: 作らない(DR-03)。旧FWは凍結し、既存案件はリニューアル時に新規実装で載せ替える。移行資料も作成しない(設計根拠としての旧FW言及のみ可)。
2. **ビューテンプレートエンジン**: Twig/Blade 的なものは作らない。View 層は `Template::part()` / `Template::render()` 相当の薄いヘルパのみ(プラグイン同梱テンプレートへの fallback 付き)。template_include の乗っ取り・グローバル変数展開・exit は一切行わない。
3. **フル ORM**: リレーション(hasMany 等)・自動ハイドレーション・Identity Map は実装しない(DR-08)。任意の軽量 DTO ハイドレーションをオプションで提供するに留める。
4. **外部プラグイン(ACF / CF7 / Yoast 等)の制御コードのコア収載**: 一切含めない。ACF 連携は tmswp/acf-bridge 拡張のみが担い、CF7 / Yoast 連携は提供しない。app/ 側で外部プラグインを利用する場合の指針は rules/ に整備する。
5. **認証・会員機能**: ロール宣言(UserRole)とユーザクエリまで。ログイン・登録フローは app/ 実装指針(rules/)の範囲とする。
6. **HTML 流暢ビルダ・HTTP クライアント・デバッグダンプ**: 旧FWで未使用が実証されたサーフェスは作らない(DR-16)。
7. **CSVインポート基盤**: 公式拡張として提供しない(DR-20)。データ投入の仕様(列定義・検証・保存先)は案件固有性が高く、必要な案件が app 側で WP-CLI コマンド等として実装する。
8. **WordPress 7.0 未満・PHP 8.3 未満のサポート**: 行わない(DR-02)。
9. **ブロックテーマ対応・ACF Blocks 向けコード生成**: ブロックテーマ(FSE)には対応しない。対象はクラシックテーマ(PHP テンプレート階層)である。acf-bridge は ACF Blocks 向けのコードを生成しない。
10. **マルチサイト対応**: マルチサイト(ネットワーク)環境には対応しない。全パッケージは単一サイトでの動作のみを仕様とし、マルチサイト向けの分岐(ネットワーク有効化・`switch_to_blog()`・ネットワーク共有テーブル等の考慮)を持たない。

---

## 3. 設計原則 5箇条

全文書・全実装はこの5原則に従う。原則間で衝突が生じた場合は番号が小さいものを優先する。

### 原則1: WP標準を壊さない(絶対原則)

ビューテンプレートに特殊処理を行わない。WordPress 標準のテーマ構築手法(テンプレート階層・The Loop・get_template_part・条件分岐タグ)をそのまま使えること。FW がテンプレートに要求するのは「型付きヘルパを呼べる」ことだけであり、テンプレートローダの乗っ取り・`exit`・`extract()`・グローバル変数の暗黙展開は全面禁止。初期化も WP のフックライフサイクル(`after_setup_theme` / `init` 等)に整合させ、即時同期初期化を行わない。

`exit` 禁止の例外は次の 2 つに限る。いずれもリダイレクト応答を確定させるためのもので、テンプレートローダの乗っ取りには当たらない。

- PRG(Post/Redirect/Get)でフォーム送信を処理した後の `wp_safe_redirect()` の直後
- 設定ページ(tmswp/settings-page)の保存処理の後の `wp_safe_redirect()` の直後

### 原則2: コアは外部プラグイン非依存

tmswp/core は WordPress コアと PSR インターフェース(psr/log ^3、psr/container ^2)以外に依存しない。ACF・CF7・Yoast 等の外部プラグインを検知・制御・前提とするコードをコアに置かない。外部プラグイン統合は専用拡張パッケージ(acf-bridge 等)が、コアの公開インターフェース(MetaStorageInterface 等)を実装する形でのみ行う。

### 原則3: シンプル・イズ・ベスト

実利用が実証された機能だけを、最小の API サーフェスで提供する(旧FW未使用37%の教訓)。投機的な汎用化・多層コールバック合成・設定可能性の先回り追加を行わない。1機能=1つの明白なやり方。迷ったら「作らない」を選ぶ。

### 原則4: AIファースト

コードは静的解析・LLM が完全に追跡できる形で書く。`__callStatic` 等のマジックメソッド・文字列ベースのディスパッチ・暗黙の規約を禁止し、Attribute・enum・定数・明示シグネチャで宣言する。規約は AGENTS.md / rules/ として機械可読に文書化し、人間・CI・AI が同一コマンド(composer test / lint / fix / analyse / qa)で検証できる。

### 原則5: 型安全

`declare(strict_types=1)` 必須、全シグネチャに型宣言、PHPStan level max・baseline なしを全パッケージで維持する。null センチネル(「null なら未設定」の暗黙規約)を全廃し、has()/get() 分離・Result オブジェクト・enum で状態を型として表現する。FW 同梱の PHPStan 拡張により、スキーマ宣言からアクセサ戻り値の型ナローイングまで担保する。

---

## 4. 確定済み設計判断(DR-01〜DR-20)

以下は確定事項である。各文書はこれを前提とし、再議論・変更しない。詳細仕様は「主担当文書」列の文書が定義する。

| ID | 領域 | 決定内容 | 主担当文書 |
|----|------|----------|----------|
| DR-01 | 名称・配布 | 正式名称は TMStack WordPress Framework(以下・略称: **TMSWP**)。TMStack はブランド名でありパッケージ名ではない(iwf2b ⇔ Inspire WordPress Framework 2β と同じ、ブランド名⇔コード接頭辞の関係)。composer vendor=`tmswp`、名前空間ルート=`TMSWP\`、グローバルヘルパ接頭辞=`tms_`。旧 iwf2b は凍結し、本FWは後方互換なしの完全新規パッケージ | 01 |
| DR-02 | 動作範囲 | PHP 8.3〜8.5 / WordPress 7.0 以上。readonly・enum・named arguments・Attribute・第一級 callable・型付きクラス定数・`#[\Override]` をフル活用。WordPress 7.0 未満向けの分岐は持たない | 全文書 |
| DR-03 | 移行 | 完全新規のみ。旧 iwf2b からの移行資料・互換シムは作成しない(設計根拠としての旧FW言及は可) | 全文書 |
| DR-04 | 自動探索 | PHP 8 Attribute(`#[AsPostType]` 等)+クラスマップ走査。走査結果は探索コマンド(`wp tmswp discover`)が探索結果ファイルとして生成してコミットし、本番はそのファイルだけを読む(走査は debug 時のみ)。「app/ に置くだけ」体験を維持。コア名前空間は走査対象外。初期化は WP フックに整合(即時同期初期化の禁止)。決定的ロード順・循環依存検出・探索失敗の明示エラーを必須とする | 02 |
| DR-05 | meta/option 定義 | 非 ACF 時は属性付き定数(`#[MetaSchema]`)+型付きアクセサ(MetaBag)。型指定は文字列でなく公開 enum `TMSWP\Core\Meta\MetaValueType` を属性引数へ直接渡す(不正値は言語/静的解析が実行前に拒否し、ブート時の型文字列検証は持たない)。register_post_meta / register_term_meta / register_user_meta へ自動登録。FW 同梱の PHPStan 拡張でスキーマから型ナローイング。頻出フィールドは明示アクセサメソッドを書く規約を rules/ に明記 | 04 |
| DR-06 | ACF 統合 | acf-json が存在する場合は acf-json が真実源。tmswp/acf-bridge のコードジェネレータが型付きアクセサクラス(app/Acf/*Fields.php、@generated)を生成。return_format から戻り値型を決定、repeater は PHPDoc array shape。AcfMetaStorage が読み書きを get_field / update_field へ委譲。ACF 管理下フィールドは register_*_meta を発行しない。CI でドリフト検知。PHP 手書き宣言との同一キー重複はエラー | 09 |
| DR-07 | フォーム | Form オブジェクトにライフサイクル(バインド/検証/CSRF/状態遷移)を集約。確認画面は署名付き hidden 再 POST(HMAC、改ざん防止)、ファイルはトークン付き一時保管(ユーザ入力からのパス再構築は全面禁止)。CSRF(wp_nonce)は自動埋込み・自動検証。レンダリングは純関数(echo 書き味は維持)。3層コールバック合成(旧 FormRenderer)は廃止 | 05 |
| DR-08 | 独自テーブル | スキーマ宣言→自動マイグレーション(dbDelta)は維持。クエリは必ず `wpdb->prepare` を通すクエリビルダ。フル ORM(リレーション・自動ハイドレーション)は実装しない(任意の軽量 DTO ハイドレーションはオプション)。マイグレーション契機は有効化フック+バージョン比較+WP-CLI | 06 |
| DR-09 | DI | PSR-11 準拠の軽量コンテナを内部に持ち、テンプレート向けには静的ファサード(例: `ProductPost::permalink()`)を提供する二層構成。旧 AbstractSingleton(final private __construct)は廃止しテスト可能な設計へ | 02 |
| DR-10 | Hook 解体 | 日本語サイト対応(日本語スラッグ・全角スペース検索正規化・wptexturize 無効化)は tmswp/ja-toolkit 公式拡張のオプトインモジュールへ。WP クリーンアップ(管理画面整理・wp_head 掃除等)は削除対象がテーマ毎に異なるため FW コードに収載せず、rules/hooks.md のレシピ(app 側 Hook クラス)で提供。ACF/CF7/Yoast 連携コードはコアから完全排除(ACF は acf-bridge のみ)。template_include 乗っ取り+exit+グローバル変数展開(load_global)は完全廃止 | 08 |
| DR-11 | リポジトリ | monorepo(packages/ 配下に core+各拡張)+Packagist へ read-only split 配布。SemVer+リリースタグ運用必須 | 01 |
| DR-12 | QA | PHPStan(szepeviktor/phpstan-wordpress)level max・baseline なし、php-cs-fixer(PER-CS 2.0+strict_types)、PHPUnit 11 で2層テスト(ユニット=WP非依存/Brain Monkey、統合=wp-env+wp-env が取得する WordPress のテストライブラリ(WP_TESTS_DIR))、GitHub Actions(ユニット層は PHP 8.3 / 8.5、統合層は PHP 8.3 / 8.5 × WP 7.0 / 最新版の 4 セル。統合層のテストライブラリは各セルの WP と同じ系列に揃える。PHPStan の phpVersion は 8.3〜8.5)。composer test/lint/fix/analyse/qa を人間・CI・AI が同一コマンドで実行。カバレッジはユニット層と統合層を合算して行単位で測る。WordPress に依存するクラスは、モックを多用したユニットテストではなく、実物の WordPress での統合テストで確かめる。合算の基準は v0.9.0(導入できる版)で 85% とし、それまでの版は版ごとの基準(`12-implementation-plan.md` §0.7)を版が上がるたびに引き上げる(仕様は `10-quality.md` §4.8)。※PHPUnit 11 が適用されるのはユニット層のみ。統合層は WordPress のテストライブラリが PHPUnit 9.6 上限のため、vendor-bin 隔離した PHPUnit 9.6 で実行する(確定仕様は QA 設計書 `10-quality.md` §4.2 に従う) | 10 |
| DR-13 | AI 資料 | AGENTS.md+rules/(機能別レシピ)+app/ スケルトン+composer create-project 対応スターター(starter-theme / starter-plugin) | 11 |
| DR-14 | コア収載 | Kernel/Container/Attribute 探索、PostType/Taxonomy/UserRole 宣言、meta/option 宣言層、フォーム+バリデーション、Model(独自テーブル)、Log(自前 PSR-3 v3、依存ゼロ)、Template::part 相当の薄いテンプレートヘルパ(View\Template。プラグイン同梱テンプレート fallback 付き)、Support(Arr/Str/Html 属性レンダラ=実利用メソッドのみ)。コアの runtime 依存は psr/log ^3 と psr/container ^2 のみ。ext-gd は thumbnail 拡張へ | 01 |
| DR-15 | 拡張パッケージ | tmswp/thumbnail(timthumb 全面置換=署名付き URL+WP_Image_Editor)、tmswp/settings-page(Form 層ベース、nonce 自動)、tmswp/acf-bridge、tmswp/ja-toolkit | 08, 09 |
| DR-16 | 廃止 | View 乗っ取り機構、Html 流暢ビルダ、Http、Debug(iwf_dump)、CF7/Yoast 連携、AbstractCsvImport の echo HTML ログ、旧 Form/FormChoice/FormRendererCollection | 全文書 |
| DR-17 | i18n | テキストドメイン=`tmswp`。ソース文字列は英語で書き、日本語は同梱翻訳ファイルで提供する。翻訳ファイルは WP-CLI で生成する(`composer i18n:build` が wp-env の cli コンテナで `wp i18n make-pot` → `update-po` → `make-mo` → `make-php` を実行)。ja の `.po` は手で管理し、生成した `.pot`・`.mo`・`.l10n.php`(WordPress の `WP_Translation_File_PHP` の形式)とともにコミットする。ロードコードは `load_textdomain()` に `.mo` のパスを渡すだけで、下限の WP 7.0 を含む全対応版で WordPress が同名の `.l10n.php` を優先して読む(仕様は `01-architecture.md` §7)。CPT/Tax ラベル自動生成は ja/en テンプレートを内蔵し、`KernelConfig` の `labelLanguage`(Kernel ごとの既定。デフォルト en)か定義側の `labelLanguage()` で切替(日本語サイトでは Kernel の既定を Ja にする) | 03 |
| DR-18 | ストレージ差し替え | 旧FWで機能していなかった ACF 差し替えフィルタの教訓: null センチネル全廃。MetaStorageInterface は has()/get() 分離。ストレージ差し替えはグローバルフィルタでなくエンティティ単位の明示オプトイン(`#[UseMetaStorage]`) | 04, 09 |
| DR-19 | テンプレート前処理 | テンプレート単位の前処理は、テンプレート同名ファイル規約 actions/{テンプレート相対パス}.php で行う(クラス形式のコントローラは持たない)。template_include(priority 100)でstaticクロージャinclude(可視変数=$wp_queryのみ)、戻り値契約 array→View変数バッグ / ActionRouter::notFound()→404 / null→no-op。$templateは常に無加工(404強制時のみWP標準のget_404_template()差し替え)。actions/common.phpは持たない(共通ロジックはapp/Hookまたはパーツ内モデルヘルパ直読み)。テーマ形態専用。型はテンプレート側@var注釈規約+未定義キーWP_DEBUG警告+静的検査(出力検査・set_404検査=FW同梱PHPStan拡張のカスタムルール、孤児actions検査=bin/tms-check軽量スクリプト)で担保 | 07 |
| DR-20 | CSVインポート | CSV インポート基盤は公式拡張に収載しない。列定義・検証・保存先といったデータ投入の仕様は案件固有性が高く、FW 側で汎用基盤として抽象化する価値が薄いため、必要な案件が app 側で実装する(WP-CLI コマンド+ league/csv 等の直接利用) | 08 |

---

## 5. 全体アーキテクチャ概観

### 5.1 リポジトリ構成(monorepo、リポジトリ名は仮に tmswp)

```
tmswp/
├── packages/
│   ├── core/           tmswp/core           TMSWP\Core\
│   ├── thumbnail/      tmswp/thumbnail      TMSWP\Thumbnail\
│   ├── settings-page/  tmswp/settings-page  TMSWP\SettingsPage\
│   ├── acf-bridge/     tmswp/acf-bridge     TMSWP\AcfBridge\
│   └── ja-toolkit/     tmswp/ja-toolkit     TMSWP\JaToolkit\
└── starters/
    ├── theme/          tmswp/starter-theme
    └── plugin/         tmswp/starter-plugin
```

各 packages/* は Packagist へ read-only split で個別配布する(DR-11)。拡張パッケージはすべて tmswp/core に依存し、拡張同士は相互依存しない。詳細は文書01。

### 5.2 コアのモジュールマップ(TMSWP\Core\ 配下、正準名)

| モジュール | 構成クラス | 担当文書 |
|----------|----------|---------|
| Kernel | `Kernel\Kernel`, `Kernel\KernelConfig`, `Kernel\Discovery`, `Kernel\DiscoveryFile`, `Kernel\DiscoverCommand` | 02 |
| Container | `Container\Container`(PSR-11 実装) | 02 |
| Attribute | `Attribute\AsPostType`, `AsTaxonomy`, `AsUserRole`, `AsModel`, `AsHook`, `AsOptionGroup`, `Action`(メソッドレベル), `Filter`(メソッドレベル), `MetaSchema`, `OptionSchema`, `UseMetaStorage`, `WithMetaRevisions` | 02(探索仕様)+ 各機能文書(意味論) |
| Post | `Post\PostType`(基底), `Post\PostTypeDefinition`, `Post\PostQuery` | 03 |
| Tax | `Tax\Taxonomy`(基底), `Tax\TaxonomyDefinition`, `Tax\TermQuery` | 03 |
| User | `User\UserRole`(基底), `User\UserRoleDefinition`, `User\UserQuery` | 03 |
| Meta | `Meta\MetaBag`, `Meta\ObjectType`(enum: Post/Term/User), `Meta\MetaValueType`(backed enum: スキーマ型指定の公開API), `Meta\MetaStorageInterface`, `Meta\WpMetaStorage`, `Meta\MetaSchemaRegistry`(`#[WithMetaRevisions]` によるプレビュー時メタ保存のオプトインを含む) | 04 |
| Option | `Option\OptionAccess`(trait: backed enum グループ用アクセサ), `Option\OptionRepository`, `Option\OptionStorageInterface`, `Option\WpOptionStorage` | 04 |
| Form | `Form\Form`, `Form\FormDefinition`, `Form\FormResult`, `Form\FormStep`(enum: Input/Confirm/Complete), `Form\Field`, `Form\FieldView`, `Form\FormHtml`(純関数レンダラ), `Form\Validator`, `Form\ValidationContext`, `Form\ConfirmToken`, `Form\UploadedFile`, `Form\TempFileStore`, `Form\Rule\RuleInterface` ほか Rule 群 | 05 |
| Model | `Model\Model`(基底), `Model\TableDefinition`, `Model\QueryBuilder`, `Model\Migrator`, `Model\Connection`(wpdb ラッパ) | 06 |
| Log | `Log\Log`(ファサード), `Log\SimpleLogger`, `Log\LoggerRegistry` | 07 |
| View | `View\Template`(part()/render()), `View\ActionRouter`(template_include ディスパッチャ), `View\View`(actions→テンプレート変数バッグ), `View\NotFound`(404シグナル) | 07(ActionRouter のboot時生成・登録は02) |
| Support | `Support\Arr`, `Support\Str`, `Support\Html` | 07 |
| Hook | `Hook\Hook`(薄い基底: app 側が WP フック登録をまとめる置き場), `Hook\HookInitializer` | 02 |
| Exception | `Exception\` 配下(全例外の名前空間) | 各文書 |

### 5.3 ブート API とライフサイクル(正準形)

テーマ `functions.php`:

```php
\TMSWP\Core\Kernel\Kernel::boot(
    \TMSWP\Core\Kernel\KernelConfig::theme(appDir: __DIR__ . '/app', appNamespace: 'App')
);
```

プラグイン本体:

```php
\TMSWP\Core\Kernel\Kernel::boot(
    \TMSWP\Core\Kernel\KernelConfig::plugin(file: __FILE__, appDir: __DIR__ . '/app', appNamespace: 'App')
);
```

アプリ側名前空間はデフォルト `App\`(スターター既定)だが KernelConfig で変更可能。`Kernel::boot()` は即時にコンポーネント初期化を行わず、WP フックライフサイクルに沿って Discovery → 登録 → 各コンポーネント初期化を進める(詳細タイミングは文書02が確定する)。

### 5.4 開発者体験の正準コード例

エンティティ定義(app/ に置くだけで登録される):

```php
#[AsPostType]
final class ProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0, showInRest: true)]
    public const META_PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')->label('製品')->labelLanguage(LabelLanguage::Ja)->hasArchive();
    }
}
```

メタ読み書き(テンプレート/ロジック共通):

```php
$price = ProductPost::meta($post)->int(ProductPost::META_PRICE);
ProductPost::meta($post)->set(ProductPost::META_PRICE, 1200);
// 頻出フィールドは明示アクセサを書く規約(rules/ に明記):
// public static function price(WP_Post|int $post): int
```

フォーム:

```php
$form = ContactForm::create();
$result = $form->handle();  // バインド+CSRF検証+検証+状態遷移
// $result->step は FormStep::Input | Confirm | Complete
// テンプレート側: $form->field('email')->input(['class' => 'form-input']) を echo
```

Model(独自テーブル):

```php
#[AsModel]
final class InquiryModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('inquiries')->id()->string('email', 191)->text('body')->datetime('created_at');
    }
}

InquiryModel::query()->where('email', $email)->orderByDesc('id')->limit(10)->get();
```

### 5.5 コーディング規約(全パッケージ共通)

- `declare(strict_types=1)` 必須 / PER-CS 2.0(PSR-12 の後継)/ クラスは final 優先
- 全シグネチャに型宣言。`__callStatic` 等のマジックメソッド全面禁止。`extract()` 禁止
- 例外は `TMSWP\Core\Exception\` 配下(各拡張は自パッケージの Exception 名前空間)
- WP 関数呼び出しはユニットテスト可能性のため薄い境界(Storage / Connection 等)に寄せる
- テキストドメインは `tmswp`(DR-17)。グローバルヘルパは `tms_` 接頭辞(DR-01)

### 5.6 エラー方針(全モジュール共通)

全パッケージの例外・ログ処理は次の表に従う。各担当文書が個々の失敗の扱いを定めるときは、この表のどの分類に当たるかに沿って決める。

| 分類 | 例 | 扱い |
|------|------|------|
| 設定の誤り | 属性・Definition オブジェクトの宣言の誤り、存在しないクラスの指定、不正な設定値 | debug の有無に関係なく、起動時(登録時)に必ず例外を投げる |
| 実行時のデータ取得失敗 | DB エラー、`WP_Error` の返却、ファイルの読み書き失敗、データの不整合(親の循環による深さの超過等) | debug 時は例外を投げる。本番はそのモジュールを起動した Kernel のロガー(`$kernel->logger()`)の `error()` に記録したうえで既定値を返す |
| データが無いこと | アイキャッチ未設定、メタ未保存、該当行なし | エラーにしない。正常な結果(`null`・空配列・`has()` が false 等)として返す |

- debug 時か本番かは、そのモジュールを起動した Kernel の `KernelConfig::$debug`(既定値は `WP_DEBUG` の真偽。文書02)で判定する。各モジュールが `WP_DEBUG` を直接参照して分岐することはしない。
- 本番で既定値を返す場合も、失敗を黙って捨てない。Kernel のロガーへの記録を省略してはならない。

---

## 6. 文書マップ(読書順と依存関係)

設計書は本書(00)を起点に以下の 01〜12 で構成する。番号順がそのまま推奨読書順である。実装順も原則この順(コア基盤 → コア機能 → 拡張 → QA/AI資料)に従い、版ごとの実装計画は文書12(ロードマップ)が定める。

| # | ファイル | 内容 | 対応 DR | 依存文書 |
|---|---------|------|---------|---------|
| 00 | `00-overview.md` | 全体概要(本書) | 全DR | なし |
| 01 | `01-architecture.md` | monorepo 構成、各パッケージの composer.json 仕様、依存関係、read-only split 配布、SemVer/リリース運用、ディレクトリレイアウト | DR-01, 02, 11, 14, 15 | 00 |
| 02 | `02-kernel.md` | Kernel / KernelConfig / Discovery / DiscoveryFile / Container(PSR-11)/ Attribute 探索仕様(探索結果ファイル・決定的ロード順・循環検出・WP フック整合)、Hook 基底と `#[Action]` / `#[Filter]` | DR-04, 09 | 00, 01 |
| 03 | `03-entities.md` | PostType / Taxonomy / UserRole の宣言層(Definition オブジェクト、PostQuery / TermQuery / UserQuery、ラベル自動生成 ja/en) | DR-17 | 00, 02 |
| 04 | `04-meta-options.md` | MetaSchema / MetaValueType / MetaBag / MetaSchemaRegistry / MetaStorageInterface / `#[WithMetaRevisions]`、オプショングループ(backed enum + OptionAccess trait)/ OptionRepository、PHPStan 型ナローイング拡張 | DR-05, 18 | 00, 02, 03 |
| 05 | `05-form.md` | Form ライフサイクル、FormDefinition / Field / Rule 群、確認画面(ConfirmToken / HMAC)、UploadedFile / TempFileStore、FormHtml 純関数レンダラ | DR-07 | 00, 02 |
| 06 | `06-model.md` | Model / TableDefinition / QueryBuilder / Migrator / Connection(マイグレーション契機、prepare 必須化、DTO ハイドレーションオプション) | DR-08 | 00, 02 |
| 07 | `07-support.md` | View\Template(part/render、プラグイン同梱 fallback)、ActionRouter / View 変数バッグ(actions 規約・dispatch 正準仕様)、Support\Arr / Str / Html、Log(PSR-3 v3 自前実装)の合冊 | DR-14, 16, 19 | 00, 02 |
| 08 | `08-extensions.md` | tmswp/thumbnail(署名付き URL 仕様含む)/ settings-page / ja-toolkit(日本語サイト対応のオプトインモジュール群)の3拡張 | DR-10, 15, 20 | 00, 01, 02, 05(settings-page が Form 層に依存) |
| 09 | `09-acf-bridge.md` | acf-json 真実源、コードジェネレータ、AcfMetaStorage、CI ドリフト検知、重複キーエラー | DR-06, 18 | 00, 02, 04 |
| 10 | `10-quality.md` | PHPStan / php-cs-fixer / PHPUnit 2層 / wp-env / GitHub Actions / composer スクリプト体系 | DR-12 | 00, 01 |
| 11 | `11-ai-docs.md` | AGENTS.md 仕様、rules/ レシピ体系、app/ スケルトン、starter-theme / starter-plugin | DR-13 | 00〜10(全文書の規約を参照する) |
| 12 | `12-implementation-plan.md` | ロードマップ(v0.1.0〜v0.9.0 の版構成・タスク・完了条件・受け入れ条件・依存関係) | 全DR(実装順序への写像) | 00〜11(全文書を参照する) |

依存関係の要点:

- **02(Kernel)が全機能文書の土台**。03〜07 のコンポーネント(エンティティ・メタ・フォーム・Model)はすべて 02 の Discovery / Container / Attribute 機構に登録される側であり、登録のされ方(Attribute → 初期化タイミング)は 02 が一元的に確定する。
- **04(Meta)と 09(acf-bridge)は DR-18 のコントラクト(MetaStorageInterface、`#[UseMetaStorage]`)で結合する**。インターフェース定義は 04 が持ち、09 はその実装者。09 が 04 の API を変更することはできない。
- **08 の settings-page は 05(Form)のクライアント**。Form 層の公開 API のみを使い、内部 API に依存しない。
- **11(AI 資料)は全文書の写像**。各文書の利用例・規約を rules/ レシピとスターターに落とし込むため、終盤に読む・書く。
- **12(ロードマップ)は全文書を版ごとの実装順序へ写像する**。最後に読む。

---

## 7. 用語集

全文書はここで定義した用語を同じ意味で使う。

| 用語 | 定義 |
|------|------|
| **TMSWP** | 本FWの略称(正式名称: TMStack WordPress Framework)。composer vendor 名 `tmswp`、名前空間ルート `TMSWP\`。TMStack はブランド名でありパッケージ名ではない。 |
| **コア(core)** | tmswp/core パッケージ。runtime 依存は psr/log ^3 と psr/container ^2 のみ。外部プラグイン制御コードを含まない。 |
| **拡張パッケージ(extension)** | コアとは別に composer で追加導入する公式パッケージ群(thumbnail / settings-page / acf-bridge / ja-toolkit)。すべて tmswp/core に依存し、相互には依存しない。 |
| **app 層** | 各テーマ・プラグインの `app/` ディレクトリ配下に置くプロジェクト固有コード(デフォルト名前空間 `App\`)。Discovery の走査対象。FW 本体(vendor/)と明確に分離される。 |
| **Kernel** | FW のブートストラップ装置(`TMSWP\Core\Kernel\Kernel`)。`Kernel::boot(KernelConfig)` の1呼び出しで起動し、WP フックライフサイクルに沿って Discovery → コンポーネント初期化を実行する。 |
| **KernelConfig** | ブート設定の値オブジェクト。`KernelConfig::theme()` / `KernelConfig::plugin()` の名前付きコンストラクタでテーマ/プラグイン文脈を表現する。 |
| **Discovery** | app/ のクラスマップを走査し、TMSWP Attribute(`#[AsPostType]` 等)が付与されたクラス(および `#[AsHook]` クラス内の `#[Action]` / `#[Filter]` メソッド属性)を発見・登録する機構。コア名前空間は走査対象外。走査結果は探索結果ファイルに生成され、本番はそれを読む。 |
| **探索結果ファイル** | Discovery の結果を探索コマンド `wp tmswp discover` が生成する PHP ファイル(テーマ・プラグインのルート直下の `tmswp-discovery.php`。`DiscoveryFile` が読み書きする)。コードと一緒にコミット・デプロイし、本番(debug=false)の boot はこれだけを読んで走査しない。無い・構成と合わない場合は起動時に例外。形式と検証は文書02が定義する。 |
| **Container** | PSR-11 準拠の軽量 DI コンテナ(`TMSWP\Core\Container\Container`)。FW 内部の依存解決に使う。テンプレート向けには静的ファサードを別途提供する(二層構成、DR-09)。 |
| **静的ファサード** | テンプレートから FQCN で呼べる静的 API(例: `ProductPost::permalink()`)。内部実装は Container 管理のインスタンスに委譲され、テスト時に差し替え可能。`__callStatic` は使わない。 |
| **Definition オブジェクト** | エンティティ登録パラメータを表す型付きビルダ(`PostTypeDefinition` / `TaxonomyDefinition` / `UserRoleDefinition` / `TableDefinition` / `FormDefinition`)。旧FWの static プロパティ羅列を置き換える。 |
| **MetaSchema** | メタキー定数に付与する Attribute(`#[MetaSchema(type: MetaValueType::Integer, default:, showInRest:)]`)。type 引数は MetaValueType enum で受ける(文字列指定は不可)。register_*_meta への自動登録と PHPStan 型ナローイングの情報源。 |
| **MetaValueType** | `TMSWP\Core\Meta\MetaValueType`。メタ/オプションのスキーマ型指定に使う公開 backed enum。`#[MetaSchema]` / `#[OptionSchema]` の type 引数へ enum ケースを直接渡す(PHP 8.1+ で enum ケースは定数式)。不正な型値は IDE・PHPStan・PHP 自体が実行前に拒否するため、ブート時の型文字列検証は存在しない。 |
| **MetaBag** | エンティティ単位の型付きメタアクセサ(`ProductPost::meta($post)->int(...)` / `->set(...)`)。ObjectType(Post/Term/User)を enum で明示し、旧FWの継承文脈依存の暗黙振り分けを廃する。 |
| **MetaStorageInterface** | メタ読み書きの差し替え点。has()/get() 分離で null センチネルを排除(DR-18)。標準実装は WpMetaStorage、ACF 実装は acf-bridge の AcfMetaStorage。 |
| **`#[UseMetaStorage]`** | エンティティクラス単位でストレージ実装を明示オプトインする Attribute。グローバルフィルタによる暗黙差し替えは存在しない。 |
| **`#[Action]` / `#[Filter]`** | `TMSWP\Core\Attribute\Action` / `TMSWP\Core\Attribute\Filter`。`#[AsHook]` クラスのメソッドに付与するメソッドレベル Attribute(TARGET_METHOD)で、WP フック登録を宣言的に行う(例: `#[Action('init', priority: 20)]`)。acceptedArgs はメソッド引数数から自動導出され、属性引数での手書きは不可。`register()` はループでフック名を生成する等、真に動的な登録のみの例外手段(第一級の書き方は本属性に一本化)。 |
| **OptionGroup(オプショングループ)** | オプショングループの宣言単位。backed enum + `Option\OptionAccess` trait で宣言する(例: `enum SiteOption: string { use OptionAccess; #[OptionSchema(...)] case ContactEmail = 'contact_email'; }`)。利用は `SiteOption::ContactEmail->string()` / `->set(...)`、グループ内キー一覧は `cases()`。キー重複・インスタンス化は言語仕様が禁止するため独自の重複検出・インスタンス化禁止機構は持たない。enum ケースに無い未宣言キー(他プラグインのオプション等)の読み取りは `OptionRepository::get()` を直接使う。 |
| **acf-bridge** | ACF 統合拡張(tmswp/acf-bridge)。acf-json を真実源とした型付きアクセサ生成(@generated)と AcfMetaStorage を提供する。ACF 関連コードはこのパッケージにのみ存在する。 |
| **acf-json 真実源** | ACF フィールド定義の正本は acf-json ディレクトリの JSON であり、PHP 側は生成物という原則(DR-06)。PHP 手書き宣言と同一キーが重複した場合はエラー。 |
| **Form ライフサイクル** | リクエスト値バインド → CSRF 検証 → バリデーション → 状態遷移(FormStep enum: Input/Confirm/Complete)の一連の流れ。`Form::handle()` が一括実行し FormResult を返す。 |
| **ConfirmToken** | 確認画面の改ざん防止トークン。検証済み入力を HMAC 署名付き hidden として再 POST する(DR-07)。 |
| **TempFileStore** | アップロードファイルのトークン付き一時保管。ユーザ入力からのファイルパス再構築は全面禁止。 |
| **FormHtml** | フォーム部品の純関数レンダラ(型+値+属性 → エスケープ済み HTML 文字列)。テンプレートでの echo 書き味を担保する。コールバック合成は持たない。 |
| **QueryBuilder** | Model 層の流暢クエリビルダ。生成 SQL は必ず `wpdb->prepare` を通す。 |
| **Migrator** | TableDefinition から dbDelta ベースで自動マイグレーションを行う機構。契機は有効化フック+バージョン比較+WP-CLI(毎リクエスト実行はしない)。 |
| **Template(View)** | `View\Template` の part()/render()。WP テンプレート階層を一切変更しない薄い読込ヘルパで、プラグイン同梱テンプレートへの fallback を持つ。 |
| **ActionRouter** | `View\ActionRouter`(final)。テーマ形態のテンプレート前処理ディスパッチャ。`Kernel::boot()` が `template_include`(priority 100)へ `dispatch` を登録する(DR-19)。確定済みテンプレートに対応する `actions/{テンプレート相対パス}.php` を子→親テーマの順に探索し、存在すれば static クロージャで include(可視変数=$wp_query のみ)。戻り値契約: `array`→View 変数バッグ / `ActionRouter::notFound()`→404強制 / `null`→no-op。`$template` は常に無加工で返す(404強制時のみ WP標準の `get_404_template()` 差し替え)。テンプレート乗っ取り・exit・グローバル変数展開は行わない(原則1)。フロントの本描画リクエストでのみ動作。旧 iwf2b の actions/ 規約の直接踏襲。 |
| **View(変数バッグ)** | `View\View`(final)。actions ファイル→ページテンプレート間の変数バッグ。actions が `return [...]`(array)した値を保持し、テンプレート側は `tms_var('key')` / `View::get('key')` で型付き取得する。`set()` はマージ(全置換でない)、未定義キーかつデフォルト未指定は WP_DEBUG 時のみ警告。パーツ(get_template_part)からの読み出しは禁止(追跡経路が2本になる)。`View\NotFound` は `ActionRouter::notFound()` 経由でのみ生成される空の404シグナル値。 |
| **actions 規約** | テーマルート直下 `actions/`(名前固定・リネーム不可)に置く、テンプレート同名のデータ準備ファイル群(`actions/page-contact.php` 等)。役割はデータ準備のみで、出力・フック登録・`$wp_query->set_404()` 直呼びは禁止(WP_DEBUG 検知 + 静的検査: 出力・set_404 は FW 同梱 PHPStan 拡張のカスタムルール、孤児 actions 検査は `bin/tms-check` 軽量スクリプト)。404 は必ず `return \TMSWP\Core\View\ActionRouter::notFound()` で表明。フォーム駆動・PRG リダイレクト(wp_safe_redirect + exit)は許可。`actions/common.php`(全テンプレート共通)は持たない(共通ロジックは `app/Hook` クラスまたはパーツ内のモデルヘルパ直読みへ)。旧 iwf2b の15案件中3案件で定型化していた規約の踏襲。 |
| **ja-toolkit** | 日本語サイト向け公式拡張(tmswp/ja-toolkit)。日本語スラッグ・全角スペース検索正規化・wptexturize 無効化をオプトインモジュールとして提供。WP クリーンアップは FW コードでは提供せず rules/hooks.md のレシピで行う。コアは一切のクリーンアップを行わない。 |
| **スターター(starter)** | `composer create-project` で生成する雛形(starter-theme / starter-plugin)。ブート定型・app/ スケルトン・QA 設定・AGENTS.md を同梱する。 |
| **AGENTS.md / rules/** | AI エージェント向けの機械可読規約。AGENTS.md が FW 全体規約(命名・初期化タイミング・禁止事項)、rules/ が機能別レシピ(CPT 追加・フォーム作成・独自テーブル等)を持つ(DR-13)。 |
| **read-only split** | monorepo の packages/* を Packagist 配布用の読み取り専用個別リポジトリへ自動分割する配布方式(DR-11)。 |
| **2層テスト** | ユニット層(WP 非依存、Brain Monkey でフック関数をスタブ、高速)と統合層(wp-env + WordPress のテストライブラリ(WP_TESTS_DIR)、実 WP 環境)の二段構え(DR-12)。 |
| **null センチネル(禁止語)** | 「null を返したら未設定/フォールスルー」という暗黙規約。旧FWの ACF 差し替えフィルタが機能しなかった根本原因であり、TMSWP では全面禁止(DR-18)。 |

---

## 8. 利用例(app 側コードの最小像)

スターターが生成するテーマの最小構成(全体像の共有のために示す。詳細は文書03〜07・11):

```
my-theme/
├── composer.json          # require: tmswp/core(必要に応じ拡張を追加)
├── vendor/                # composer 管理(コミットしない)
├── functions.php          # Kernel::boot() の数行のみ
├── app/
│   ├── Post/ProductPost.php       # #[AsPostType]
│   ├── Tax/ProductCategoryTax.php # #[AsTaxonomy]
│   ├── Form/ContactForm.php       # フォーム定義
│   ├── Model/InquiryModel.php     # #[AsModel]
│   └── Hook/ThemeHook.php         # #[AsHook] フック登録置き場
├── AGENTS.md              # AI 向け規約(スターター同梱)
├── index.php / single-product.php / ...   # 素の WP テンプレート
└── style.css
```

テンプレートは素の WordPress のまま、FW のヘルパを「呼ぶだけ」:

```php
<?php /* single-product.php — 通常の WP テンプレート */ ?>
<?php get_header(); ?>
<?php while (have_posts()) : the_post(); ?>
    <h1><?php the_title(); ?></h1>
    <p>価格: <?= esc_html(number_format(\App\Post\ProductPost::price(get_the_ID()))) ?>円</p>
    <?php \TMSWP\Core\View\Template::part('product/spec', ['post' => get_post()]); ?>
<?php endwhile; ?>
<?php get_footer(); ?>
```

---

## 9. 旧 iwf2b からの設計根拠(要約)

- **規約ベース起動・宣言的エンティティ・フォーム・Model を踏襲する根拠**: 15プロジェクト全件で同一構成が反復され(ブート定型100%、CPT/Tax 計100クラス、Field 142参照、Model 9クラス)、この DX が現場価値の中核であることが定量的に実証されている。
- **完全新規(互換なし)とする根拠**: 旧 API は `__callStatic`・シングルトン・null センチネルなど新設計で必ず変わる箇所に依存しており、互換シムは負債の持ち込みになる。また全15案件が dev-master 固定でタグ運用が存在せず、同一名前空間での上書き配布は既存案件を壊す。
- **コア最小化・拡張分離の根拠**: 旧FW 73クラス中27クラス(37%)が全案件未使用。AbstractHook の責務過多(ACF/CF7/Yoast 混入)は利用側の分裂(5案件が継承回避して独自 Hook を再実装)を実際に招いた。
- **セキュリティ設計を最初から固定する根拠**: SQL 文字列補間・パス再構築・CSRF 手書き漏れ・timthumb という実在欠陥が一次資料で確認されており、これらは「実装時に注意する」ではなく API 設計(prepare 必須のビルダ、トークン保管、nonce 自動化、署名付き URL)で構造的に不可能化する。

---

## 10. 受け入れ基準

本書自体の受け入れ基準(レビュー時チェックリスト):

- [ ] DR-01〜DR-20 が全件、欠落なく表に収載されている
- [ ] 文書マップ 01〜12 の番号・ファイル名・担当範囲が、実在する各設計文書のタイトルと一致している
- [ ] 各 DR に主担当文書が割り当てられ、その文書が該当 DR の詳細仕様を実際に定義している
- [ ] 用語集の用語が他文書(01〜12)で同一の意味・同一の正準クラス名で使われている(`grep -r "MetaBag\|MetaStorageInterface\|ConfirmToken" docs/` で表記ゆれがない)
- [ ] 本書に記載した正準コード例(ブート/エンティティ/フォーム/Model)が、文書02/03/04/05/06 の API 定義とシグネチャレベルで矛盾しない(MetaSchema の type 引数は MetaValueType enum であること)
- [ ] 「非ゴール」に列挙した項目(移行シム・フル ORM・テンプレートエンジン・CF7/Yoast 連携等)を実装する記述が、いずれの文書にも存在しない

検証コマンド(文書群完成後):

```bash
# 文書マップとファイル実在の突合
ls docs/*.md
# 正準クラス名の表記ゆれ検査(例)
grep -rn "MetaBag\b" docs/ | grep -v "Meta\\\\MetaBag\|MetaBag"
# 非ゴール混入の検査(例: 互換シム・CF7)
grep -rni "iwf2b互換\|互換シム\|cf7連携\|FormRendererCollection" docs/ --include="*.md" | grep -v "00-overview\|廃止\|作らない\|禁止"
```
