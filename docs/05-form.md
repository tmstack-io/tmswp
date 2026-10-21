# 05 フォーム層設計書 — TMSWP\Core\Form

## 目的

フォームの定義・値バインド・バリデーション・CSRF防御・確認画面遷移・ファイルアップロード・HTMLレンダリングを、単一の `Form` オブジェクトに集約したライフサイクルとして提供する。旧iwf2bで利用側が毎回手書きしていた制御フロー(POST判定→validate→確認画面→完了)をフレームワークの状態機械に置き換え、13フィールド規模のフォームが約30行の宣言で書け、テンプレート側はWordPress標準のテーマ構築手法のまま `echo` だけで部品を出力できる状態を実現する。

本文書は DR-07(フォーム)、DR-12(QA)、DR-14(コア収載)、DR-18(nullセンチネル全廃) に基づく。

## スコープ

- 対象パッケージ: `packages/core` (tmswp/core)
- 対象名前空間: `TMSWP\Core\Form\` 全クラスと、関連例外 `TMSWP\Core\Exception\Form\`
- スコープ外:
  - 設定画面でのフォーム利用(tmswp/settings-page 拡張の文書が本層を基盤として定義する)
  - メール送信・DB保存などの完了処理の実装(app側責務。`rules/` のレシピで指針提供。DR-13)
  - ウィザード形式(入力/確認/完了の3画面を超える、4画面以上のセッション保持型マルチステップ)は対象外とする。必要ならapp側でフォームを分割して実装する。コアは状態を持たない設計を優先する
  - ACFフィールドとの連携(tmswp/acf-bridge)

## 依存文書

| 文書 | 依存内容 |
|---|---|
| 全体アーキテクチャ/モジュールマップ | 正準クラス名・名前空間・コーディング規約 |
| Support層設計書 | `Support\Html::attributes(array $attributes): string`(HTML属性レンダラ。非空時は先頭に半角スペース1つの出力規約を含む)、`Support\Arr`、`Support\Str` |
| Kernel/Container設計書 | ブート規約(フォームはAttribute探索対象外。テンプレート/フックから明示生成する) |
| AI資料(AGENTS.md / rules/) | `rules/form.md`(フォーム実装レシピ)、`rules/form-mail.md`(wp_mail完了処理レシピ)の整備を前提として参照 |

## 設計原則と旧FW痛点の対応

実案件解析で確認された痛点と、本設計での解消方法。本層のすべてのAPI判断はこの表に帰着する。

| # | 旧FWの痛点(実案件での実態) | 本設計での解消 |
|---|---|---|
| P1 | 13フィールドのお問い合わせフォームで `add_field`/`add_rule` の羅列が約100行(T04 actions/tmpl-contact.php) | `FormDefinition` のfluent API+named argumentsで1フィールド1行。13フィールドが約30行(§利用例で実証) |
| P2 | 確認画面が `hidden_fields()` による全値hidden再POSTで、改ざん検証なし。ファイルはWP_CONTENT_DIR配下の任意パス文字列を再構築可能(セキュリティ欠陥) | 検証済み値をHMAC-SHA256署名付き `ConfirmToken` として単一hiddenで再POST。ファイルは `TempFileStore` のランダムトークンのみを往復させ、ユーザ入力からのパス再構築を全面禁止 |
| P3 | checkbox未チェック時の値消失対策に `Form::hidden(...)->set_value('')` を手前に置くハックが必要(T05で13箇所) | バインド時に「定義済みフィールドのPOSTキー欠損=空値(`''` / `[]`)」と規定し、hiddenハック自体を不要化 |
| P4 | CSRF(nonce)が手書きで、T06のログイン/パスワード忘れフォームに検証漏れが実在 | `Form::open()` がnonceを自動埋込み、`Form::handle()` が全POSTで自動検証。漏れる余地がない |
| P5 | カナ(RegexRule全角カタカナ+MaxLength)・生年月日(年月日3分割+checkdateのCallbackRule)の定型がプロジェクト間・フォーム間でコピペ重複 | コアは複合フィールド機構(`CompositeField`)を提供し、生年月日selectはapp側レシピとして定義する。カナ入力プリセットは日本語サイト向けの `tmswp/ja-toolkit` が提供する |
| P6 | checkboxesレンダリングで内部hidden inputをif分岐でスキップし `data-label` 属性からラベルを取り出す暗黙規約(T05 tmpl-event-form.php) | `FieldView::options()` が `OptionView` のリストを返し、テンプレートが素直にforeachで完全制御。マジックインデックス・内部hiddenは存在しない |
| P7 | デフォルトエラーメッセージが英語で、日本語化のためだけにルールのサブクラスを作成(T05) | ja標準メッセージを内蔵。ルール単位(`message:` 引数)とサイト全体(`tmswp/form/rule_message` フィルタ)の2層で上書き可能 |
| P8 | `FieldSet::get_instance('name')` のグローバルシングルトンレジストリで毎リクエスト再構築・テスト困難 | `Form` はただのインスタンス。`ContactForm::create()` で都度生成、テストはコンストラクタ注入で完結 |
| P9 | 確認画面のdl手書き列挙でコピペバグが実在(T04の「お名前」欄に `f_phone` が表示される) | `Form::confirmFields()` のイテレーションと `FieldView::display()` で確認画面を機械的に生成可能(hidden・パスワード・`hideOnConfirm()` の項目は出ない。§確認画面) |
| P10 | `echo $fieldset['f_xxx']`(__toString)のエスケープ責務が不明 | `FieldView`/`FormHtml` の出力はすべてエスケープ済みHTML文字列。生値が欲しい場合のみ `value()` を明示的に使う |
| P11 | `mb_strlen(配列)` のPHP8 TypeError、緩い比較によるchecked/selected判定のPHP8挙動差 | バインド時のスカラー正規化(配列混入は空文字に落とす)+全比較を文字列化後の厳密比較(`===`)に統一 |
| P12 | 3層コールバック合成(FormRenderer before/after_render)で「値がvalue属性になる」だけの処理に3ファイル横断の解析が必要 | レンダリングは `FormHtml` の純粋な静的関数(入力→エスケープ済み文字列)。コールバック合成機構は全廃(DR-16) |
| P13 | バリデーションとnonce検証の順序・`validate(false)` の意味が利用側コードから読めない | 制御フローは `handle()` 内部に固定。利用側は `FormResult::$step` を見るだけ |

## アーキテクチャ概観

### クラス相関

```
Form (final具象・ライフサイクルの持ち主。FormDefinitionを合成)
 ├─ FormDefinition ──┬─ Field (単一入力の定義: name/label/type/rules/default/choices/filters)
 │                   ├─ CompositeField (複合入力の定義: parts/compose/decompose/validateParts)
 │                   │    └─ (app側独自定義)
 │                   └─ onComplete / confirm / spamGuard / errorHtml 等のフロー設定
 ├─ FormRequest (メソッド・POST・FILESの不変スナップショット。WPのmagic quotesをunslash)
 ├─ Validator ── ValidationContext ── Rule\RuleInterface 実装群 (+ Messages)
 ├─ ConfirmToken (HMAC署名付き確認トークン) ── ConfirmTokenResult / TokenStatus
 ├─ SubmitToken (確認画面の無いフォームの使い捨て送信トークン) ── SubmitTokenResult / TokenStatus
 ├─ TokenSigner (両トークンの署名と検証。用途別の鍵の派生。@internal)
 ├─ JtiStore (jti再送防止のアトミック獲得。wp_optionsへのINSERT IGNORE) ── JtiClaim
 ├─ SpamGuardInterface (スパム対策を差し込む口。実装はapp側。手本は rules/form.md)
 ├─ UploadedFile / StoredFile / TempFileStore (ファイルの一時保管と確定)
 ├─ FormResult (handle()の戻り値: step/values/errors)
 └─ FieldView / OptionView ── FormHtml (純関数レンダラ) ── Support\Html
```

- 複合フィールドの実装例は `packages/core/rules/form.md` のレシピを正とする。カナ入力プリセットは `tmswp/ja-toolkit` が提供する。
- フォームはKernelのAttribute探索対象ではない。テンプレート・フック・ショートコードから `ContactForm::create()` で明示生成する。

### リクエストライフサイクル(全体像)

```
GET  /contact/                          → step = Input  (デフォルト値で表示)
POST _step=confirm                      → CSRF検証 → バインド → スパム対策 → 検証
                                           NG → step = Input (値保持+エラー表示。検証を通ったファイルは保管して引き回す)
                                           OK → ファイルをTempFileStoreへ退避
                                                → ConfirmToken発行 → step = Confirm
POST _step=submit  (+ _confirm_token)   → CSRF検証 → トークン検証(署名/期限) → 全項目の復元とファイルの存在確認
                                           → 今の規則での再検証 → jtiのclaim(再送の拒否) → onComplete(values, form) → step = Complete
                                           NG → step = Input (理由に応じ値復元+フォームエラー)
POST _step=back    (+ _confirm_token)   → 署名検証 → 値復元 → step = Input
```

確認画面なし(`confirm()` 未指定)の場合は、`_step=submit`(+ `_submit_token`)が「CSRF検証 → 送信トークン検証 → バインド → スパム対策 → 検証 → jtiのclaim → onComplete」を直接実行する2状態フローになる。

確認画面の有無に関係なく、送信は使い捨てのjtiで1回に限られる。確認画面なしのフォームは `open()` が埋め込む送信トークン(§SubmitToken)のjti、確認画面ありのフォームはConfirmTokenのjtiを使う(送信トークンは埋め込まない)。`handle()` と `open()` は、フォームのあるページをページキャッシュから外す(§ページキャッシュからの除外)。

### 予約POSTキー

`Form` が予約するPOSTフィールド名。`Field`/`CompositeField` のnameにこれらを使うと `FormConfigException`。

| 定数 | 値 | 用途 |
|---|---|---|
| `Form::FIELD_FORM_ID` | `_form_id` | フォーム識別子(同一ページ複数フォームの判別) |
| `Form::FIELD_STEP` | `_step` | アクション(`confirm` / `submit` / `back`) |
| `Form::FIELD_NONCE` | `_nonce` | wp_nonce(CSRF) |
| `Form::FIELD_CONFIRM_TOKEN` | `_confirm_token` | ConfirmToken |
| `Form::FIELD_SUBMIT_TOKEN` | `_submit_token` | SubmitToken(確認画面の無いフォームの送信トークン) |

加えて、ファイルフィールド `{name}` に対し `{name}__token`(一時保管トークンの引き回し用hidden)を予約する。

---

## クラス仕様

すべてのファイルは `declare(strict_types=1);` を持ち、PSR-12に従う。例外クラスは `TMSWP\Core\Exception\Form\` 配下(§例外)。

### enum: FormStep / FormAction / FieldType

```php
namespace TMSWP\Core\Form;

/** フォームの表示状態。handle()後にテンプレートが分岐に使う */
enum FormStep: string
{
    case Input = 'input';
    case Confirm = 'confirm';
    case Complete = 'complete';
}

/** POSTされた _step の値(利用者の操作)。FormStep(状態)とは別物 */
enum FormAction: string
{
    case Confirm = 'confirm';
    case Submit = 'submit';
    case Back = 'back';
}

/** フィールドの入力部品種別。HTML上のtype属性はテンプレート側でattrs上書き可能 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Password = 'password';
    case Hidden = 'hidden';
    case Select = 'select';
    case Radios = 'radios';
    case Checkboxes = 'checkboxes';
    case Checkbox = 'checkbox';
    case File = 'file';
}
```

- `FieldType::Checkboxes` と `Select`(`attrs: ['multiple' => true]` 指定時は不可。複数選択selectは `Checkboxes` を使う規約とし、`FieldView::select()` は常に単一選択)以外のスカラー型は値が `string`、`Checkboxes` のみ `list<string>`、`File` のみ `UploadedFile|StoredFile|''`。

### FormRequest

リクエストの不変スナップショット。WP関数への依存(`wp_unslash`)を `fromGlobals()` に閉じ込め、`handle()` 自体はユニットテスト可能にする。

```php
namespace TMSWP\Core\Form;

final readonly class FormRequest
{
    /**
     * @param array<string, mixed> $post  unslash済みのPOSTデータ
     * @param array<string, array<array-key, mixed>> $files $_FILES形式(エントリの形は取り込み時にFormが確かめる)
     */
    public function __construct(
        public string $method,
        public array $post = [],
        public array $files = [],
    ) {}

    /** 利用者の入力がどんな形でも例外を投げない */
    public static function fromGlobals(): self;

    public function isPost(): bool;

    /** POST値を取得。キー欠損時は '' を返す(nullを返さない) */
    public function post(string $key): mixed;

    public function hasPost(string $key): bool;
}
```

振る舞い:

- `fromGlobals()`: `$_SERVER['REQUEST_METHOD']`(無ければ `'GET'`。CLI等)を大文字化、`$_POST` の各値に `wp_unslash()`、`$_FILES` は文字列のキーで値が配列のエントリだけを保持する(配列でないエントリは捨てる。PHPが作る `$_FILES` のエントリは常に配列)。保持した配列の中の形は確かめない。**WordPressはスーパーグローバルにスラッシュを付与するため、`wp_unslash` の適用はここが唯一の責務点**。テスト時は `new FormRequest('POST', [...])` を直接渡す。
- `fromGlobals()` は、利用者の入力の形を理由に例外を投げない(誰でもPOST1本で致命的エラーを起こせる状態にしない)。`$_POST`・`$_FILES` の文字列でないキー(`$_POST[0]` のような数字のキー。PHPは `'7'` もintにする)は捨てる。項目の名前は英小文字で始まるため、どの項目にも当たらず、未定義のPOSTキーと同じく使われないから。値と、保持したファイルのエントリの中の不正(不正なUTF-8・配列のファイル等)は、`Form` の取り込み(§bind())で項目のエラーにする。
- `post()`: 欠損キーは `''`。`hasPost()` と分離(DR-18: has/get分離の原則に整合)。

### Field

単一入力フィールドの不変定義。生成は静的ファクトリのみ(コンストラクタはprivate)。

```php
namespace TMSWP\Core\Form;

use TMSWP\Core\Form\Rule\RuleInterface;

