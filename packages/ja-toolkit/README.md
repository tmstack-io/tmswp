# tmswp/ja-toolkit

日本語サイト固有の課題を解決するオプトインモジュール集です。導入しただけでは何も起きず、`JaToolkit::enable()` に列挙したモジュールだけが有効化されます。

## モジュール一覧

| モジュール | 効果 |
|---|---|
| `JapaneseSlug` | 日本語タイトル由来のURLエンコード済みスラッグを `{post_type}-{post_id}` 形式へ自動変換する(フォーマットはコンストラクタで変更可) |
| `FullwidthSearchNormalizer` | 検索クエリの全角スペースを半角へ正規化し、連続スペースを1つにまとめる |
| `DisableTexturize` | 和文で不要な英文タイポグラフィ変換(wptexturize)を `run_wptexturize` フィルタで一括無効化する |

## 利用例

`Kernel::boot()` の直後(`init` フック前)に有効化します。

```php
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\DisableTexturize;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;

JaToolkit::enable([
    JapaneseSlug::class,
    FullwidthSearchNormalizer::class,
    DisableTexturize::class,
]);
```

- `init` 発火後の `enable()`、同一モジュールの重複有効化は `LogicException` になります。
- コンストラクタ引数を指定する場合はインスタンスを渡します(例: `new JapaneseSlug('{post_type}-{post_id}')`)。

## Form プリセット

カナ入力は `TMSWP\JaToolkit\Form\KanaTextField` が提供します。WPフックを登録しない静的ファクトリのため、`JaToolkit::enable()` は不要です。

```php
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\JaToolkit\Form\KanaTextField;
use TMSWP\JaToolkit\Form\KanaType;

FormDefinition::create('contact')
    ->add(KanaTextField::create('kana', 'フリガナ', KanaType::Katakana, [new Required()], maxLength: 50));
```

- `KanaType::Katakana` は `mb_convert_kana(..., 'KVC', 'UTF-8')` で正規化し、全角カタカナを検証します。
- `KanaType::Hiragana` は `mb_convert_kana(..., 'KVc', 'UTF-8')` で正規化し、ひらがなを検証します。

## 管理画面や wp_head の整理について

管理メニュー・管理バー・ダッシュボード・wp_head 出力などの汎用クリーンアップは、削除対象がテーマ毎に異なるため本パッケージでは提供しません。app 側の Hook クラスで実装してください(レシピ: `vendor/tmswp/core/rules/hooks.md`)。
