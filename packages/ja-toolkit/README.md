# tmswp/ja-toolkit

日本語サイト固有の課題を解決するオプトインモジュール集です。導入しただけでは何も起きません。テーマ・プラグインのルートの`tmswp-kernel.php`で、`KernelConfig`の`extensions`に`JaToolkit`を渡したKernelでだけ、コンストラクタに渡したモジュールが有効になります。

## 動作環境

- PHP 8.3〜8.5(`ext-mbstring`。composer.jsonの`require`)、WordPress 7.0以上
- tmswp/core `^0.2`(composer.jsonの`require`)

## 導入

1. `composer require tmswp/ja-toolkit`を実行します。
2. テーマ・プラグインのルートの`tmswp-kernel.php`で、`extensions`に`new JaToolkit(...)`を渡し、使うモジュールだけをコンストラクタに並べます(下の「利用例」)。

## モジュール一覧

| モジュール | 効果 |
|---|---|
| `JapaneseSlug` | 日本語のタイトル等から作られたURLエンコードのスラッグを、`{post_type}-{post_id}`の形式(変えられる)に置き換える。対象は許可リストの投稿タイプだけで(既定は公開の投稿タイプのうちattachment以外)、置き換えたスラッグの一意性をWordPressで確かめ直す |
| `FullwidthSearchNormalizer` | 検索クエリの全角スペースを半角へ正規化し、連続スペースを1つにまとめる。スペースだけの検索は0件にする |
| `DisableTexturize` | 和文で不要な英文の記号の変換(wptexturize)を、`run_wptexturize`のフィルタでサイト全体で止める |

## 利用例

```php
// tmswp-kernel.php
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
            new JapaneseSlug(),
            new FullwidthSearchNormalizer(),
            new DisableTexturize(),
        ),
    ],
);
```

- モジュールはインスタンスで渡します。同じクラスのモジュールを2つ渡すと、Kernelのbootが`LogicException`になり、何も登録されません。
- 1つの`JaToolkit`のインスタンスは、1つのKernelにだけ登録できます(2つ目は`LogicException`)。テーマとプラグインの両方で使う場合は、それぞれの`tmswp-kernel.php`で`new JaToolkit(...)`を作ります。
- `JapaneseSlug`の形式と対象の投稿タイプは、コンストラクタで変えられます(例: `new JapaneseSlug('{post_type}-{post_id}', ['post', 'news'])`)。形式は`{post_id}`を含み、ほかは`{post_type}`と英小文字・数字・`_`・`-`だけです。`{post_type}`を20文字、`{post_id}`を20桁として数えた長さが200(`post_name`の列の上限)を超える形式は、`LogicException`になります。

## Formのプリセット

カナの入力の項目は`TMSWP\JaToolkit\Form\KanaTextField`が作ります。WordPressのフックを登録しない静的なファクトリです。

```php
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\JaToolkit\Form\KanaTextField;
use TMSWP\JaToolkit\Form\KanaType;

FormDefinition::create('contact')
    ->add(KanaTextField::create('kana', 'フリガナ', KanaType::Katakana, [new Required()], maxLength: 50));
```

- 違反のメッセージの日本語訳は、`JaToolkit`を`KernelConfig`の`extensions`に登録したときに読み込まれます。モジュールを使わずKanaTextFieldだけを使う場合も、モジュールを渡さない`new JaToolkit()`を登録してください。登録しない場合、訳は保証しません。
- メッセージは検証の時点で訳すため、フォームの定義を`init`より前に作っても訳が出ます。
- `KanaType::Katakana`は、半角カタカナ・ひらがな・全角スペースを全角カタカナ・半角スペースに正規化し、全角カタカナ・長音符・中黒・半角スペースだけを通します。
- `KanaType::Hiragana`は、半角カタカナ・カタカナ(ヴを含む)・全角スペースをひらがな・半角スペースに正規化し、ひらがな(ゔを含む)・長音符・中黒・半角スペースだけを通します。
- 漢字・英数字と、値の途中の改行・タブは、どちらも通しません(前後の空白・改行・タブは除去されます)。

## 管理画面や`wp_head`の整理について

管理メニュー・管理バー・ダッシュボード・`wp_head`の出力などの整理は、消す対象がテーマごとに違うため、このパッケージでは提供しません。app側のHookクラスで実装してください(レシピ: `vendor/tmswp/core/rules/hooks.md`)。

## レシピ

- 各モジュールの有効化と設定・JapaneseSlugの形式の規則・KanaTextField: `vendor/tmswp/ja-toolkit/rules/ja-toolkit.md`
- 拡張の登録の仕方: `vendor/tmswp/core/rules/extensions.md`

設計の正本は、TMSWPのmonorepo([tmstack-io/tmswp](https://github.com/tmstack-io/tmswp))の`docs/08-extensions.md`の§3です。

## ライセンス

GPL-2.0-or-later(`LICENSE`)