final class Field
{
    /**
     * @param list<RuleInterface> $rules
     * @param array<array-key, string> $choices 値 => 表示ラベル(数字の文字列のキーはPHPがintにする)
     * @param list<\Closure(string): string> $filters バインド時に値へ順次適用する正規化関数
     */
    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly FieldType $type,
        public readonly array $rules = [],
        public readonly string|array $default = '',
        public readonly array $choices = [],
        public readonly bool $multiple = false,
        public readonly array $filters = [],
        public readonly string $checkedValue = '1',
        public readonly ?string $checkedLabel = null,
    ) {}

    /** @param list<RuleInterface> $rules @param list<\Closure(string): string> $filters */
    public static function text(string $name, string $label, array $rules = [], string $default = '', array $filters = []): self;

    public static function textarea(string $name, string $label, array $rules = [], string $default = '', array $filters = []): self;

    /** trimフィルタを適用しない。値は再描画・確認画面・ConfirmTokenのどこにも出さない(§パスワードの項目) */
    public static function password(string $name, string $label, array $rules = []): self;

    public static function hidden(string $name, string $label = '', array $rules = [], string $default = ''): self;

    /** @param array<array-key, string> $choices */
    public static function select(string $name, string $label, array $choices, array $rules = [], string $default = ''): self;

    /** @param array<array-key, string> $choices */
    public static function radios(string $name, string $label, array $choices, array $rules = [], string $default = ''): self;

    /** 複数選択。値は list<string>。@param array<array-key, string> $choices @param list<string> $default */
    public static function checkboxes(string $name, string $label, array $choices, array $rules = [], array $default = []): self;

    /**
     * 単一チェックボックス(同意確認等)。チェック時の値は $checkedValue、未チェックは ''。
     * $checkedLabel はチェック時に確認画面(FieldView::display())へ出す文言。null の項目の display() は FormConfigException
     */
    public static function checkbox(string $name, string $label, string $checkedValue = '1', array $rules = [], ?string $checkedLabel = null): self;

    /** ファイル。常に単一ファイル。複数添付はフィールドを分けて定義する */
    public static function file(string $name, string $label, array $rules = []): self;

    /** ルールを末尾に追加した新インスタンスを返す(イミュータブル) */
    public function withRules(RuleInterface ...$rules): self;

    /** フィルタを末尾に追加した新インスタンスを返す(イミュータブル) */
    public function withFilters(\Closure ...$filters): self;
}
```

振る舞い仕様:

- `name` は `/\A[a-z][a-z0-9_]*\z/` に一致しなければ `FormConfigException`。予約キー(§予約POSTキー)・`__token` サフィックス付き名も拒否する。
- **デフォルトtrimフィルタ**: `text` / `textarea` は、ユーザ指定 `$filters` の先頭に半角・全角空白トリム(`preg_replace('/\A[\s\x{3000}]++|[\s\x{3000}]++\z/u', '', $v)`)を自動挿入する。`password` / `hidden` / 選択系 / `file` には適用しない。
- `choices` のキーは、PHPが `'1'` のような数字の文字列をintに変えるため、intのこともある。値との比較(選択状態・ラベルへの変換・選択肢の検証)は、すべてキーを文字列にしてから厳密に行う。
- `choices` のキー `''` はプレースホルダ(「--- お選びください ---」等)として許可する。選択肢整合検証(§Validator)は空値をスキップするため、`''` 選択は `Required` で検出される。
- `checkboxes` は `multiple = true`、POST名は `{name}[]`。
- エッジケース: `choices` が空配列の `select`/`radios`/`checkboxes` は `FormConfigException`(定義ミス)。

#### パスワードの項目

パスワードの項目(`FieldType::Password`)の値は、次のどこにも出さない。複合フィールドのパスワードの部品(`parts()` に `Field::password()` を含むもの)も同じに扱う。

- 再描画のvalue属性: `FieldView::input()` は常に `value=""` を出し、`attrs['value']` の指定は `FormConfigException`。`hidden()` も `FormConfigException`。複合フィールドの部品のビュー(`part('pass')`)も、部品の実際の型で判定して同じに扱う。
- 確認画面の表示: `FieldView::display()` は `FormConfigException`。パスワードの部品を含む複合フィールドは、部品の `display()` も親の `display()`(正準値にパスワードが入る)も `FormConfigException` にする。`Form::confirmFields()` には、パスワードの項目もパスワードの部品を含む複合フィールドも含めない。
- ConfirmToken: 確認画面のあるフォームにはパスワードの項目を置けない。`FormDefinition` は、`confirm()` と項目の追加のどちらが先でも、定義の時点で `FormConfigException` にする(部品にパスワードの項目を持つ複合フィールドも同じ)。確認画面の値は署名付きのトークン(中身はbase64で読める)でクライアントを往復するため、確認画面を経由してパスワードを `onComplete` まで届けるには、トークンに平文で入れるしかないから。

パスワードの項目は、確認画面の無いフォーム(ログイン・パスワードの変更等)で使い、値は `onComplete` の `$values` で受け取る。会員登録はパスワードをフォームで受け取らず、自動で生成して、利用者にはパスワードの設定のメールで決めさせる(`rules/user-role.md` の「認証(ログイン・会員登録)」のレシピ)。

### CompositeField と同梱複合フィールド

複数の入力部品が論理的に1つの値(正準値)を構成するフィールドの抽象基底。**正準値は常に `string`**(ConfirmTokenのJSON表現とメタ保存を単純化するため)。

```php
namespace TMSWP\Core\Form\Composite;

use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Rule\RuleInterface;

abstract class CompositeField
{
    /** @param list<RuleInterface> $rules 正準値に対して適用されるルール */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly array $rules = [],
    ) {}

    /**
     * パーツ定義。キーはパーツ名(/\A[a-z][a-z0-9_]*\z/)。
     * POST名は "{$this->name}[{パーツ名}]" となる。
     * パーツFieldのrulesは各パーツ値に対して正準値合成前に適用される。
     * @return array<string, Field>
     */
    abstract public function parts(): array;

    /**
     * パーツ値(フィルタ・パーツルール適用済み)から正準値を合成する。
     * 全パーツが空のときは '' を返すこと(Required連携のための不変条件)。
     * @param array<string, string> $partValues
     */
    abstract public function compose(array $partValues): string;

    /**
     * 正準値をパーツ値へ分解する(確認画面からの戻り・トークン復元時の再表示用)。
     * compose(decompose($v)) === $v を満たすこと。$v === '' のときは全パーツ '' を返すこと。
     * @return array<string, string>
     */
    abstract public function decompose(string $value): array;

    /** 一部のパーツのみ入力された状態のエラーメッセージ */
    public function incompleteMessage(): string; // 既定: __('Please complete all parts of {label}.', 'tmswp')

    /** 全パーツ入力後の組み合わせ検証。暦日・郵便番号分割などの整合性をここで検証する */
    public function validateParts(array $partValues): bool; // 既定: true

    /** validateParts() が false を返したときのエラーメッセージ */
    public function invalidPartsMessage(): string; // 既定: __('{label} is invalid.', 'tmswp')

    /** 確認画面・display()用の表示文字列(エスケープ前の生文字列を返す) */
    public function display(string $value): string; // 既定: $value をそのまま返す
}
```

検証順序(Validatorが保証する不変の手順):

1. 各パーツ値にパーツFieldのfiltersを適用
2. 全パーツが空 → 正準値 `''`。手順5へ
3. 一部のみ入力 → `incompleteMessage()` をエラーとし、このフィールドの検証を終了
4. 各パーツのrulesを検証(違反したら終了)
5. `validateParts()` でパーツ間の整合性を検証(違反したら `invalidPartsMessage()` をエラーとして終了)
6. `compose()` で正準値を得る
7. 正準値に対し `$this->rules` を通常フィールドと同じ規則で検証

生年月日等の年月日3分割selectはコアに同梱せず、`rules/form.md` の `BirthDateField` レシピを正とする。app側の要件に応じて `minYear`、`maxYear`、日入力の有無、表示形式、暦日検証を調整する。

### FormDefinition

フォームの全構成(フィールド・フロー設定)を保持するビルダ。`Form` 1インスタンスにつき1つ。

```php
namespace TMSWP\Core\Form;

use TMSWP\Core\Form\Composite\CompositeField;

final class FormDefinition
{
    private function __construct(public readonly string $id) {}

    /** @param string $id /\A[a-z][a-z0-9_-]*\z/ に一致。nonceアクション名・トークンのformIdに使う */
    public static function create(string $id): self;

    // ---- フィールド追加ショートカット(Fieldの同名ファクトリへ委譲し $this を返す) ----
    public function text(string $name, string $label, array $rules = [], string $default = '', array $filters = []): self;
    public function textarea(string $name, string $label, array $rules = [], string $default = '', array $filters = []): self;
    public function password(string $name, string $label, array $rules = []): self;
    public function hidden(string $name, string $label = '', array $rules = [], string $default = ''): self;
    public function select(string $name, string $label, array $choices, array $rules = [], string $default = ''): self;
    public function radios(string $name, string $label, array $choices, array $rules = [], string $default = ''): self;
    public function checkboxes(string $name, string $label, array $choices, array $rules = [], array $default = []): self;
    public function checkbox(string $name, string $label, string $checkedValue = '1', array $rules = [], ?string $checkedLabel = null): self;
    public function file(string $name, string $label, array $rules = []): self;

    /**
     * Field/CompositeField を直接追加(複合フィールド・プリセット・app独自定義用)。
     * @throws FormConfigException 同名の項目がある場合と、確認画面のあるフォームにパスワードの項目を足す場合
     */
    public function add(Field|CompositeField ...$fields): self;

    // ---- フロー設定 ----
    /**
     * 確認画面の有効化。既定: 無効(入力→完了の2状態)。
     * @throws FormConfigException パスワードの項目がある場合(§パスワードの項目)
     */
    public function confirm(bool $enabled = true): self;

    /**
     * 確認画面(Form::confirmFields())に出さない項目を指定する(確認用のメールアドレスの欄等)。
     * hidden とパスワードの項目は、指定しなくても出さない。
     * @throws UnknownFieldException まだ足していない項目
     */
    public function hideOnConfirm(string ...$names): self;

    /** ConfirmTokenの有効期限秒。既定: 1800 */
    public function confirmTtl(int $seconds): self;

    /** 完了処理。シグネチャ: function (array<string, mixed> $values, Form $form): void。例外の扱いは §handle() */
    public function onComplete(\Closure $handler): self;

    /** スパム対策の登録(§SpamGuardInterface)。複数を登録でき、登録の順に検査する */
    public function spamGuard(SpamGuardInterface $guard): self;

    /**
     * FieldView::errors() の既定テンプレート。`%s` をエスケープ済みメッセージに置き換える(`%s` 以外の `%` はそのまま出す。
     * sprintf の書式としては扱わない)。既定: '<p class="form-error">%s</p>'
     * @throws FormConfigException `%s` を含まない場合
     */
    public function errorHtml(string $template): self;

    /** ファイル一時保管先の差し替え(テスト・特殊構成用)。既定: TempFileStore::default() */
    public function tempFileStore(TempFileStore $store): self;

    // ---- 参照系(Form/Validator/FieldViewが使用) ----
    /** @return array<string, Field|CompositeField> 定義順を保持 */
    public function fields(): array;

    /** @return array<string, Field|CompositeField> 確認画面に出す項目(定義順。hidden・パスワード(パスワードの部品を含む複合フィールドを含む)・hideOnConfirm() の項目を除く) */
    public function confirmFields(): array;

    /** @throws \TMSWP\Core\Exception\Form\UnknownFieldException */
    public function field(string $name): Field|CompositeField;

    public function has(string $name): bool;

    public function isConfirmEnabled(): bool;
    public function ttl(): int;
    public function completeHandler(): ?\Closure;
    public function errorTemplate(): string;
    public function store(): TempFileStore;
    /** @return list<SpamGuardInterface> */
    public function spamGuards(): array;
    public function hasFileField(): bool;
}
```

振る舞い仕様:

- 同名フィールドの重複追加は `FormConfigException`(P2のような事故をロード時に検出)。
- `onComplete` 未設定のままComplete遷移に到達した場合も正常動作(何もしない)。完了処理をテンプレート側で `$result->step` 分岐により書く構成を許容するため。
- すべてのセッタは `$this` を返すfluent。`fields()` は定義順を保持した配列を返す(確認画面イテレーションの表示順保証)。

### Validator / ValidationContext / 空値セマンティクス

```php
namespace TMSWP\Core\Form;

final class Validator
{
    /**
     * バインド・フィルタ適用済みの値を検証し、エラーを返す。
     * @param array<string, mixed> $values フィールド名 => 正規化済み値
     * @return array<string, list<string>> フィールド名 => エラーメッセージ(空なら検証成功)
     */
    public function validate(FormDefinition $definition, array $values): array;

    /** 空値判定の正準実装: null / '' / [] のみ空。'0'・0・false は空ではない */
    public static function isEmpty(mixed $value): bool;
}

final readonly class ValidationContext
{
    public function __construct(
        private FormDefinition $definition,
        /** @var array<string, mixed> */
        private array $values,
        public string $currentField,
    ) {}

    /** 他フィールドの値(クロスフィールド検証用)。@throws UnknownFieldException 未定義フィールド */
    public function value(string $field): mixed;

    /** 他フィールドが空でないか */
    public function filled(string $field): bool;

    /** @throws UnknownFieldException */
    public function label(string $field): string;

    /** @return array<string, mixed> */
    public function values(): array;
}
```

#### 空値スルーセマンティクス(正準仕様)

旧FWの `through_if_empty` を明文化して踏襲する。

1. **空値の定義**: `null` / `''` / `[]` のみ。`'0'`・`0`・`false` は空ではない(旧 `Util::is_empty` がfalseを空扱いしチェックボックス検証をすり抜けた罠の修正)。
2. **`skipIfEmpty() === true` のルールは空値に対して実行されない**(無条件合格)。空チェックは `Required` / `RequiredIf`(`skipIfEmpty() === false`)だけが行う。
3. したがって「任意入力だが入力されたら形式チェック」は `rules: [new Email()]` のように `Required` を付けないだけで表現できる。

#### Validator::validate() の手順(擬似コード)

```
errors = {}
for (name, field) in definition.fields():
    value = values[name]
    ctx = new ValidationContext(definition, values, name)

    if field is CompositeField:
        # §CompositeField の検証順序1〜5を実行。エラーは errors[name] に1件だけ積んで次のフィールドへ
        continue

    # --- 選択肢整合検証(choicesを持つフィールドへ暗黙適用。ユーザがChoiceルールを書く必要はない) ---
    if field.choices != [] and not isEmpty(value):
        candidates = array_keys(field.choices) を各々 (string) キャスト
        if field.multiple:
            ok = value の全要素が candidates に厳密一致(in_array(strict: true))
        else:
            ok = in_array((string) value, candidates, strict: true)
        if not ok:
            errors[name][] = __('{label} has an invalid choice.', 'tmswp') を置換したもの
            continue   # 以降のルールは実行しない

    # --- ルール実行(フィールドごとに最初の違反で停止) ---
    for rule in field.rules:
        if field.multiple and rule.eachElement():
            # 配列値は各要素へ適用(P11: mb_strlen(array) 型エラーの根絶)
            for element in value:
                if rule.skipIfEmpty() and isEmpty(element): continue
                msg = rule.validate(element, ctx)
                if msg != null: errors[name][] = msg; break 2ループ
        else:
            if rule.skipIfEmpty() and isEmpty(value): continue
            msg = rule.validate(value, ctx)
            if msg != null: errors[name][] = msg; break
