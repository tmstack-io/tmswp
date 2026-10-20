# サイト設定値を定義する

サイト設定は backed enum と `OptionAccess` で宣言する。`get_option()` / `update_option()` を直接呼ばない。

## 使い分け

- 入力検証が重要なシステム設定(メールの宛先、APIキー等)は、このオプショングループで宣言し、設定画面はsettings-pageで作る。APIキー等の秘密情報もここに保存してよい(下の「してはいけないこと」の扱いを守る)。
- フロントに出すコンテンツ設定(画像、繰り返し項目等)は、ACFのオプションページで作り、acf-bridgeの生成アクセサ(`XxxOptions`)で読む(rules/acf.md)。同じ値をオプショングループにも宣言しない。
- 投稿・ターム・ユーザーごとの値は設定ではなくメタで扱う(rules/meta.md)。

## 手順

1. `app/Option/SiteOption.php` を作る。
2. `#[AsOptionGroup]` を付けた string backed enum にする。
3. ケースに `#[OptionSchema]` を付ける。
4. 頻繁に読む設定は、enum に明示アクセサ(下の例の `contactEmail()`)を static メソッドで書く。

## 完全な実装例

```php
<?php

declare(strict_types=1);

namespace App\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;

#[AsOptionGroup(prefix: 'site_')]
enum SiteOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: '')]
    case ContactEmail = 'contact_email';

    public static function contactEmail(): string
    {
        return self::ContactEmail->string();
    }
}
```

## キーの渡し方

- 宣言済みのキーは enum のケースで読み書きする(`SiteOption::ContactEmail->string()`)。`SiteOption::repository()->get('contact_email')` のように文字列で読み書きしない。この規約は自動では検査しないため、レビューで確かめる。
- 頻繁に読む設定は、テンプレートから enum のケースを直接呼ばず、明示アクセサ(`SiteOption::contactEmail()`)を呼ぶ。

## 未宣言のキーを読み書きする

- グループの接頭辞の中で、ケースとして宣言していないキーは、`SiteOption::repository()` の `OptionRepository` に文字列で渡して読み書きする。接頭辞は付くが、スキーマの既定値と型の検査は効かない。
- ほかのプラグインのオプション等、接頭辞の無いオプション名は `WpOptionStorage` を直接使う。`get_option()` を直接書かない。

```php
<?php

use App\Option\SiteOption;
use TMSWP\Core\Option\WpOptionStorage;

// 実際のオプション名は site_extra_note(グループの接頭辞が付く)
$note = SiteOption::repository()->string('extra_note');

// 接頭辞の無いオプション名
$siteName = (new WpOptionStorage())->get('blogname');
```

## 書き込みの規則

- `set()` は書き込んだら `true`、書き込まなかったら `false` を返す。`false` は失敗ではなく、同じ値だった場合と、ほかのプラグインのフィルタが書き込みを止めた場合で、保存されている値は変わっていない。データベースのエラーで書き込めなかったら `RuntimeException` が投げられる。
- 同じ値かどうかは WordPress の `update_option()` が決める。サニタイズ(`sanitize_option_{$name}`)やフィルタの結果が保存済みの値と同じになった場合も、`update_option()` が書き込まずに `false` を返すので `false` になる。
- 宣言済みのケースには、`#[OptionSchema]` の型に合う値だけを渡す(`MetaValueType::Boolean` なら bool)。合わない値は `InvalidArgumentException` になる。フォームの文字列は呼び出し側で型を変換してから渡す。`MetaValueType::String` のケースに `Stringable` のオブジェクトを渡しても `InvalidArgumentException` になる(`(string)` にしてから渡す)。
- 値を消すときは `delete()` を使う。`null` を `set()` すると `InvalidArgumentException` になり、保存されない。
- `deleteAll()` は実際に削除したオプション名だけを返す。

## テスト

- 既定の保存先 `WpOptionStorage` は、テストのために別の実装へ差し替えられない(final のクラスで、Container は同じ id に別の型を登録できない)。`repository()` も差し替えない。設定を読み書きするコードは、wp-env の統合テストで、本物の WordPress の保存先に対して確かめる。

## してはいけないこと

- オプション名を文字列で直書きしない。
- 巨大な配列を1オプションに保存しない。
- APIキー等の秘密情報をオプションに保存する場合も、テンプレートへの出力・ログへの記録・フロントへの公開(REST等)をしない。
- `#[OptionSchema]` をenumのケース以外(ケースでない定数・enum自身・メソッド・引数)に付けない(起動時に `InvalidSchemaException` になる)。`#[AsOptionGroup]` の無い enum のケースにも付けない(debug の boot と `wp tmswp discover` で `DiscoveryException` になる)。
- `set()` の戻り値 `false` を失敗として扱わない(保存値は変わっていない)。

## 確認方法

1. `composer qa` が通ること。
2. `SiteOption::ContactEmail->set()` 後に値が取得できること。
