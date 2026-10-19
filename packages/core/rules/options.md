# サイト設定値を定義する

サイト設定は backed enum と `OptionAccess` で宣言する。`get_option()` / `update_option()` を直接呼ばない。

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

## してはいけないこと

- オプション名を文字列で直書きしない。
- 巨大な配列を1オプションに保存しない。
- 秘密情報をオプションで管理しない。

## 確認方法

1. `composer qa` が通ること。
2. `SiteOption::ContactEmail->set()` 後に値が取得できること。