return errors
```

- **1フィールドにつき記録するエラーは最初の1件のみ**(仕様固定。旧 `$break_on_first_error` 引数は廃止)。
- ルールの実行順は定義順。`Required` を先頭に書く規約を `rules/form.md` に明記する。

### Rule群

#### RuleInterface と基底Rule

```php
namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Form\ValidationContext;

interface RuleInterface
{
    /** trueなら空値はこのルールをスキップ(空値スルー) */
    public function skipIfEmpty(): bool;

    /** multipleフィールドで各要素に個別適用するか(falseなら配列全体を受け取る) */
    public function eachElement(): bool;

    /** 合格なら null、違反なら表示用に解決済みのエラーメッセージを返す */
    public function validate(mixed $value, ValidationContext $context): ?string;
}

/**
 * 標準実装基底。メッセージ解決(個別指定 → デフォルト → tmswp/form/rule_message フィルタ → {placeholder}置換)を共通化する。
 * 動的メッセージが必要なルール(Callback / UploadFile)はRuleInterfaceを直接実装する。
 */
abstract class Rule implements RuleInterface
{
    public function __construct(private readonly ?string $message = null) {}

    public function skipIfEmpty(): bool;  // 既定 true
    public function eachElement(): bool;  // 既定 true

    final public function validate(mixed $value, ValidationContext $context): ?string;
    // passes() ? null : Messages::resolve($this, $context)

    /** 検証本体 */
    abstract protected function passes(mixed $value, ValidationContext $context): bool;

    /** 既定メッセージ(英語ソースを __('...', 'tmswp') にリテラルで渡して返す) */
    abstract public function defaultMessage(): string;

    /** {placeholder} 置換パラメータ({label}はMessagesが自動付与) */
    public function messageParams(): array;  // 既定 []

    /** コンストラクタで指定された個別メッセージ */
    final public function customMessage(): ?string;
}
```

- 旧 `RuleInterface::__construct($config)` 宣言・`RuleTrait` のconfig配列+`get_object_vars` 注入は全廃。各ルールはコンストラクタプロモーション+named argumentsで定義する。
- スカラー検証ルールは値を `(string)` キャストしてから扱う(バインド正規化により `string` が保証されるが、防御的に明記)。

#### 同梱ルール一覧(完全仕様)

実利用上位+ファイル系のみ収載(DR-14、全プロジェクト未使用だった ExactLength / GreaterThan系 / LessThan系 / MinFileSize / RequiredUnless / Type / UniqueEmail は収載しない。DR-16)。すべて `TMSWP\Core\Form\Rule\` 配下の `final class`。

| クラス | コンストラクタ | skipIfEmpty / eachElement | 検証仕様 | デフォルトメッセージ(英語ソース) |
|---|---|---|---|---|
| `Required` | `(?string $message = null)` | false / false | `!Validator::isEmpty($value)` | `{label} is required.` |
| `RequiredIf` | private(下記ファクトリ) | false / false | 条件成立時のみ `!isEmpty($value)`。不成立なら合格 | `{label} is required.` |
| `MaxLength` | `(int $length, ?string $message = null)` | true / true | `mb_strlen((string) $value) <= $length` | `{label} must be {length} characters or fewer.` |
| `MinLength` | `(int $length, ?string $message = null)` | true / true | `mb_strlen((string) $value) >= $length` | `{label} must be at least {length} characters.` |
| `Email` | `(?string $message = null)` | true / true | `filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false`。DNSチェックは行わない(旧check_dnsは廃止) | `Please enter a valid email address for {label}.` |
| `Url` | `(array $protocols = ['http', 'https'], ?string $message = null)` | true / true | `filter_var` (FILTER_VALIDATE_URL) 合格かつスキームが `$protocols` に含まれる(旧Symfony製巨大正規表現は廃止) | `Please enter a valid URL for {label}.` |
| `Regex` | `(string $pattern, ?string $message = null)` | true / true | `preg_match($pattern, (string) $value) === 1`。`preg_match` が `false`(パターン不正)なら `FormConfigException`(入力起因ではなく定義バグのため) | `{label} has an invalid format.` |
| `Choice` | `(array $choices, ?string $message = null)` | true / true | `in_array((string) $value, $choicesの値リストを文字列化, strict: true)`。choices付きフィールドへの暗黙検証とは別に、choicesを持たないフィールド(hidden等)で明示利用する | `{label} has an invalid choice.` |
| `Callback` | `(\Closure $callback, ?string $message = null)` | true / false | `$callback(mixed $value, ValidationContext $ctx): bool\|string` を呼ぶ。`true`=合格、`false`=`$message`(未指定時はデフォルト)、`string`=その文字列をエラーメッセージとして採用 | `{label} is invalid.` |
| `Retype` | `(string $field, ?string $message = null)` | true / false | `(string) $value === (string) $ctx->value($field)`(厳密一致)。メール確認・パスワード確認用 | `{label} must match {otherLabel}.` |
| `Range` | `(int\|float $min, int\|float $max, ?string $message = null)` | true / true | `is_numeric((string) $value)` でなければ違反(PHP8比較セマンティクスに依存しない)。数値化後 `$min <= $v && $v <= $max` | `{label} must be between {min} and {max}.` |
| `UploadFile` | `(?string $message = null)` | true / false | `UploadedFile` → `isValid()`、`StoredFile` → `exists()`、それ以外の非空値 → 違反 | PHPアップロードエラーコード別の英語ソース文言表(`UploadedFile::errorMessage()`)。判定不能時は `The file upload failed.` |
| `FileExtension` | `(array $extensions, ?string $message = null)` | true / false | 拡張子(クライアント名由来)を小文字化して `$extensions` に含まれ、**かつ** 実体MIME(`finfo`)が `wp_check_filetype_and_ext()` で当該拡張子と矛盾しないこと。拡張子偽装(.jpg偽装のPHP等)を実体検査で遮断 | `Please upload {label} as one of these file types: {extensions}.` |
| `MaxFileSize` | `(int $bytes, ?string $message = null)` + `static mb(int $mb): self` | true / false | `UploadedFile::$size` / `StoredFile::$size` が `$bytes` 以下 | `{label} must be {size} or smaller.`({size}は `size_format()` による表示) |

`RequiredIf` のファクトリ(nullセンチネル不使用。DR-18):

```php
final class RequiredIf extends Rule
{
    /** $field が空でないとき必須 */
    public static function filled(string $field, ?string $message = null): self;

    /** $field の値が $value と厳密一致((string)比較)するとき必須 */
    public static function equals(string $field, string $value, ?string $message = null): self;
}
```

#### app側カスタムルールの規約

`Rule` を継承し `passes()` / `defaultMessage()`(+必要なら `messageParams()`)を実装するだけ。WP API依存ルール(メール重複チェック等)はapp側で定義する(コアには収載しない)。レシピは `rules/form.md`。

```php
final class UniqueEmail extends Rule
{
    protected function passes(mixed $value, ValidationContext $context): bool
    {
        return email_exists((string) $value) === false;
    }

    public function defaultMessage(): string
    {
        return 'このメールアドレスは既に登録されています。';
    }
}
```

### Messages(エラーメッセージ解決)

```php
namespace TMSWP\Core\Form;

final class Messages
{
    private function __construct() {}

    /**
     * メッセージ解決の正準実装。手順:
     *   1. ルールインスタンスの customMessage()、なければ defaultMessage() で生メッセージを得る
     *   2. apply_filters('tmswp/form/rule_message', string $message, Rule $rule, ValidationContext $context)
     *      でサイト全体の上書きを適用する(第2引数がRuleインスタンスのため instanceof による
     *      ルール単位・親クラス単位の判定が自然に書け、第3引数でフィールド文脈も参照できる)
     *   3. {label}(= currentFieldのラベル)と messageParams() の {key} を strtr で置換する
     *      (フィルタは置換の前段で効くため、上書き文言内のプレースホルダも展開される)
     */
    public static function resolve(Rule\Rule $rule, ValidationContext $context): string;
}
```

- デフォルトメッセージは英語ソースを `__('...', 'tmswp')` にリテラルで渡して内蔵し(`wp i18n make-pot` が抽出できる形。01 §7.1)、日本語は同梱翻訳ファイルで提供する(DR-17/01 §7)。`__()` は `defaultMessage()` 等のメソッドの中で呼び、ルールを作った時点では評価しない。
- プレースホルダ書式は `{name}` 固定(旧 `%name%` から変更。sprintf `%s` との衝突事故=旧UploadFileRule未展開バグの再発防止)。
- 上書き層は「ルール単位(コンストラクタ `message:`)」と「サイト全体(`tmswp/form/rule_message` フィルタ)」の2層のみ。フォーム単位の層は設けない(必要ならルール単位で足りる)。フィルタ名はKernel文書のフック命名規約(`tmswp/` 始まりのスラッシュ区切り)に従う。
- 静的可変レジストリ(`override()`/`reset()` のような静的な上書きAPI)は持たない。グローバル可変状態のアンチパターンでありテスト間の状態リークを生むため、サイト全体の上書きはWP標準フィルタに一本化する。

### ConfirmToken(署名付き確認トークン)

確認画面の状態保持を担う。**検証済みの正準値+formId+発行時刻+一意ID(jti)をHMAC-SHA256で署名し、単一のhiddenとして往復させる**。サーバ側セッションは使わない(ステートレス。複数台構成でもそのまま動く)。確認画面ありのフォームでは、このjtiが送信トークンを兼ねる(§SubmitToken は使わない)。

ステートレスであってもページキャッシュとは両立しない。トークンとnonceを埋め込むページがキャッシュに載ると、未ログインの利用者のnonce(全訪問者で共通かつ12〜24時間有効)が期限切れのまま配られ、全員の送信が失敗する。そのためフォームのあるページはページキャッシュから外す(§ページキャッシュからの除外)。

```php
namespace TMSWP\Core\Form;

/** ConfirmToken・SubmitToken の検証の結果 */
enum TokenStatus
{
    case Valid;
    case Expired;   // 署名は正当だが期限切れ(ConfirmTokenの値は信頼できるので再表示に使う)
    case Invalid;   // 形式不正・署名不一致・formId不一致・jtiの形式不正(値は一切信頼しない)
}

final readonly class ConfirmTokenResult
{
    public function __construct(
        public TokenStatus $status,
        /** @var array<string, string|list<string>|array{__tmsfile: string}> Valid/Expired時のみ復元値、Invalid時 [] */
        public array $values = [],
        public string $jti = '',
        public int $issuedAt = 0,
    ) {}
}

final class ConfirmToken
{
    public const VERSION = 1;

    private function __construct() {}

    /**
     * @param array<string, string|list<string>|array{__tmsfile: string}> $values 正準値
     *        (StoredFileは ['__tmsfile' => トークン] へ変換済みであること。UploadedFile混入はFormConfigException)
     * @param ?string $secret マスターの秘密。null = wp_salt('auth')(鍵はここから派生させる。§トークン形式と署名手順)
     * @param ?int $now null = time()(テスト時に注入)
     */
    public static function issue(string $formId, array $values, ?string $secret = null, ?int $now = null): string;

    public static function verify(string $formId, string $token, int $ttl = 1800, ?string $secret = null, ?int $now = null): ConfirmTokenResult;
}
```

#### トークン形式と署名手順(確定アルゴリズム)

ConfirmToken と SubmitToken は、同じ形式と手順を `TokenSigner`(@internal)で共有する。違いは用途の名前(鍵の派生に使う)とペイロードの中身だけである。

```
鍵の派生:
  master = $secret ?? wp_salt('auth')
  key    = hash_hkdf('sha256', master, 32, 'tmswp/form/' . purpose)
    # purpose: ConfirmToken = 'confirm-token'、SubmitToken = 'submit-token'

発行:
  payload = {
    'ver': 1,
    'f'  : formId,
    'j'  : bin2hex(random_bytes(16)),   # jti(使い捨ての送信ID)
    't'  : now,                         # 発行unixtime
    'v'  : values,                      # ConfirmTokenのみ
  }
  json = TokenSigner::canonicalJson(payload)
    # canonicalJson: 連想配列は再帰的に ksort($a, SORT_STRING)、リスト(array_is_list)は順序維持。
    #   json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
  body = base64url(json)                # rtrim(strtr(base64_encode($json), '+/', '-_'), '=')
  sig  = hash_hmac('sha256', body, key)
  token = body . '.' . sig

検証:
  1. 形式: '.' で2分割できない / bodyがbase64url文字集合外 / sigが64桁hex以外 → Invalid
  2. hash_equals(hash_hmac('sha256', body, key), sig) でなければ → Invalid(タイミングセーフ比較必須)
  3. json_decode(base64url_decode(body), assoc, JSON_THROW_ON_ERROR)。失敗/ver≠1/f≠formId/必須キー欠損/jtiが32桁hex以外 → Invalid
  4. t + ttl < now → Expired(ConfirmTokenはvaluesも返す)
  5. → Valid(values, jti, issuedAt=t)
