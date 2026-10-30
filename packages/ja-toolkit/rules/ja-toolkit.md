# 日本語サイト向けの調整を有効にする(tmswp/ja-toolkit)

日本語のタイトルから作られるURLエンコードのスラッグの置き換え、全角スペースを含む検索語の正規化、wptexturizeの無効化を、必要なモジュールだけ選んで有効にする。フォームのフリガナの項目(カタカナ・ひらがなの正規化と検証)も作れる。
例の名前空間`App\`は、プロジェクトの名前空間(`tmswp-kernel.php`の`KernelConfig`の`appNamespace`)に読み替える。例のテキストドメイン`my-theme`は、テーマ・プラグインのテキストドメインに置き換える。

| 名前 | 種類 | 効果 |
|---|---|---|
| `TMSWP\JaToolkit\Module\JapaneseSlug` | モジュール | URLエンコードの文字を含むスラッグを、`{post_type}-{post_id}`(形式は変えられる)に置き換える |
| `TMSWP\JaToolkit\Module\FullwidthSearchNormalizer` | モジュール | 検索語の全角スペースを半角にし、連続するスペースを1つにまとめる。スペースだけの検索語は0件にする |
| `TMSWP\JaToolkit\Module\DisableTexturize` | モジュール | wptexturize(引用符・ダッシュ等の英文の記号の変換)を、`run_wptexturize`のフィルタでサイト全体で止める |
| `TMSWP\JaToolkit\Form\KanaTextField` | フォームの項目 | カタカナ・ひらがなの入力の項目を作る。WordPressのフックは登録しない |

管理メニュー・管理バー・ダッシュボード・`wp_head`の出力の整理は、ja-toolkitに無い。app側のHookクラスで書く(vendor/tmswp/core/rules/hooks.md)。

## 手順

1. `composer require tmswp/ja-toolkit`を実行する(PHPの`ext-mbstring`が要る)。
2. `tmswp-kernel.php`の`KernelConfig::theme()` / `KernelConfig::plugin()`の`extensions`に`new JaToolkit(...)`を渡し、コンストラクタに使うモジュールのインスタンスだけを並べる。渡さないモジュールは何もしない。
3. フリガナの項目を使うときは、フォームの定義で`KanaTextField::create()`を`add()`に渡す。モジュールを使わないときも、訳(検証のメッセージ)の読み込みのため`new JaToolkit()`を`extensions`に渡す。
4. 下の「確認方法」で、各モジュールの効果を確かめる。

## 完全な実装例

`tmswp-kernel.php`(テーマ。お知らせ(`news`)と投稿だけのスラッグを`news-123`・`post-45`の形にし、検索語の正規化とwptexturizeの無効化を有効にする):

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\DisableTexturize;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;

return KernelConfig::theme(
    __DIR__ . '/app',
    'App',
    extensions: [
        new JaToolkit(
            new JapaneseSlug('{post_type}-{post_id}', ['post', 'news']),
            new FullwidthSearchNormalizer(),
            new DisableTexturize(),
        ),
    ],
);
```

`app/Form/ReservationForm.php`(フリガナの項目を持つフォームの定義。画面の流れとメールはvendor/tmswp/core/rules/form.mdのとおりに作る):

```php
<?php

declare(strict_types=1);

namespace App\Form;

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\JaToolkit\Form\KanaTextField;
use TMSWP\JaToolkit\Form\KanaType;

final class ReservationForm
{
    public static function create(): Form
    {
        return new Form(
            FormDefinition::create('reservation')
                ->text('full_name', __('Name', 'my-theme'), [new Required(), new MaxLength(50)])
                ->add(KanaTextField::create('full_name_kana', __('Name (katakana)', 'my-theme'), KanaType::Katakana, [new Required()], maxLength: 50))
                ->add(KanaTextField::create('child_name_kana', __('Child name (hiragana)', 'my-theme'), KanaType::Hiragana, maxLength: 50))
                ->text('email', __('Email', 'my-theme'), [new Required(), new Email(), new MaxLength(254)])
                ->confirm(),
        );
    }
}
```

## モジュールの登録の規則

