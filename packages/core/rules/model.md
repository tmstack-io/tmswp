# 独自DBテーブルを作る

独自テーブルは `Model` と `TableDefinition` で宣言する。SQL文字列と `$wpdb` の直書きは禁止。

## 手順

1. `app/Model/<Name>Model.php` を作る。
2. `#[AsModel]` を付け、`Model` を継承する。
3. `table()` でスキーマを宣言する。
4. `wp tmswp migrate` を実行する。

## 完全な実装例

```php
<?php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class InquiryModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('inquiries')
            ->id()
            ->string('email')
            ->text('body')
            ->datetime('created_at')
            ->index('email', 'email_idx');
    }
}
```

使い方:

```php
\App\Model\InquiryModel::insert([
    'email' => 'info@example.test',
    'body' => 'お問い合わせ本文',
    'created_at' => gmdate('Y-m-d H:i:s'),
]);
```

## してはいけないこと

- `$wpdb->query()` を直接呼ばない。
- `CREATE TABLE` / `ALTER TABLE` を手書きしない。
- `register_activation_hook` へ独自マイグレーションを登録しない。

## 確認方法

1. `composer qa` が通ること。
2. `wp tmswp migrate` が成功すること。
3. `insert()` した行を `query()->first()` で読めること。