```

- 改ざんは手順2で検出される(P2の根絶)。値の正規化(`canonicalJson`)が決定的であるため、同一入力からは同一署名が得られ、テストで固定ベクタ検証ができる。
- 鍵は `wp_salt('auth')` をそのまま使わず、用途ごとにHKDFで派生させる。auth Cookieの署名と同じ鍵を使わないためと、ある用途のトークン(送信トークン)を別の用途(確認トークン)として受け付けないためである(確認トークンの値は検証済みとして信頼するため、取り違えると検証を飛ばした完了処理になる)。`AUTH_KEY`/`AUTH_SALT` 未定義環境でもWPが自動生成するため追加設定は不要。
- **jtiによる再送防止は `Form::handle()` の責務**(トークン自体はステートレスを維持)。Submit成立時、トークンの検証と全項目の復元(ファイルの存在の確認を含む)と今の規則での再検証の**後**、`onComplete` の**前**に、jtiを §JtiStore でアトミックに獲得(claim)する。獲得に失敗した場合は再送として拒否する。獲得は `wp_options` テーブルへのアトミックINSERT 1経路(TTL = トークンの有効期限 + 60秒)であり、並行2リクエストで獲得できるのは1リクエストのみであることが、運用環境(永続オブジェクトキャッシュの有無)に依存せず**無条件に**保証される。
  - 獲得を `onComplete` より前にするのは、二重クリック・リロード・並行した二重POSTで `onComplete` を1回だけ実行するためである。`onComplete` が例外で終わった場合はjtiを手放し(`JtiStore::release()`)、同じトークンで再送できる状態に戻す(§handle())。
  - 復元できない場合(ファイルの消失・トークンの項目とフォームの項目の食い違い)は、jtiを獲得せずに入力画面へ戻す。
  - 復元した値は、claimの前に今の規則(`Validator`)で検証し直す。確認の後のデプロイで規則や選択肢が変わり(選択肢の削除・MaxLengthの縮小等)、署名済みの値が今の規則を通らなくなることがあるためである。通らなければjtiを獲得せず、項目のエラーを付けて入力画面へ戻す(ユーザー入力に起因する異常として扱い、noticeで記録する)。
  - PRG構成(完了後リダイレクト)でも、jti消費は `wp_options` に永続するため `handle()` の冪等キャッシュに依存しない。
  - なお、jti再送防止が保証するのは「同一トークンの再POST遮断」である。決済等でビジネスレベルの冪等化(同一内容の別トークン送信の排除等)が別途必要な完了処理は、app側 `onComplete` 内で担保する。
- ペイロードサイズ: 上限は設けない(textarea 1000字+13フィールドで数KB程度であり、一般的なPOSTサイズ制限の範囲内)。

### SubmitToken(確認画面の無いフォームの送信トークン)

確認画面の無いフォームで二重送信を防ぐ使い捨てのトークン。formId・発行時刻・jtiを署名する(値は持たない)。`Form::open()` が `_submit_token` のhiddenとして埋め込み、Submit時に `Form::handle()` が検証してjtiを §JtiStore でclaimする。確認画面ありのフォームでは埋め込まない(ConfirmTokenのjtiに一本化する)。

```php
namespace TMSWP\Core\Form;

final readonly class SubmitTokenResult
{
    public function __construct(
        public TokenStatus $status,
        public string $jti = '',
        public int $issuedAt = 0,
    ) {}
}

final class SubmitToken
{
    public const VERSION = 1;

    /** 有効期限の秒数。WordPressのnonceの最長の有効期間(1日)に合わせる */
    public const TTL = 86400;

    private function __construct() {}

    public static function issue(string $formId, ?string $secret = null, ?int $now = null): string;

    public static function verify(string $formId, string $token, ?string $secret = null, ?int $now = null): SubmitTokenResult;
}
```

- 形式と署名手順は §トークン形式と署名手順(用途 `submit-token`、ペイロードに `v` は無い)。
- `open()` のたびに新しいjtiで発行する。入力エラーで再表示した画面には新しいトークンが入るため、利用者は修正してそのまま送信できる。
- Invalid(欠落・改ざん・別フォーム・確認トークンの流用)とExpiredは、POST値を再表示用にバインドしたうえで「Your session has expired. Please submit the form again.」を出して入力画面に戻す。Invalidは記録する(§ログ)。

#### JtiStore(jti再送防止のアトミック獲得)

```php
namespace TMSWP\Core\Form;

enum JtiClaim
{
    case Claimed;     // 初めての送信。jtiを獲得した
    case Duplicate;   // 獲得済みのjti(再送)
}

/**
 * jti(使い捨ての送信ID)のアトミック獲得ストア。
 * wp_options の option_name UNIQUE制約に対する INSERT IGNORE で「既存なら失敗」をDBレベルで保証する。
 * WPコア自身が WP_Upgrader::create_lock()(WP4.5+)でロックに使うのと同一パターンであり、独自再発明ではない。
 * 注意: add_option() は内部が get→insert の非アトミック実装(TOCTOU)のため代替にならない
 * (コア自身が create_lock() で生SQLに落ちているのがその根拠)。生SQLは本クラスの外に漏らさない。
 */
final class JtiStore
{
    /** @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー */
    public function claim(string $jti, int $ttl): JtiClaim;

    /** 獲得したjtiを手放す(onCompleteの例外の後、再送できるようにする)。@throws \TMSWP\Core\Exception\RuntimeException */
    public function release(string $jti): void;

    /** 期限切れjtiの掃除。@return int 削除行数。@throws \TMSWP\Core\Exception\RuntimeException */
    public function gc(): int;
}
```

確定手順:

```
claim(jti, ttl):
  if mt_rand(1, 100) === 1: gc()               # 確率的GC(cron不要の自己清掃)。挿入の前に行い、GCの失敗でjtiを獲得したまま終わらないようにする
  result = $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
      $wpdb->options,
      'tmswp_form_jti_' . jti,
      (string) (time() + ttl),                 # option_value = 期限unixtime(gc用)
  ))
  result === false → RuntimeException(データベースのエラー。$wpdb->last_error を含める)
  result === 1 → Claimed / result === 0 → Duplicate / それ以外 → RuntimeException

release(jti):
  DELETE FROM {$wpdb->options} WHERE option_name = 'tmswp_form_jti_' . jti   # false → RuntimeException

gc():
  DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tmswp_form_jti_%' AND CAST(option_value AS UNSIGNED) < time()
  # false → RuntimeException(0行に丸めない)
```

- データベースのエラーを「既に送信済み」と区別せずに扱うと、正当な送信が誤解を招く文言とともに消え、ログも残らない。そのためDBエラーは例外にし、`Form::handle()` がエラー方針(00-overview.md §5.6)に従って扱う(debugでは例外、本番ではKernelのロガーにerrorで記録して汎用の送信失敗エラーを出す。jtiは獲得していないので再送できる)。
- `autoload = 'off'` のため `alloptions` キャッシュを汚染しない。送信成立ごとの非キャッシュDB書込は1回で、フォーム送信頻度に対して無視できるコスト。
- GCの掃除圧は、transient利用時に発生する `_transient_timeout_*` のクリーンアップと同等のテーブル負荷であり、optionsテーブルへの負荷純増ではない。
- `wp_using_ext_object_cache()` による分岐(`wp_cache_add` / transient)は持たない。transient側が非アトミックでTOCTOUレースを許容し、二重送信防止が運用環境に依存する条件付き保証になるため、本1経路だけとする。

### SpamGuardInterface(スパム対策の差し込み口)

honeypot・Turnstile等のスパム対策をフォームに差し込む口。コアは差し込む仕組みだけを持ち、実装はapp側に置く(外部サービスに依存するコードをコアに置かない。原則2)。手本の実装(honeypot・Turnstile)は `rules/form.md` にある。

```php
namespace TMSWP\Core\Form;

interface SpamGuardInterface
{
    /** 入力画面の open() が hidden の後に出力する HTML(エスケープ済み)。無ければ '' */
    public function render(string $formId): string;

    /** POST を検査する。受け付けてよければ true、スパムとして拒否するなら false */
    public function passes(FormRequest $request): bool;
}
```

- 登録は `FormDefinition::spamGuard()`。複数を登録でき、登録の順に検査し、どれか1つが拒否したら受け付けない。
- `render()` は入力画面(`FormStep::Input`)の `open()` だけが出力する。確認画面には出力しない。
- `passes()` は、利用者の入力を受け取るPOST(確認画面ありは `_step=confirm`、なしは `_step=submit`)で、CSRFと送信トークンの検証の後、値の検証の前に呼ぶ。確認画面からの送信では呼ばない(確認の時点で通っているため)。
- 拒否したときは、POST値を再表示用にバインドしたうえで「Your submission could not be verified. Please try again.」を出して入力画面に戻し、Kernelのロガーにnoticeで記録する(§ログ)。

### ページキャッシュからの除外

フォームのあるページは、自動でページキャッシュから外す。`handle()` と `open()` が、`DONOTCACHEPAGE` 定数(未定義なら `true` で定義。WP Super Cache・W3 Total Cache 等のページキャッシュが参照する)を定義し、`nocache_headers()`(`Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private` 等)を送る。

- `nocache_headers()` は出力の開始後には何もしない(WordPressの仕様)。そのため、出力の前に呼ぶ `handle()` で付けることを前提とする。テンプレートの先頭・`template_redirect` 等、出力の前に `handle()` を呼ぶ(§利用例)。別のページへPOSTするフォーム(サイドバーの問い合わせ欄等)を置くページでも、出力の前にそのフォームの `handle()` を呼ぶ(自フォーム宛のPOSTでなければ入力画面を返すだけである)。
- `open()` でも同じ処理をするのは、`handle()` を呼ばないページでも `DONOTCACHEPAGE` だけは確実に付けるためである。

### UploadedFile / StoredFile / TempFileStore

ファイルアップロードの値表現と一時保管。**ユーザ入力(POST値)からファイルパスを組み立てる経路を一切持たない**(P2)。

```php
namespace TMSWP\Core\Form;

/** $_FILES 1エントリの不変値オブジェクト(リクエスト内でのみ有効) */
final readonly class UploadedFile
{
    public function __construct(
        public string $tmpPath,     // $_FILES['x']['tmp_name']
        public string $clientName,  // クライアント申告名(信頼しない。表示用)
        public string $clientMime,  // クライアント申告MIME(信頼しない)
        public int $size,
        public int $error,          // UPLOAD_ERR_*
    ) {}

    /** @param array{name: string, type: string, tmp_name: string, error: int, size: int} $entry */
    public static function fromFilesEntry(array $entry): self;

    /** error === UPLOAD_ERR_OK && is_uploaded_file($tmpPath) */
    public function isValid(): bool;

    /**
     * finfo_file による実体MIME(呼び出し毎に判定する純粋アクセサ)。判定不能時 'application/octet-stream'。
     * readonlyクラスのためメモ化は行わない(readonly解除やstaticキャッシュによるメモ化の導入は禁止)。
     * 呼び出し箇所は FileExtension ルールと TempFileStore::store() 手順6の最大2回/リクエストでコストは無視できる。
     * 保管後は StoredFile::$mime(meta.json由来、手順6でfinfo確定)が真実源(§baseDirの配置と保管済みファイルの不変条件)。
     */
    public function detectedMime(): string;

    /** クライアント名の拡張子(小文字)。なければ '' */
    public function clientExtension(): string;

    /** UPLOAD_ERR_* に対応するメッセージ(英語ソースを __('...', 'tmswp') で翻訳したもの) */
    public function errorMessage(): string;
}

/** TempFileStore に保管済みのファイル(確認画面〜完了処理間で有効) */
final readonly class StoredFile
{
    public function __construct(
        public string $token,       // /\A[a-f0-9]{32}\z/
        public string $path,        // サーバ側で解決された絶対パス(POSTからは絶対に作らない)
        public string $clientName,  // sanitize_file_name 済み
        public string $mime,        // 保管時にfinfoで確定した実体MIME
        public int $size,
    ) {}

    public function exists(): bool;

    /**
     * 完了処理での確定移動。$filename省略時はclientNameを採用し、wp_unique_filename で衝突回避。
     * 移動後、一時ディレクトリ(トークンディレクトリ)は削除される。
     * @return string 移動後の絶対パス
     * @throws \TMSWP\Core\Exception\Form\TempFileException 移動失敗・既に削除済み
     */
    public function moveTo(string $directory, ?string $filename = null): string;

    /**
     * メディアライブラリへ添付ファイル化(media_handle_sideload ラッパ)。成功時に一時ファイルは消費される。
     * @return int attachment ID
     * @throws \TMSWP\Core\Exception\Form\TempFileException WP_Error時(メッセージを内包)
     */
    public function toAttachment(int $parentPostId = 0): int;

    public function delete(): void;
}

final class TempFileStore
{
    /** @param string $baseDir 一時保管ルート(実在しなければ生成) */
    public function __construct(private readonly string $baseDir) {}

    /** 既定構成: wp_upload_dir()['basedir'] . '/tmswp-form-tmp' */
    public static function default(): self;

    /**
     * アップロード直後のファイルを一時保管しトークンを発行する。
     * @throws TempFileException 保管失敗(ディレクトリ作成不可・move失敗)
     */
    public function store(UploadedFile $file): StoredFile;

    /** トークンの存在確認(has/get分離。DR-18) */
    public function exists(string $token): bool;

    /** @throws TempFileException トークン形式不正・不存在・メタ破損 */
    public function resolve(string $token): StoredFile;

    public function delete(string $token): void;

    /** 期限切れ一時ファイルの掃除。@return int 削除したトークンディレクトリ数 */
    public function gc(int $maxAgeSeconds = 86400): int;
}
```

#### TempFileStore::store() の手順(確定アルゴリズム)

```
1. $file->isValid() でなければ TempFileException(検証済みの値のみ保管する契約)
2. token = bin2hex(random_bytes(16))                      # 128bit。推測不能
3. dir = {baseDir}/{token}/                                # トークンがそのままディレクトリ名
   - baseDir直下に .htaccess('Require all denied')と index.html(空)を初回生成(直リンク遮断)
   - mkdirは0700。失敗時 TempFileException