- モジュールはインスタンスで渡す(引数の無いモジュールも`new DisableTexturize()`のように渡す)。同じクラスのモジュールを2つ渡すと、Kernelのbootが`LogicException`で止まり、どのモジュールも登録されない。
- 1つの`JaToolkit`のインスタンスは、1つのKernelにだけ登録できる(2つ目は`LogicException`)。テーマとプラグインの両方で使うときは、それぞれの`tmswp-kernel.php`で`new JaToolkit(...)`を作る。
- モジュールとKanaTextFieldの訳は、`JaToolkit`を登録したKernelのbootの中で登録される。bootの後に有効・無効を切り替えるAPIは無い。
- `tmswp-kernel.php`とモジュールのコンストラクタは、WordPressを起動しない経路からも読まれる。モジュールの引数に、WordPressの関数の戻り値(`get_post_types()`等)を渡さない。

## JapaneseSlug

`new JapaneseSlug(string $format = '{post_type}-{post_id}', ?array $postTypes = null)`

- 置き換えるのは、`wp_unique_post_slug`のフィルタに来たスラッグが、URLエンコードした文字(`%e3%81%82`等)を含むときだけである。日本語のタイトルから自動で作られたスラッグも、編集者が日本語で入力したスラッグも置き換わる。英数字のスラッグはそのまま残る。
- 投稿のIDが決まっていない時点(新規の投稿の最初の保存の前)は置き換えず、IDが決まった後の保存で置き換える。モジュールを有効にする前に保存した投稿のスラッグは、次にその投稿を保存するまで変わらない。
- 置き換えたスラッグは、WordPressの一意性の確認にもう一度通す。重なれば`news-123-2`のように接尾辞が付く。

`$format`の規則(違反はコンストラクタの`LogicException`。debugに関係なく`tmswp-kernel.php`を読んだ時点で止まる):

- `{post_id}`を必ず含む。
- プレースホルダは`{post_type}`と`{post_id}`だけ。その外に書けるのは英小文字・数字・`_`・`-`だけである(大文字・`%`・`/`・空白・ほかの波括弧は不可)。例: `'{post_type}-{post_id}'`・`'{post_id}'`・`'item_{post_id}'`。
- `{post_type}`を20文字、`{post_id}`を20桁として数えた長さが、200(`wp_posts`の`post_name`の列の上限)以下である(出現するたびに数える)。

`$postTypes`の規則:

- `null`(既定)なら、置き換えの時点で公開(`public`)の投稿タイプのうち`attachment`以外が対象である(post・pageと、公開のカスタム投稿タイプ)。
- 配列を渡すと、その投稿タイプのキーだけが対象である。空の配列と、投稿タイプのキーの形(英小文字・数字・`_`・`-`の1〜20文字)でない要素は`LogicException`。投稿タイプが登録済みかは確かめない。

`JapaneseSlug`は、サイトの1つのKernelにだけ渡す。2つのKernelで違う形式を渡すと、先にフィルタを登録した側が置き換え、後の側は(URLエンコードした文字が無くなるため)何もしない。

## FullwidthSearchNormalizer

- 検索のクエリ(`is_search()`が真のもの。メインのクエリとサブのクエリの両方)の`s`を、`pre_get_posts`で正規化する。全角スペースを半角にし、連続するスペースを1つにまとめ、前後のスペースを取る。
- 全角・半角のスペースだけの検索語は、`s`を空にして0件にする(`is_search()`は真のまま。検索結果のテンプレートが0件として表示する)。WordPressは`s`が空の検索を全件にするため、ja-toolkitはスペースだけの検索語を「該当なし」として扱う。
- 正規化した検索語は`get_search_query()`にも反映される。検索フォームの値を出力するときは、`get_search_query()`(エスケープ済み)を使う。

## DisableTexturize

- `run_wptexturize`のフィルタで`false`を返し、wptexturizeを止める。タイトル・本文・抜粋・コメント・ウィジェット等、wptexturizeを通るすべての出力が対象で、ほかのテーマ・プラグインの出力も変わらなくなる。
- 英文の記号の変換(`"`を全角の引用符にする等)が要るサイトでは、このモジュールを渡さない。

