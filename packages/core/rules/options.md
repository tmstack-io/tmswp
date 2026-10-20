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

## 書き込みの規則

- `set()` は書き込んだら `true`、書き込まなかったら `false` を返す。`false` は失敗ではなく、同じ値だった場合と、ほかのプラグインのフィルタが書き込みを止めた場合で、保存されている値は変わっていない。データベースのエラーで書き込めなかったら `RuntimeException` が投げられる。
- 宣言済みのケースには、`#[OptionSchema]` の型に合う値だけを渡す(`MetaValueType::Boolean` なら bool)。合わない値は `InvalidArgumentException` になる。フォームの文字列は呼び出し側で型を変換してから渡す。
- 値を消すときは `delete()` を使う。`null` を `set()` すると `InvalidArgumentException` になり、保存されない。
- `deleteAll()` は実際に削除したオプション名だけを返す。

## してはいけないこと

- オプション名を文字列で直書きしない。
- 巨大な配列を1オプションに保存しない。
- APIキー等の秘密情報をオプションに保存する場合も、テンプレートへの出力・ログへの記録・フロントへの公開(REST等)をしない。
- `#[OptionSchema]` をenumのケース以外(ケースでない定数・enum自身・メソッド・引数)に付けない(起動時に `InvalidSchemaException` になる)。
- `set()` の戻り値 `false` を失敗として扱わない(保存値は変わっていない)。

## 確認方法

1. `composer qa` が通ること。
2. `SiteOption::ContactEmail->set()` 後に値が取得できること。