4. name = sanitize_file_name($file->clientName) ?: 'file.bin'
5. move_uploaded_file($file->tmpPath, dir . name)。失敗時 TempFileException
6. meta.json をdirへ書き出し: {name, mime: $file->detectedMime(), size, created: time()}
7. 確率的GC: mt_rand(1, 100) === 1 のとき gc() を実行(cron不要の自己清掃)
8. return StoredFile(token, dir . name, name, detectedMime, size)
```

#### baseDirの配置と保管済みファイルの不変条件

- 既定の `wp_upload_dir()['basedir'] . '/tmswp-form-tmp'` は**Web公開領域**である。手順3の `.htaccess`('Require all denied')による直リンク遮断は**Apache前提**であり、nginxでは `.htaccess` が無視されるため、トークン(128bit乱数、推測不能)の秘匿性のみが防御となる。可能な環境では `baseDir` をWeb非公開領域(例: `WP_CONTENT_DIR` 外や `ABSPATH` 外の専用ディレクトリ)に変更することを**推奨**する(コンストラクタ注入で差し替え可能であることが本層の責務)。公開領域に置かざるを得ない場合のnginx向け設定(`location` ブロックでの拒否)は `rules/form.md` で案内する。
- **検証済み不変条件**: `FileExtension`(実体MIME整合検査を含む)・`MaxFileSize` 等のファイル検証は `store()` より**前**(`UploadedFile` 段階の `validate()`)で完了しており、`StoredFile` は常に検証済みのファイルを指す。トークンディレクトリは `0700`(手順3)で所有プロセス以外から書込不可、かつ保管後にファイル実体を書き換えるAPIは本層に存在しない(`moveTo()`/`toAttachment()`/`delete()` はrename・削除のみ)。したがって確認画面往復後の `moveTo()`/`toAttachment()` で実体MIMEを再検証する必要はなく、`meta.json` の `mime`(手順6で `finfo` により確定)を以後の真実源として扱う。

#### resolve() / gc() の仕様

- `resolve(token)`: 形式 `/\A[a-f0-9]{32}\z/` に不一致なら即 `TempFileException`(**パストラバーサルの遮断はこの正規表現が境界**)。`{baseDir}/{token}/meta.json` を読み、記載のファイル名と実体の存在を確認して `StoredFile` を再構成する。トークン→パスの対応はこのサーバ側ディレクトリ構造のみが知る。
- `gc()`: baseDir直下のトークンディレクトリを走査し、`meta.json` の `created`(読めない場合はディレクトリmtime)が `maxAgeSeconds` より古いものを再帰削除。確認画面の放置(既定TTL30分)に対して既定24時間保持は十分な余裕を持つ。
- WP-CLIからの手動GC(`wp tmswp form gc` 等)はコアのWP-CLI統合文書側で扱う。本層は `gc()` をpublicに公開するまでが責務。

### Form(ライフサイクルの持ち主)

フォームライフサイクルの持ち主であるfinal具象クラス。app側は本クラスを**継承せず**、組み立てた `FormDefinition` をコンストラクタへ渡す合成で利用する。バインド・CSRF・送信トークン・スパム対策・検証・状態遷移・完了処理の起動を `handle()` に集約する。

```php
namespace TMSWP\Core\Form;

final class Form
{
    public const FIELD_FORM_ID = '_form_id';
    public const FIELD_STEP = '_step';
    public const FIELD_NONCE = '_nonce';
    public const FIELD_CONFIRM_TOKEN = '_confirm_token';
    public const FIELD_SUBMIT_TOKEN = '_submit_token';

    /** @var array<string, mixed> 現在の表示値(フィルタ適用済み) */
    private array $values = [];
    /** @var array<string, string|list<string>> applyDefaults() で差し替えた既定値 */
    private array $defaultOverrides = [];
    /** @var array<string, list<string>> */
    private array $fieldErrors = [];
    /** @var list<string> フィールドに紐付かないエラー(CSRF失敗・トークン期限切れ・送信失敗等) */
    private array $formErrors = [];
    private FormStep $step = FormStep::Input;
    private ?FormResult $result = null;       // handle()の冪等化用キャッシュ
    private string $confirmToken = '';        // Confirm状態のときopen()が埋め込む
    private readonly string $creatorFile;     // new Form() を書いたファイル(§FormのKernel)

    public function __construct(private readonly FormDefinition $definition) {}

    /**
     * バインド+CSRF検証+送信トークン検証+スパム対策+検証+状態遷移+完了処理の実行。
     * 冪等: 2回目以降の呼び出しは初回の FormResult をそのまま返す(onCompleteの二重実行はない)。
     * フォームのあるページをページキャッシュから外す(§ページキャッシュからの除外)ため、出力の前に呼ぶ。
     * @param ?FormRequest $request null = FormRequest::fromGlobals()
     * @throws \Throwable debug時、onCompleteが投げた例外と JtiStore のデータベースのエラー
     */
    public function handle(?FormRequest $request = null): FormResult;

    // ---- テンプレート向けAPI(すべてhandle()後に使用。handle()前はInput状態のデフォルト値を返す) ----

    /** @throws UnknownFieldException */
    public function field(string $name): FieldView;

    /** 定義順の全FieldView。@return array<string, FieldView> */
    public function fields(): array;

    /**
     * 確認画面に出す項目のFieldView(定義順。§確認画面)。hidden・パスワードの項目(パスワードの部品を含む複合フィールドを含む)と、
     * FormDefinition::hideOnConfirm() で指定した項目を除く。@return array<string, FieldView>
     */
    public function confirmFields(): array;

    /**
     * <form>開始タグ+必須hiddenを返す。hiddenは _form_id / _nonce、確認画面の無いフォームでは _submit_token
     * (呼び出しごとに新しいjtiで発行)、Confirm状態では _confirm_token。Input状態では、登録したスパム対策の
     * render() の出力を続ける。
     * attrs['action'] は送信先(未指定時は ''。自己POST)。method="post" は固定で、ファイルフィールドを持つ定義では
     * enctype="multipart/form-data" を自動付与する。この2つはFWが決めるため、attrsで指定すると FormConfigException。
     * @throws FormConfigException
     */
    public function open(array $attrs = []): string;

    public function close(): string;  // '</form>'

    public function step(): FormStep;
    public function id(): string;

    /** @return array<string, mixed> */
    public function values(): array;

    /**
     * 項目の既定値を差し替える(設定画面で保存済みの値を初期表示する等)。handle() より前に呼ぶ。
     * 値は項目が受け付ける形で渡す: 単一の値の項目と複合フィールド(正準値)は string、複数選択(checkboxes)は list<string>。
     * @param array<string, mixed> $values
     * @throws \TMSWP\Core\Exception\LogicException handle() の後に呼んだ場合(POST値が既定値に戻り FormResult と食い違うため)
     * @throws UnknownFieldException フォームに無い項目
     * @throws \TMSWP\Core\Exception\Form\FormConfigException 値の形が項目と違う場合と、ファイルの項目(既定値を持てない)
     */
    public function applyDefaults(array $values): void;

    /** @return array<string, list<string>> */
    public function errors(): array;
    /** @return list<string> */
    public function formErrors(): array;
    public function hasErrors(): bool;
}
```

app側のフォーム定義クラスの規約: `Form` を継承せず、「構成済み `Form` を返す `create(): Form` staticファクトリを持つfinalクラス」として置く。呼び出し側は `ContactForm::create()` と書く。テストはサブクラススタブ不要で、任意の `FormDefinition` を `new Form($definition)` で直接渡せる。`Form` を abstract 基底にして `create(): static`(LSB)や null センチネルのコンストラクタで構成する形は採らない(DR-18)。

#### FormのKernel(ログの記録先とdebugの判定)

`Form` は、`new Form()` を書いたファイルを含むルートディレクトリを持つKernel(`Kernel::forFile()`。`Log` の静的な呼び出しと同じ規則。テーマなら子テーマのディレクトリも含む)に属する。ログはそのKernelのロガー(`$kernel->logger()`)に記録し、debugの判定はそのKernelの `KernelConfig::$debug` で行う。

- フォームはテンプレート・ショートコード・app側のクラスのどこからでも作れるため、クラスの名前空間(`Kernel::forClass()`)ではなく、作ったファイルの場所で決める。
- Kernelは、ログやdebugの判定が要るときに初めて解決する。どのKernelのルートにも含まれないファイルで作ったフォームがそれを要すると `KernelException` になる(黙って別の記録先へ回さない)。送信の失敗(onCompleteの例外等)の扱いでKernelを決められなかった場合は、jtiの手放しの失敗を記録する途中も含め、元の例外を `KernelException` の `previous` に入れて投げる。

#### ログ

`handle()` は次の事象を、FormのKernelのロガーに `tmswp.form: ` で始まるメッセージで記録する。文脈にはフォームのID(`form`)を入れ、利用者の入力値は入れない。

| 事象 | レベル |
|---|---|
| CSRF(nonce)の検証の失敗 | warning |
| ConfirmToken・SubmitTokenがInvalid(欠落・改ざん・別フォーム) | warning |
| 一時ファイルの消失(確認画面からの送信時) | warning |
| ConfirmTokenの項目とフォームの項目の食い違い(デプロイをまたいだ送信) | notice |
| ConfirmTokenの値が今の規則を通らない(確認の後に規則・選択肢が変わった) | notice(文脈の `fields` に項目名) |
| 取り込み時の入力の不正(不正なUTF-8・不正な形のファイルのエントリ。§bind()) | notice(文脈の `fields` に項目名) |
| 再送の拒否(jtiがDuplicate) | notice |
| スパム対策による拒否 | notice |
| onCompleteの例外・JtiStoreのデータベースのエラー(本番) | error(文脈は下記の例外の情報) |
| onCompleteの例外の後のjtiの手放しの失敗 | error(文脈は下記の例外の情報) |

例外を記録するときは、例外のオブジェクトを文脈に入れず、次の3つだけを入れる。onCompleteが利用者の入力値をメッセージに含めた例外を投げることがあり、トレースの引数にはjti等が入りうるため、例外のメッセージ・previousのメッセージ・トレースは本番のログに残さない。開発者は、debugで投げ直される元の例外で詳細を見る。

| キー | 値 |
|---|---|
| `exceptionClass` | 例外のクラス名 |
| `exceptionAt` | 例外が投げられた場所(`ファイル:行`) |
| `previousClasses` | previousの例外のクラス名のリスト(近い順。無ければ空) |

#### handle() の状態機械(確定擬似コード)

```
handle(request = null):
  if this.result != null: return this.result          # 冪等化

  excludeFromPageCache()                               # DONOTCACHEPAGE + nocache_headers()(§ページキャッシュからの除外)
  request ??= FormRequest::fromGlobals()
  def = this.definition

  # --- 0. 自フォーム宛のPOSTでなければ初期表示 ---
  if !request.isPost() or request.post(FIELD_FORM_ID) !== def.id:
      this.values = 全フィールドのdefault(applyDefaults() の差し替えを含む)
      return finish(FormStep::Input)

  # --- 1. CSRF(全POSTで無条件・自動) ---
  if wp_verify_nonce(request.post(FIELD_NONCE), 'tmswp_form_' . def.id) == false:
      bind(request)                                    # 値は再表示用に保持(処理には使わない)
      formErrors[] = __('Your session has expired. Please submit the form again.', 'tmswp')
      log warning                                      # §ログ
      return finish(FormStep::Input)

  action = FormAction::tryFrom((string) request.post(FIELD_STEP))
  if action == null:
      bind(request)                                    # _step不明: 検証せずInput再表示
      return finish(FormStep::Input)
  if action == FormAction::Confirm and !def.isConfirmEnabled():
      action = FormAction::Submit                      # confirm無効フォームではConfirmはSubmitと同義
  if action == FormAction::Back and !def.isConfirmEnabled():
      bind(request)                                    # confirm無効フォームに戻る操作は無い: 不明_stepと同じ
      return finish(FormStep::Input)

  switch action:

  case Confirm:                                        # 入力 → 確認
      bind(request)                                    # 入力の不正(不正なUTF-8等)は値を空にして記録(§bind())
      if !passesSpamGuards(request): return finish(FormStep::Input)
      validate()                                       # 入力の不正のあった項目は、検証の結果に代えてそのエラー
      if fieldErrors != []:
          storeValidUploadedFiles()                    # 自身の項目にエラーが無いファイルは保管し、{name}__token で引き回す
          return finish(FormStep::Input)
      storeUploadedFiles()                             # UploadedFile → StoredFile(トークン化)
      this.confirmToken = ConfirmToken::issue(def.id, tokenValues())
      return finish(FormStep::Confirm)

  case Submit:
      if def.isConfirmEnabled():                       # 確認 → 完了
          r = ConfirmToken::verify(def.id, (string) request.post(FIELD_CONFIRM_TOKEN), def.ttl())
          if r.status == Invalid:
              this.values = 全フィールドのdefault       # 改ざん: 値は一切信頼しない
              formErrors[] = __('The form could not be resubmitted. Please start again.', 'tmswp')
              log warning
              return finish(FormStep::Input)
          restored = restoreValues(r.values)           # 全項目を復元。['__tmsfile'=>token] → StoredFile(消えていたら '')
          if r.status == Expired:
              return returnToInput(EXPIRED)            # 署名は正当: 再表示には使える
          if restored.changed:                         # トークンの項目・値の形がフォームと食い違う(デプロイをまたいだ送信)
              log notice; return returnToInput(EXPIRED)   # 例外(500)にせず、入力画面に戻す
          if restored.missingFiles != []:              # 一時ファイルの消失(gc済み等)。jtiのclaimより前に確かめる
              log warning; return returnToInput(EXPIRED)
          errors = Validator.validate(def, 復元した値(複合フィールドは部品の値に分解))   # 今の規則で再検証(claimより前)
          if errors != []:                             # 確認の後のデプロイで規則・選択肢が変わった
              fieldErrors = errors
              複合フィールドの正準値を部品の値に戻す(再表示用)
              log notice                               # 項目名だけを記録する
              return finish(FormStep::Input)           # jtiは獲得していない
          return complete(r.jti, def.ttl() + 60)
      else:                                            # 入力 → 完了(確認なし)
          r = SubmitToken::verify(def.id, (string) request.post(FIELD_SUBMIT_TOKEN))
          bind(request)
          if r.status != Valid:                        # 欠落・改ざん・別フォーム・期限切れ
              formErrors[] = __('Your session has expired. Please submit the form again.', 'tmswp')
              if r.status == Invalid: log warning
              return finish(FormStep::Input)
          if !passesSpamGuards(request): return finish(FormStep::Input)
          validate()
          if fieldErrors != []: return finish(FormStep::Input)
          # 確認なしの場合ファイルはUploadedFileのままonCompleteへ(同一リクエスト内でtmp_nameが有効)
          return complete(r.jti, SubmitToken::TTL + 60)

  case Back:                                           # 確認 → 入力(値を保持して戻る)
      r = ConfirmToken::verify(def.id, (string) request.post(FIELD_CONFIRM_TOKEN), def.ttl())
      if r.status == Invalid:
          this.values = 全フィールドのdefault
          formErrors[] = __('The form could not be resubmitted. Please start again.', 'tmswp')
          log warning
          return finish(FormStep::Input)
      restoreValues(r.values)                          # Expiredでも署名正当なら復元可。今の項目に無い値は捨てる
      複合フィールドの正準値を部品の値に戻す(再表示用)
      return finish(FormStep::Input)                   # 入力画面で確認からやり直すため、食い違い・消失はここでは扱わない

