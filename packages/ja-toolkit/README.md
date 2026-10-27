# tmswp/ja-toolkit

日本語サイト固有の課題を解決するオプトインモジュール集です。導入しただけでは何も起きません。テーマ・プラグインのルートの`tmswp-kernel.php`で、`KernelConfig`の`extensions`に`JaToolkit`を渡したKernelでだけ、コンストラクタに渡したモジュールが有効になります。

## モジュール一覧

| モジュール | 効果 |
|---|---|
| `JapaneseSlug` | 日本語タイトル由来のURLエンコード済みスラッグを `{post_type}-{post_id}` 形式へ自動変換する。対象は許可リストの投稿タイプだけで(既定は公開の投稿タイプのうちattachment以外)、変換したスラッグの一意性をWordPressで確かめ直す |
| `FullwidthSearchNormalizer` | 検索クエリの全角スペースを半角へ正規化し、連続スペースを1つにまとめる。スペースだけの検索は0件にする |
| `DisableTexturize` | 和文で不要な英文タイポグラフィ変換(wptexturize)を `run_wptexturize` フィルタで一括無効化する |

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

## Form プリセット

カナ入力は `TMSWP\JaToolkit\Form\KanaTextField` が提供します。WPフックを登録しない静的ファクトリです。

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
- 改行・タブ・漢字・英数字は、どちらも通しません。

## 管理画面や wp_head の整理について

管理メニュー・管理バー・ダッシュボード・wp_head 出力などの汎用クリーンアップは、削除対象がテーマ毎に異なるため本パッケージでは提供しません。app 側の Hook クラスで実装してください(レシピ: `vendor/tmswp/core/rules/hooks.md`)。