## KanaTextField

`KanaTextField::create(string $name, string $label, KanaType $type = KanaType::Katakana, array $rules = [], int $maxLength = 100): Field`

- 値は、前後の空白を除いた後に正規化してから検証する。半角カタカナを全角にし、濁点・半濁点を1文字にまとめる。
  - `KanaType::Katakana`: ひらがなをカタカナに、全角スペースを半角にする。全角カタカナ(ヴ・ヵ・ヶを含む)・長音符(ー)・中黒(・)・半角スペースだけを通す。
  - `KanaType::Hiragana`: カタカナをひらがなに、全角スペースを半角にする。ひらがな(ゔ・ゕ・ゖを含む)・長音符・中黒・半角スペースだけを通す。
  - 漢字・英数字と、値の途中の改行・タブは、どちらも通さない(前後の改行・タブは空白の除去で消える)。
- `$rules`は、カナの検証と`MaxLength($maxLength)`より先に検証する規則である(`Required`等)。`$rules`に`Required`が無ければ、空の値はエラーにならない。文字数は正規化の後の値で数える。
- 違反のメッセージは「{label}は全角カタカナで入力してください。」・「{label}はひらがなで入力してください。」で、検証の時点で訳す。`JaToolkit`を登録していないKernelでは、訳が出ることを保証しない。
- 項目の名前は、WordPressの公開のクエリ変数(`name`等)と同じにしない(フロントのフォームでは、coreのFormが設定の誤りとして止める)。上の例では`full_name_kana`にしている。

## してはいけないこと

- ja-toolkitのモジュールを、必要かどうかを確かめずにすべて渡さない。特にDisableTexturizeは、サイト全体の出力を変える。
- 同じクラスのモジュールを1つの`JaToolkit`に2つ渡さない。1つの`JaToolkit`のインスタンスを2つのKernelに渡さない。
- `JapaneseSlug`の`$format`に、`{post_id}`の無い形式や、大文字・`%`・`/`・ほかのプレースホルダを含む形式を渡さない。
- `JapaneseSlug`を、違う形式で2つのKernelに渡さない。
- スラッグの置き換え・検索語の正規化・wptexturizeの無効化を、app側のフックで同じことをして重ねない。
- `init`で`$_GET['s']`を書き換えて検索語を正規化しない(FullwidthSearchNormalizerはクエリのオブジェクトの側で正規化する)。
- KanaTextFieldの値に、自分でもう一度`mb_convert_kana()`をかけない。項目のfiltersが正規化する。
- KanaTextFieldだけを使うときに、`JaToolkit`の登録を省かない。

## 確認方法

1. `composer qa`が通ること。
2. JapaneseSlug: 対象の投稿タイプで日本語のタイトルの投稿を作って公開し、パーマリンクのスラッグが`{post_type}-{投稿のID}`(上の例では`news-123`)になること。英数字のスラッグを入力した投稿は、そのまま残ること。対象でない投稿タイプ(上の例では固定ページ)では、日本語のスラッグのまま残ること。
3. FullwidthSearchNormalizer: `/?s=東京%E3%80%80大阪`(全角スペースを挟む)で、「東京 大阪」として検索されること。`/?s=%E3%80%80`(全角スペースだけ)で、検索結果が0件になること。
4. DisableTexturize: 本文に`"quote"`や`--`を書き、フロントでそのまま表示される(全角の引用符・ダッシュにならない)こと。
5. KanaTextField: フリガナに半角カナ・ひらがなを入れて送信し、確認画面で全角カタカナになっていること。漢字・英字を入れて送信し、「{項目名}は全角カタカナで入力してください。」のエラーが出ること。

## 発展

- 拡張の登録の仕方と制約: vendor/tmswp/core/rules/extensions.md
- フォームの画面の流れ・メール: vendor/tmswp/core/rules/form.md・form-mail.md
- 管理画面・`wp_head`の整理: vendor/tmswp/core/rules/hooks.md
- 設計の正本: TMSWPのmonorepoの[docs/08-extensions.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/08-extensions.md)の「tmswp/ja-toolkit — 日本語サイト対応」の節