complete(jti, ttl):                                    # jtiのclaim → onComplete
  try: claim = new JtiStore().claim(jti, ttl)
  catch RuntimeException e: return failSubmission(e)   # DBエラー(jtiは獲得していない)
  if claim == Duplicate:                               # 再送(リロード/二重クリック/並行二重POST)
      this.values = 全フィールドのdefault
      formErrors[] = __('This form has already been submitted.', 'tmswp')
      log notice
      return finish(FormStep::Input)
  handler = def.completeHandler()
  if handler != null:
      try: handler(this.values, this)
      catch Throwable e:
          JtiStore.release(jti)                        # 同じトークンで再送できる状態に戻す(失敗したら log error)
          return failSubmission(e)
  return finish(FormStep::Complete)

failSubmission(e):                                     # エラー方針(00-overview.md §5.6)
  if FormのKernelの debug: throw e
  log error(exceptionClass, exceptionAt, previousClasses)   # 例外のメッセージ・トレース・オブジェクトは入れない(§ログ)
  return returnToInput(__('The form could not be sent. Please try again.', 'tmswp'))

passesSpamGuards(request):
  for guard in def.spamGuards():
      if !guard.passes(request):
          formErrors[] = __('Your submission could not be verified. Please try again.', 'tmswp')
          log notice
          return false
  return true

returnToInput(message):
  複合フィールドの正準値を部品の値に戻す(再表示用)
  formErrors[] = message
  return finish(FormStep::Input)

EXPIRED = __('The confirmation screen has expired. Please review your entries and continue again.', 'tmswp')

finish(step):
  this.step = step
  this.result = new FormResult(step, this.values, this.fieldErrors, this.formErrors)
  return this.result
```

- `onComplete` で起きた例外はエラー方針に従う。debug時は例外として投げる(jtiは手放してから投げる)。本番ではFormのKernelのロガーに `error` で記録し、利用者には汎用の送信失敗エラーをフォームに出す。いずれもjtiは消費されず(手放しがデータベースのエラーで失敗した場合を除く。その失敗は error で記録する)、同じトークンで再送できる。`onComplete` の中の処理(メール送信等)が一部だけ済んでから例外になった場合、再送でその処理が重複しうる。重複を避ける必要がある処理は、app側の `onComplete` で冪等にする。

#### bind()(値バインドとスカラー正規化)の確定仕様


定義済みフィールドだけを対象とする(マスアサインメント耐性。未定義POSTキーは無視)。

| フィールド種別 | POSTキー欠損時 | 値の正規化 |
|---|---|---|
| スカラー(Text/Textarea/Password/Hidden/Select/Radios/Checkbox) | `''`(P3: hiddenハック不要化) | 配列が来たら `''` に落とす(攻撃対策+P11)。それ以外は `(string)` キャスト後、filtersを順次適用 |
| Checkboxes(multiple) | `[]` | 非配列なら `[v]` に包む(`''` は `[]`)。各要素を `(string)` キャスト・連番インデックスに振り直し・filtersを各要素へ適用 |
| File | `''` | `$_FILES[name]` があり、`error` が `UPLOAD_ERR_NO_FILE` でなければ、1つのファイルの形(`name`・`type`・`tmp_name` が文字列、`error`・`size` が整数、`name` が正しいUTF-8)を確かめて `UploadedFile::fromFilesEntry()`。なければ `{name}__token` POST値を検査し、`store.exists(token)` なら `store.resolve(token)`(確認画面から戻った後の再送信でファイル選択を維持)。どちらもなければ `''` |
| CompositeField | 全パーツ `''` | `{name}[part]` の配列から各パーツをスカラー正規化。表示値としてはパーツ値の連想配列を保持し、検証時に正準値へ合成 |

取り込みの段階で見つけた利用者の入力の不正は、例外にせず、その項目の値を空(`''`・`[]`)にしてから項目のエラーにする(ユーザー入力に起因する異常はバリデーションエラーの側に倒す。§例外)。そのPOSTが検証まで進んだ場合(確認・送信)、その項目の検証の結果に代えてこのエラーを付け、入力画面に戻す。項目名だけを §ログ に notice で記録し、入力値は記録しない。

| 不正 | 項目のエラー |
|---|---|
| 値(スカラー・Checkboxesの要素・CompositeFieldのパーツ)が不正なUTF-8 | `'{label} contains characters that cannot be used.'` |
| 保持したファイルのエントリ(配列)が1つのファイルの形でない(`resume[]` のような配列のファイル・キーの欠落・エントリの中の項目の型の違い(`error` が文字列等)。`error` の無いエントリを含む)、またはファイル名が不正なUTF-8 | `'The file upload failed.'` |

不正なUTF-8を取り込みの段階で止めるのは、確認画面のあるフォームでその値がConfirmTokenのJSONに入ると `json_encode` が例外を投げ、500になるため(確認画面の無いフォームでも同じ扱いにする)。

#### validate() / storeUploadedFiles() / tokenValues()

- `validate()`: `(new Validator())->validate(def, this.values)` の結果を `fieldErrors` に格納。CompositeFieldは検証成功時に表示値を正準値(string)へ確定する。
- `storeUploadedFiles()`: 値が `UploadedFile` のフィールドを `def.store()->store()` で `StoredFile` に差し替える。`StoredFile`(再送信で維持されたもの)はそのまま。
- `storeValidUploadedFiles()`: 確認ボタンの押下で他の項目がエラーになったときに使う。自身の項目にエラーが無く、アップロードに成功した(`isValid()`)`UploadedFile` だけを保管する。保管したファイルは入力画面の `$form->field(name)->hidden()`(`{name}__token`)で引き回し、利用者に選び直させない。保管したまま送信されなかったファイルは、TempFileStoreの `gc()` が期限で消す。
- `tokenValues()`: ConfirmTokenに格納する正準値を生成。`StoredFile` は `['__tmsfile' => token]` へ変換。`UploadedFile` が残っていたら `FormConfigException`(storeUploadedFiles()後には存在し得ない不変条件)。
- `restoreValues()`: 逆変換。署名済みの値を**全項目**復元してから結果(項目の食い違いの有無・消失したファイルの項目)を返す。`store.exists()` が false のトークン(GC済み等)は値を `''` に落とし、Submitでは**期限切れトークンと同じ扱いで `formErrors` に「確認画面の有効期限が切れました」を積んで `FormStep::Input` へ戻す**(ファイルだけ黙って欠落させない)。この確認はjtiのclaimより前に行うため、ファイルが消えていた送信はjtiを消費しない。トークンの項目がフォームの項目と食い違う場合(デプロイをまたいだ送信)も、例外にせず同じく入力画面へ戻す(ユーザー入力に起因する異常はバリデーションエラーの側に倒す。§例外)。項目が揃っていても、復元した値はclaimの前に今の規則で検証し直し、通らなければ項目のエラーを付けて入力画面へ戻す(§handle())。

#### 二重送信・リダイレクトの責務分担

- 二重送信の防止はFWの責務。確認画面の有無に関係なく、送信は使い捨てのjti(確認画面なしはSubmitToken、ありはConfirmToken)の §JtiStore へのclaimで1回に限られる。リロード・二重クリック・並行した二重POSTの2本目以降は「This form has already been submitted.」で入力画面に戻る。
- `handle()` はリダイレクトしない(コアはHTTP応答を制御しない。ヘッダはページキャッシュからの除外だけを送る)。PRGはapp側で `$result->step === FormStep::Complete` を見て `wp_safe_redirect()` し、直後に `exit` する(00-overview.md 原則1の例外。§利用例)。
- リダイレクトせずその場で完了画面を出す構成でも、jti消費によりリロード再POSTは再実行されない。

### FormResult

```php
namespace TMSWP\Core\Form;

final readonly class FormResult
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $fieldErrors
     * @param list<string> $formErrors
     */
    public function __construct(
        public FormStep $step,
        public array $values,
        public array $fieldErrors,
        public array $formErrors,
    ) {}

    public function isValid(): bool;    // fieldErrors と formErrors がともに空
    public function hasErrors(): bool;  // !isValid()
}
```

### FieldView / OptionView(テンプレート向けビュー)

`Form::field()` が返す出力ヘルパ。**全メソッドの戻り値はエスケープ済みHTML文字列**であり、テンプレートはそのまま `echo` する(P10)。HTML構造(ラッパ・dl・クラス設計)は常にテンプレート側が持ち、本クラスは入力部品とエラー断片しか生成しない(WP標準テーマ手法を壊さない絶対原則)。

```php
namespace TMSWP\Core\Form;

use TMSWP\Core\Form\Composite\CompositeField;

final class FieldView
{
    /** @internal Formのみが生成する */
    public function __construct(
        private readonly Field|CompositeField $field,
        private readonly mixed $value,
        /** @var list<string> */
        private readonly array $errors,
        private readonly string $formId,
        private readonly string $errorTemplate,
    ) {}

    // ---- 入力部品(エスケープ済みHTML文字列を返す。attrs の扱いは下記「属性の指定」) ----

    /** <input>。typeはFieldTypeから既定(text/password/hidden/file)、attrs['type']で上書き可(email/tel等)。
     *  password/file は値を出さない(attrs['value'] の指定も不可) */
    public function input(array $attrs = []): string;

    public function textarea(array $attrs = []): string;

    /** choices付きフィールド用 <select>(単一選択。attrs['multiple'] は不可)。選択状態は文字列化厳密比較。
     *  <option> のテキスト(choicesのラベル)は esc_html()、value属性は esc_attr() を適用済み(FormHtml::select()に委譲) */
    public function select(array $attrs = []): string;

    /**
     * Radios/Checkboxes の選択肢ビュー(テンプレートがforeachで完全制御する正準手段)。
     * @return list<OptionView>
     */
    public function options(): array;

    /** options()の一括版: <label {labelAttrs}><input {attrs}> ラベル</label> を選択肢数ぶん連結。
     *  attrs['id']・labelAttrs['for'] は全選択肢に同じ値が付くため不可(個別に変えるときは options() を使う) */
    public function checkboxes(array $attrs = [], array $labelAttrs = []): string;
    public function radios(array $attrs = [], array $labelAttrs = []): string;

    /** 単一チェックボックス(FieldType::Checkbox)。checked判定は checkedValue との厳密比較 */
    public function checkbox(array $attrs = []): string;

    /** 現在値を持つ <input type="hidden">(ファイルフィールドでは {name}__token のhiddenを返す)。パスワードの項目は FormConfigException */
    public function hidden(): string;

    /** CompositeFieldのパーツビュー(例: $form->field('birthday')->part('year')->select())。
     *  単一Fieldに対して呼ぶと FormConfigException */
    public function part(string $key): self;

    // ---- エラー・メタ情報 ----

    /** エラーHTML。$template の `%s` をエスケープ済みメッセージに置き換える(`%s` 以外の `%` はそのまま)。
     *  $template省略時はFormDefinition::errorHtml()。`%s` を含まないテンプレートは FormConfigException。$firstOnly=trueで先頭のみ */
    public function errors(?string $template = null, bool $firstOnly = false): string;

    public function hasError(): bool;
    /** @return list<string> 生メッセージ(エスケープ前) */
    public function errorList(): array;

    /** esc_html済みラベル */
    public function label(): string;
    /** <label for="{id()}">ラベル</label>。attrs['for'] で for を変えられる(input() 等の id を変えたとき) */
    public function labelTag(array $attrs = []): string;

    /** 生値(string | list<string> | UploadedFile | StoredFile | パーツ連想配列)。エスケープなし */
    public function value(): mixed;

    /**
     * 確認画面用の表示文字列(esc_html済み)。
     * - スカラー: choicesがあればラベルへ変換して表示。textarea値は nl2br 適用(esc_html後)
     * - 単一チェックボックス: チェック時は checkedLabel、未チェックは ''。checkedLabel の無い項目は FormConfigException
     * - list: 各要素をラベル変換し $separator で連結
     * - ファイル: clientName を表示
     * - Composite: CompositeField::display() の結果をesc_html
     * - パスワード: FormConfigException(パスワードの部品と、それを含む複合フィールドも。§パスワードの項目)
     */
    public function display(?string $separator = null): string;

    /** 決定的ID: '{formId}-{fieldName}'(P: 旧crc32不安定ID問題の解消)。入力部品の既定のid と labelTag() の既定のfor */
    public function id(): string;

    /** POST名(Checkboxesは '{name}[]'、Compositeパーツは '{name}[{part}]') */
    public function name(): string;
}

