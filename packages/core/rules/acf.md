# ACFでフィールドを管理する

ACFを使う場合は `acf-json` を真実源にし、`wp tmswp acf sync` で型付きアクセサを生成する。
アプリコードで `get_field()` を直書きしない。

## 手順

1. `composer require tmswp/acf-bridge` を実行する。
2. ACF管理画面でフィールドグループを作る。
3. `acf-json/` にJSONが保存されたことを確認する。
4. `wp tmswp acf sync` を実行する。
5. `app/Acf/*Fields.php` の生成アクセサ経由で値を読む。

## 完全な実装例

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\AcfBridge\AcfBridge;

require_once __DIR__ . '/vendor/autoload.php';

Kernel::boot(KernelConfig::theme(appDir: __DIR__ . '/app', appNamespace: 'App'));
AcfBridge::boot();
```

テンプレート:

```php
<?php $fields = \App\Acf\ProductFields::of(get_the_ID()); ?>
<p><?php echo esc_html((string) $fields->price()); ?></p>
```

## MetaBagをACFへ委譲する場合

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
#[UseMetaStorage(AcfMetaStorage::class)]
final class AcfProductPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('acf_product')->label('ACF製品')->labelLanguage(LabelLanguage::Ja);
    }
}
```

## してはいけないこと

- `get_field('price')` / `the_field()` をアプリコードに散らさない。
- `app/Acf/` の生成ファイルを手で編集しない。
- v0.6.0まで、ACFのフィールドグループで管理するキーを `#[MetaSchema]` で宣言しない。起動時に `DuplicateMetaKeyException` になる。それまでは生成アクセサで読み書きし、`#[UseMetaStorage(AcfMetaStorage::class)]` はフィールドグループで管理しないキーにだけ使う。v0.6.0以降は、フィールドグループで管理するキーも `#[MetaSchema]` と `#[UseMetaStorage(AcfMetaStorage::class)]` で宣言できる(rules/meta.md の「使い分け」)。

## 確認方法

1. `wp tmswp acf check` が通ること。
2. `composer qa` が通ること。
3. 管理画面で入力した値が生成アクセサ経由で表示されること。