final readonly class OptionView
{
    public function __construct(
        public string $value,
        public string $label,    // 生ラベル(テンプレートで esc_html() して出力する)
        public bool $checked,
        public string $id,       // '{formId}-{fieldName}-{index}'
        public string $name,
        public FieldType $type,  // Radios または Checkboxes
    ) {}

    /** <input type="checkbox|radio" ... checked> 単体(エスケープ済み)。attrs['id'] で id を変えられる */
    public function input(array $attrs = []): string;

    /** <label for="{id}">ラベル</label>(エスケープ済み)。attrs['for'] で for を変えられる */
    public function labelTag(array $attrs = []): string;
}
```

振る舞い仕様:

- checked/selected判定はすべて「双方を `(string)` キャストした上で `===`」(P11)。multipleは `in_array(strict: true)`。
- 未チェック送信用のhidden inputは生成しない(バインド仕様が欠損キー=空を保証するため不要。P3/P6)。
- `input()` で `attrs['value']` を渡した場合は現在値より優先する(特殊用途。パスワード・ファイルの項目では不可)。`attrs` の `class` は文字列のみ受け付ける(配列マージ等の便宜機能は持たない。シンプル第一)。
- 型と部品の不一致(`FieldType::Select` のフィールドに `textarea()` 等)は `FormConfigException`。テンプレート書き間違いを早期検出する。

#### 属性の指定

`attrs` に渡した属性は、出力に反映するか `FormConfigException` にするかのどちらかで、黙って捨てない。属性名は大文字と小文字を区別しない(`'ID'` も `id` の指定。同じ属性を2回指定すると `FormConfigException`)。名前だけの指定(`['required']`)と、名前だけの指定の `''`(`[$required ? 'required' : '']` の書き方)は `Support\Html::attributes()` と同じに扱う。

| 部品 | 上書きできる属性 | 指定できない属性(FWが決める) |
|---|---|---|
| `Form::open()` | `action`(既定 `''`) | `method`(常に `post`)・`enctype`(ファイルの項目の有無で決まる) |
| `FieldView::input()` | `id`・`type`・`value`(パスワード・ファイルの項目は不可) | `name` |
| `FieldView::textarea()` | `id` | `name` |
| `FieldView::select()` | `id` | `name`・`multiple` |
| `FieldView::checkbox()`・`OptionView::input()` | `id` | `type`・`name`・`value`・`checked` |
| `FieldView::checkboxes()`・`radios()` | なし(`id` は選択肢ごとに決まる) | `id`(attrs)・`for`(labelAttrs)と、`OptionView::input()` と同じ属性 |
| `FieldView::labelTag()`・`OptionView::labelTag()` | `for` | なし |

`id()` は既定のidを返す。入力部品のidを変えたときは、ラベルとの関連付けのため、同じ値を `labelTag(['for' => ...])` に渡す。`Form::field()` は呼び出しごとに新しい `FieldView` を作るため、`input()` に渡したidを `id()`・`labelTag()` が覚えておくことはできない。

#### 確認画面

確認画面の項目の一覧は `Form::confirmFields()` を回して `label()` と `display()` で出す(§利用例)。`Form::fields()` は全項目を返すため、確認画面には使わない。

- `confirmFields()` は、hiddenの項目(利用者が入力していない値)と、パスワードの項目・パスワードの部品を含む複合フィールド(§パスワードの項目)を除く。
- 確認用のメールアドレスの欄(`Retype`)等、確認画面に出す必要の無い項目は `FormDefinition::hideOnConfirm()` で除く。
- 単一チェックボックスは、`Field::checkbox()` の `checkedLabel`(チェック時に出す文言。「同意する」等)を `display()` に出す。値(`'1'`)をそのまま出さないよう、`checkedLabel` の無い項目の `display()` は `FormConfigException` にする。確認画面に出さないなら `hideOnConfirm()` で除く。

### FormHtml(純関数レンダラ)

HTML断片生成の最下層。**全メソッドはstatic純関数**(引数のみから出力が決まり、状態・コールバック・フックを一切持たない)。FieldView/OptionView/Form::open() はすべて本クラスへ委譲する。属性のエスケープと整形は `Support\Html::attributes()`(Support層文書で定義: 値は `esc_attr`、`true` は属性名のみ、`false|null` は省略、非空時は先頭に半角スペース1つ)に委譲する。テキストノードは `esc_html()` を直接呼んでエスケープする(`esc_html` の有無を確かめて別のエスケープに切り替える分岐は持たない。WordPressの `esc_html` フィルタを通らない出力の経路を作らないため)。

引数で決める属性(`input()` の `type`・`name`・`value` 等)を `$attrs` でも指定すると、どちらを使うかが曖昧になるため `FormConfigException` にする(黙ってどちらかを捨てない)。

```php
namespace TMSWP\Core\Form;

final class FormHtml
{
    private function __construct() {}

    /** attrs に type・name・value は指定できない */
    public static function input(string $type, string $name, string $value, array $attrs = []): string;
    /** attrs に name は指定できない */
    public static function textarea(string $name, string $value, array $attrs = []): string;

    /** @param array<array-key, string> $choices @param string|list<string> $selected 文字列化厳密比較
     *  <option> のテキスト(choicesのラベル)は esc_html()、value属性は esc_attr() を適用してから出力する。attrs に name は指定できない */
    public static function select(string $name, array $choices, string|array $selected, array $attrs = []): string;

    /** attrs に type・name・value・checked は指定できない */
    public static function checkbox(string $name, string $value, bool $checked, array $attrs = []): string;
    public static function radio(string $name, string $value, bool $checked, array $attrs = []): string;
    public static function hidden(string $name, string $value): string;
    /** attrs に for は指定できない */
    public static function label(string $text, string $for, array $attrs = []): string;

    /** <form>開始タグのみ(hidden群はFormが組み立てる)。method="post" 固定、$multipart なら enctype="multipart/form-data"。
     *  attrs に method・action・enctype は指定できない */
    public static function openTag(string $action, bool $multipart, array $attrs = []): string;

    /**
     * 属性の指定を、小文字の属性名をキーにした形にそろえる(['ID' => 'x'] は id の指定。['required'] は ['required' => true])。
     * @return array<string, mixed>
     * @throws FormConfigException 同じ属性を2回指定した場合(大文字と小文字の違いを含む)
     */
    public static function normalizeAttributes(array $attrs): array;
}
```

- `select()` はテンプレートを介在させず `<option>ラベル</option>` を直接構築する唯一のメソッドであるため、**テキストノードのエスケープ責務を本クラスが持つ**: `<option>` のテキスト(choicesのラベル)に `esc_html()`、`value` 属性に `esc_attr()` を適用してから出力する(属性レンダリングは `Support\Html::attributes()` だが、テキストノードはその責務外であることに注意)。choicesがapp定義の定数でなくDB値・タクソノミー名等の動的ソース由来であってもXSSが成立しない。radios/checkboxesのラベルは `OptionView::$label` が生値でありテンプレート側が `esc_html()` する規約(§FieldView)と責務分担が異なる。
- 旧 `FormRenderer`/`FormRendererCollection`/`before_render`/`after_render` のコールバック合成は全廃(DR-16)。「同一引数なら同一出力」が保証されるため、スナップショットテストが可能(P12)。

### 例外

`TMSWP\Core\Exception\Form\` 配下。

```php
namespace TMSWP\Core\Exception\Form;

/** フォーム定義の誤り(重複name・予約名・choices空・型と部品の不一致など)。実装時に気付くべきバグ。
 *  UnknownFieldExceptionの親となるため非final */
class FormConfigException extends \LogicException {}

/** 未定義フィールド名の参照 */
final class UnknownFieldException extends FormConfigException {}

/** 一時ファイル操作の失敗(保管・解決・移動・添付化) */
final class TempFileException extends \RuntimeException {}
```

- 設計方針: **ユーザ入力に起因する異常は例外にせずバリデーションエラーに倒す**(旧ChoiceRuleが入力不正でUnexpectedValueExceptionを投げた問題の修正)。署名済みのトークンの項目がフォームの項目と食い違う場合(デプロイをまたいだ送信)もこれに当たり、入力画面へ戻す。例外は「開発者の定義ミス」(`FormConfigException`。`handle()` の後の `applyDefaults()` のような呼び出し順の誤りは `TMSWP\Core\Exception\LogicException`)と「環境・IO異常」(`TempFileException`、JtiStoreのデータベースのエラーの `TMSWP\Core\Exception\RuntimeException`)のみ。
- 環境・IO異常のうち、送信の処理中に起きたもの(JtiStoreのデータベースのエラー)と `onComplete` が投げた例外は、`handle()` がエラー方針(00-overview.md §5.6)に従って扱う。debug時は投げ、本番はFormのKernelのロガーに `error` で記録して汎用の送信失敗エラーをフォームに出す(§handle())。

---

## 利用例(app側コード)

### 1. 13フィールドのお問い合わせフォーム定義(約30行)

T04の実フォーム(旧実装: actions/tmpl-contact.php 約100行)と同等の内容。`app/Form/ContactForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\{Email, MaxLength, Regex, Required, Retype};
use TMSWP\JaToolkit\Form\KanaTextField;

final class ContactForm
{
    private const SUBJECTS = ['製品について' => '製品について', 'お見積り' => 'お見積り', 'その他' => 'その他'];

    /** 構成済みFormを返すstaticファクトリ(§Formのapp側規約。Formは継承しない) */
    public static function create(): Form
    {
        $tel = new Regex('/\A[0-9\-]{10,13}\z/', message: '{label}は半角数字とハイフンで入力してください。');

        return new Form(
            FormDefinition::create('contact')
                ->text('name', 'お名前', rules: [new Required(), new MaxLength(50)])
                ->add(KanaTextField::create('kana', 'フリガナ', rules: [new Required()], maxLength: 50))
                ->text('company', '会社名', rules: [new Required(), new MaxLength(100)])
                ->text('address', 'ご住所', rules: [new Required(), new MaxLength(200)])
                ->text('phone', '電話番号', rules: [new Required(), $tel])
                ->text('fax', 'FAX番号', rules: [$tel])  // 任意入力: 空ならRegexはスルー
                ->text('email', 'メールアドレス', rules: [new Required(), new Email(), new MaxLength(255)])
                ->text('email_confirm', 'メールアドレス(確認)', rules: [new Required(), new Retype('email')])
                ->select('subject', 'お問い合わせ種別', choices: ['' => '--- お選びください ---'] + self::SUBJECTS, rules: [new Required()])
                ->text('product', '対象製品', rules: [new Required(), new MaxLength(100)])
                ->textarea('detail', 'お問い合わせ内容', rules: [new Required(), new MaxLength(1000)])
                ->radios('contact_method', 'ご希望の連絡方法', choices: ['email' => 'メール', 'phone' => '電話'], rules: [new Required()])
                ->checkbox('privacy', '個人情報の取り扱い', rules: [new Required()], checkedLabel: '同意する')
                ->hideOnConfirm('email_confirm')   // 確認画面には出さない(hidden・パスワードの項目は指定しなくても出さない)
                ->confirm()
                ->onComplete(self::sendMail(...)),
        );
    }

    /** @param array<string, mixed> $values メール送信の実装は rules/form-mail.md のレシピを参照 */
    private static function sendMail(array $values, Form $form): void
    {
        // wp_mail() による自動返信+管理者通知。Log::channel('mail') へ成否を記録。
    }
}
```

### 2. テーマテンプレート(入力/確認/完了をWP標準テンプレート1枚で)

`tmpl-contact.php`(Template Name付きの通常のWPページテンプレート。HTML構造・CSSクラスは完全にテンプレート側の自由):

```php
<?php

declare(strict_types=1);

use App\Form\ContactForm;
use TMSWP\Core\Form\FormStep;

$form = ContactForm::create();
$result = $form->handle();

get_header();
?>

<?php if ($result->step === FormStep::Complete) : ?>
    <p>お問い合わせを受け付けました。</p>

<?php elseif ($result->step === FormStep::Confirm) : ?>
    <?= $form->open() /* _form_id / _nonce / _confirm_token のhiddenを自動埋込み */ ?>
    <dl>
        <?php foreach ($form->confirmFields() as $view) : /* 確認画面に出す項目だけ(§確認画面) */ ?>
            <dt><?= $view->label() ?></dt>
            <dd><?= $view->display() ?></dd>
        <?php endforeach; ?>
    </dl>
    <button type="submit" name="_step" value="back">入力に戻る</button>
    <button type="submit" name="_step" value="submit">送信する</button>
    <?= $form->close() ?>

<?php else : ?>
    <?php foreach ($result->formErrors as $error) : ?>
        <p class="form-alert"><?= esc_html($error) ?></p>
    <?php endforeach; ?>
    <?= $form->open(['class' => 'contact-form']) ?>
    <dl>
        <dt><?= $form->field('name')->labelTag() ?></dt>
        <dd>
            <?= $form->field('name')->input(['class' => 'w-full', 'placeholder' => '山田 太郎']) ?>
            <?= $form->field('name')->errors() ?>
        </dd>

        <dt><?= $form->field('email')->labelTag() ?></dt>
        <dd>
            <?= $form->field('email')->input(['type' => 'email', 'class' => 'w-full']) ?>
            <?= $form->field('email')->errors() ?>
        </dd>

        <dt><?= $form->field('subject')->labelTag() ?></dt>
        <dd><?= $form->field('subject')->select(['class' => 'select']) ?><?= $form->field('subject')->errors() ?></dd>

        <dt>ご希望の連絡方法</dt>
        <dd>
            <?php foreach ($form->field('contact_method')->options() as $opt) : ?>
                <label class="radio"><?= $opt->input() ?> <?= esc_html($opt->label) ?></label>
            <?php endforeach; ?>
            <?= $form->field('contact_method')->errors() ?>
        </dd>
        <!-- ...他フィールドも同様。HTML構造はテンプレートの自由 -->
    </dl>
    <label><?= $form->field('privacy')->checkbox() ?> 個人情報の取り扱いに同意する</label>
    <?= $form->field('privacy')->errors() ?>
    <button type="submit" name="_step" value="confirm">入力内容を確認する</button>
    <?= $form->close() ?>
<?php endif; ?>

<?php get_footer(); ?>
```

完了後にサンクスページへリダイレクトしたい場合(PRG)は、テンプレートではなく `template_redirect` フックで処理する(出力開始前であることが保証されるため):

```php
add_action('template_redirect', static function (): void {
    if (!is_page_template('tmpl-contact.php')) {
        return;
    }
    $result = ContactFormHolder::form()->handle();   // 下記「リクエスト内共有」を参照
    if ($result->step === FormStep::Complete) {
        wp_safe_redirect(home_url('/contact/thanks/'));
        exit;
    }
});
```

`handle()` は冪等なので、テンプレート側で再度 `handle()` を呼んでも完了処理は二重実行されない。フックとテンプレートで同一インスタンスを使うための保持はapp側の責務(最小実装):

```php
final class ContactFormHolder
{
    private static ?Form $form = null;

    public static function form(): Form
    {
        return self::$form ??= ContactForm::create();
    }
}
```

### 2A. テンプレート前処理の明示呼び出しによる3画面遷移(正準形)

フォームの駆動とPRGリダイレクトは、テンプレートの先頭で型付きクラスを明示的に呼ぶ方式で書く。前処理クラスは `handle(): self` のstaticメソッドを持つreadonlyのDTOで、フォームを駆動し、完了ならリダイレクトして `exit` し、それ以外ならテンプレートが使う値を自身のプロパティで返す。テンプレートは先頭で `$vm = ContactPage::handle();` と1回呼ぶだけで、`ContactFormHolder` の手動保持と `template_redirect` フックの二重配線が不要になる。

対象は入力・確認・完了の3画面までである。ウィザード形式(4画面以上)は対象外とする(§スコープ)。

`app/Page/ContactPage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Page;

use App\Form\ContactForm;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\FormStep;

/** page-contact.php の前処理: お問い合わせフォームを駆動し、テンプレートが使う値を返す */
final readonly class ContactPage
{
    private function __construct(
        public Form $form,
        public FormResult $result,
    ) {}

    public static function handle(): self
    {
        $form = ContactForm::create();
        $result = $form->handle();   // バインド+CSRF+送信トークン+検証+状態遷移(冪等)。ページキャッシュからも外す

        // PRG: 完了時はサンクスへリダイレクトする。テンプレートの先頭(get_header() の前)は出力開始前のため安全
        if ($result->step === FormStep::Complete) {
            wp_safe_redirect(home_url('/contact/thanks/'));
            exit;   // 00-overview.md 原則1の例外(PRGのリダイレクト直後)
        }

        return new self($form, $result);
    }
}
```

テンプレート `page-contact.php`(素の WP 固定ページテンプレート):

```php
<?php
/* Template Name: お問い合わせ */

declare(strict_types=1);

use App\Page\ContactPage;
use TMSWP\Core\Form\FormStep;

$vm = ContactPage::handle();   // 出力の前に1回だけ呼ぶ

get_header();
?>
<?php if ($vm->result->step === FormStep::Confirm) : ?>
    <?= $vm->form->open() ?>
    <dl>
        <?php foreach ($vm->form->confirmFields() as $view) : ?>
            <dt><?= $view->label() ?></dt><dd><?= $view->display() ?></dd>
        <?php endforeach; ?>
    </dl>
    <button type="submit" name="_step" value="back">入力に戻る</button>
    <button type="submit" name="_step" value="submit">送信する</button>
    <?= $vm->form->close() ?>
<?php else : ?>
    <?php foreach ($vm->result->formErrors as $error) : ?>
        <p class="form-alert"><?= esc_html($error) ?></p>
    <?php endforeach; ?>
    <?= $vm->form->open(['class' => 'contact-form']) ?>
    <?= $vm->form->field('name')->input(['class' => 'w-full']) ?>
    <?= $vm->form->field('name')->errors() ?>
    <?= $vm->form->field('email')->input(['type' => 'email']) ?>
    <?= $vm->form->field('email')->errors() ?>
    <!-- ...他フィールド -->
    <button type="submit" name="_step" value="confirm">入力内容を確認する</button>
    <?= $vm->form->close() ?>
<?php endif; ?>
<?php get_footer(); ?>
```

この形では、前処理(フォームの駆動とPRG)は `ContactPage::handle()` に分離され、テンプレートは返された型付きの値を読むだけになる。前処理の呼び出しはテンプレートに明示的に書かれるため、どのテンプレートで何が駆動されるかをテンプレートのファイルだけで追える。`example 2` の `ContactFormHolder` + `template_redirect` 方式は、テンプレートの外で駆動する必要がある場合(プラグインのショートコード文脈等。§5)に使う。

### 3. 生年月日つき会員フォーム(複合フィールド・カスタム複合型)

```php
FormDefinition::create('registration')
    ->add(
        KanaTextField::create('kana', 'フリガナ', rules: [new Required()]),
        new BirthDateField('birthday', '生年月日', rules: [new Required()], minYear: 1940),
    )
    // app独自の複合型(郵便番号2分割)も CompositeField 継承で同じ流儀
    ->add(new PostalCodeField('postal_code', '郵便番号', rules: [new Required()]));
```

テンプレート側のパーツ描画:

```php
<?= $form->field('birthday')->part('year')->select() ?>年
<?= $form->field('birthday')->part('month')->select() ?>月
<?= $form->field('birthday')->part('day')->select() ?>日
<?= $form->field('birthday')->errors() ?>
```

### 4. ファイルアップロード(確認画面つき)

旧T06で約80行だった処理。定義:

```php
FormDefinition::create('entry')
    ->file('resume', '履歴書', rules: [
        new UploadFile(),
        new FileExtension(['pdf']),
        MaxFileSize::mb(5),
    ])
    ->confirm()
    ->onComplete(static function (array $values, Form $form): void {
        $attachmentId = $values['resume'] instanceof StoredFile
            ? $values['resume']->toAttachment()
            : 0;
        // ...保存処理
    });
```

テンプレート(入力画面)は `<?= $form->field('resume')->input() ?>`、確認画面は `display()` がファイル名を表示する。確認画面からの戻り時は `<?= $form->field('resume')->hidden() ?>`(= `{name}__token` hidden)を入力画面に置いておくと選択済みファイルが維持される。

### 5. プラグインからの利用(ショートコード/ブロック)

```php
// プラグインのapp/Hook配下等で登録(Hook基底の登録規約はKernel文書に従う)
add_shortcode('contact_form', static function (): string {
    $form = ContactFormHolder::form();
    $form->handle();   // 冪等: template_redirect側で処理済みならキャッシュが返る
    ob_start();
    require __DIR__ . '/../templates/contact-form.php';  // $form を使う部分テンプレート
    return (string) ob_get_clean();
});

// ブロックも render_callback で同形
register_block_type('myplugin/contact-form', [
    'render_callback' => static fn(): string => do_shortcode('[contact_form]'),
]);
```

制約として明文化する事項: ショートコード/ブロックのレンダリングは出力開始後に走るため、**リダイレクトを伴う完了処理は必ず `template_redirect`(またはそれ以前のフック)で `handle()` を先行実行する**。インライン完了表示のみなら先行実行は不要。

### 6. サイト全体のメッセージ調整(functions.php)

`tmswp/form/rule_message` フィルタで上書きする。フィルタは `Rule` インスタンスを受け取るため `instanceof` でルール単位・親クラス単位の判定が書け、プレースホルダ置換の前段で効くため上書き文言内の `{label}` も展開される。

```php
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\Rule\Rule;

add_filter(
    'tmswp/form/rule_message',
    static fn(string $message, Rule $rule): string
        => $rule instanceof Required ? '{label}は必須項目です。' : $message,
    10,
    2,
);
```

---

## 旧iwf2bからの設計根拠(簡潔)

- **Formオブジェクト不在が複雑性の根本原因**だった。旧コアはFieldSet(バインド+検証)とレンダリング部品しか持たず、「POST判定→validate→nonce→確認→完了」を15案件すべてが手書きし、nonce漏れ(T06)・確認画面コピペバグ(T04)・二重送信無対策が実害として発生した。本設計は `Form::handle()` に状態機械を固定し、この制御フローのユーザコードを消滅させる(DR-07)。
- **確認画面のhidden全値再POST+パス再構築は脆弱**(改ざん未検証、WP_CONTENT_DIR配下の任意ファイル参照が可能)だったため、HMAC署名トークン+サーバ側一時保管トークンに置換。セッション案は不採用(マルチサーバ・「コアは状態を持たない」方針との整合、および旧T05のセッション運用が session_start の管轄問題を起こしていた実態)。
- **レンダリングの3層コールバック合成**(FormRenderer/before_render/after_render、マジックインデックス)は、価値(エラー断片とinput断片のecho書き味)に対して複雑性が過大であり、FieldView+FormHtml純関数へ全面置換(DR-16)。echoの書き味とWP標準テンプレート構造はそのまま維持する。
- **Rule群はラインナップを実利用実績で選別**(RequiredRule 104参照〜Regex 21参照の上位+ファイル系)。全プロジェクト未使用の比較系・ExactLength・RequiredUnless・Type・UniqueEmailは収載しない。config配列+リフレクション注入はPHP8のコンストラクタプロモーション+named argumentsへ置換し、`%param%` は `{param}` に改める(sprintf混線バグの根絶)。
- **FieldSetシングルトン・FieldInterfaceの__construct宣言・RuleのFieldSet逆依存**は、テスト困難・静的解析不能の根源として全廃。クロスフィールド検証は `ValidationContext` 注入で解決(DR-09/DR-12)。

## 受け入れ基準

### チェックリスト

実装は以下をすべて満たすこと。

**API/構造**
- [ ] 本文書の全クラス・enum・例外と全メソッドがシグネチャ通りに存在する(`TMSWP\Core\Form\` 配下、Rule群13種・Composite同梱2種を含む)
- [ ] コアのruntime依存が増えていない(psr/log・psr/container以外のcomposer依存なし。DR-14)
- [ ] `FormRenderer` 系・コールバック合成・`__callStatic`・`extract()` が存在しない
- [ ] `Form` がfinal具象クラスであり、`abstract` メソッド・`create(): static`・nullセンチネルコンストラクタが存在しない(コンストラクタは `FormDefinition` 必須引数のみ)
- [ ] `Messages` が静的可変状態を持たない(`override()`/`reset()` が存在せず、サイト全体上書きは `tmswp/form/rule_message` フィルタのみ)
- [ ] 全ファイル `declare(strict_types=1)`・全シグネチャ型宣言・例外は §例外 のとおり

**ライフサイクル**
- [ ] §handle()擬似コードの全分岐(GET初期表示/他フォームID無視/CSRF失敗/不明_step/confirm有効・無効/確認ありSubmit時のValid・Expired・Invalid・項目の食い違い・ファイル消失・今の規則での再検証の失敗・jti再送/確認なしSubmit時の送信トークンのValid・Expired・Invalid・jti再送/スパム対策の拒否/Back時のValid・Invalid)が網羅実装され、各分岐にユニットテストがある
- [ ] `handle()` が冪等である(2回呼んでもonCompleteは1回)
- [ ] 確認画面の無いフォームでも、リロードと二重クリックでonCompleteが再実行されない(統合テスト)
- [ ] onCompleteの例外が、debugでは投げられ、本番ではFormのKernelのロガーに `error` で記録されて汎用の送信失敗エラーが出て、同じトークンで再送できる(統合テスト)
- [ ] JtiStoreのデータベースのエラーが例外になる(`claim`・`release`・`gc`)
- [ ] CSRF失敗・トークンの改ざん・再送の拒否・ファイルの消失・onCompleteの例外が §ログ のとおり記録される
- [ ] フォームのあるページのレスポンスにnocacheのヘッダが付き、`DONOTCACHEPAGE` が定義される(統合テスト)
- [ ] 登録したスパム対策が、入力画面の `open()` に出力され、入力を受け取るPOSTで値の検証の前に検査される
- [ ] `applyDefaults()` が、`handle()` の後・フォームに無い項目・値の形の違い・ファイルの項目で例外になる
- [ ] checkbox/checkboxesのPOSTキー欠損が `''` / `[]` にバインドされる(hiddenハック不要の実証テスト)
- [ ] 同一ページに2フォームを置いた場合、POSTが宛先フォームだけを処理する
- [ ] 利用者の入力(数値のキー、配列のファイル、不正な形のファイルのエントリ、不正なUTF-8、未知のトークンの項目)で例外にならず、項目のエラー(未知のトークンの項目は §handle() の扱い)になる。確認画面の有無の両方で確かめる(統合テスト)
- [ ] 確認画面の項目(`confirmFields()`)が、hidden・パスワード・`hideOnConfirm()` の項目を除き、単一チェックボックスを `checkedLabel` で表示する

**セキュリティ**
- [ ] ConfirmTokenの改ざん(値1文字変更・署名差し替え・formId差し替え)がすべてInvalidになるテスト
- [ ] 署名の鍵が用途ごとに派生され、`wp_salt('auth')` をそのまま鍵にした署名と、別の用途のトークン(送信トークン⇔確認トークン)がInvalidになるテスト
- [ ] 同一jtiの並行2リクエストで `onComplete` が二重実行されないテスト(同一jtiの2本目のINSERTが `rows_affected === 0` となるケースを `$wpdb` スタブで再現し、獲得失敗側が再送拒否になることを検証)
- [ ] `canonicalJson` が連想配列の順序によらず同一出力を返すテスト(決定性)
- [ ] 署名比較が `hash_equals` であること(コードレビュー項目)
- [ ] `TempFileStore::resolve()` が `../` 等のトークン形式違反を `TempFileException` で拒否するテスト
- [ ] POST値からファイルパスを構築するコードが存在しない(`grep` レビュー項目)
- [ ] FieldView/FormHtmlの出力に未エスケープのユーザ値が含まれない(`<script>` 入力のスナップショットテスト)
- [ ] FieldView/FormHtmlのテキストノードが `esc_html()` を通り、`esc_html` が無い環境で別のエスケープに切り替えない
- [ ] パスワードの項目が、再描画のvalue属性・確認画面の表示・ConfirmTokenに出ない(確認画面のあるフォームには定義の時点で置けない)
- [ ] `open()` の `action` と入力部品の `id`・ラベルの `for` が反映され、FWが決める属性(§属性の指定)の指定が `FormConfigException` になる
- [ ] 動的choices(ラベルに `<script>` を含む)を与えた `select()` の出力で `<option>` のラベルが `esc_html()`、valueが `esc_attr()` でエスケープされるスナップショットテスト

**検証/メッセージ**
- [ ] 空値スルー: `''` に対して `Email`/`MaxLength` 等がスキップされ、`Required` だけが失敗するテスト。`'0'` が空扱いされないテスト
- [ ] multipleフィールドへの `MaxLength` が各要素適用され、TypeErrorが発生しないテスト
- [ ] choices付きフィールドへの選択肢外値・配列混入が検証エラー(例外でなく)になるテスト
- [ ] メッセージ解決の優先順(個別 → 英語ソース既定)と、`tmswp/form/rule_message` フィルタによるサイト全体上書き(`instanceof` 判定・フィルタ適用が `{label}` 置換の前段であること)のテスト。`{label}` `{length}` 置換の検証
- [ ] app側 `BirthDateField`: 部分入力エラー・checkdate不正(2/30)・`compose(decompose($v)) === $v` のテスト
- [ ] ja-toolkit `KanaTextField`: 半角カナ・ひらがな入力が正規化されて合格するテスト

**ファイル**
- [ ] `store()` → `resolve()` → `moveTo()`/`toAttachment()` の一連の統合テスト(wp-env)
- [ ] `gc()` が期限内ファイルを消さず期限切れのみ削除するテスト
- [ ] baseDirに `.htaccess`/`index.html` が生成されるテスト

### 検証コマンド

```bash
composer lint      # php-cs-fixer (PSR-12 + strict_types)
composer analyse   # PHPStan level max・baselineなし(szepeviktor/phpstan-wordpress)
composer test      # ユニット: Form層はWP関数をBrain Monkeyでスタブ(wp_verify_nonce/wp_salt/wp_unslash/apply_filters等)。JtiStoreは $wpdb をスタブして検証
composer test:integration  # wp-env + WordPress のテストライブラリ: nonce実検証・TempFileStore実IO・toAttachment
composer qa        # 上記一括(CI同等)
```

ユニットテストの最低限の対象クラス: `ConfirmTokenTest` / `ValidatorTest` / `RuleTest`(全13ルール) / `FormHandleTest`(状態機械全分岐) / `FieldTest` / `CompositeFieldTest` / `FieldViewTest` / `FormHtmlTest` / `MessagesTest` / `JtiStoreTest` / `TempFileStoreTest`。
