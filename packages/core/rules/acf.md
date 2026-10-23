# ACFでフィールドを管理する

ACFを使う場合は `acf-json` を真実源にし、`wp tmswp acf sync` で型付きアクセサを生成する。
アプリコードで `get_field()` を直書きしない。

## 手順

1. `composer require tmswp/acf-bridge` を実行する。
2. `KernelConfig`の`extensions`に`new AcfBridge()`を渡す(テーマとプラグインでそれぞれ登録できる)。
3. ACF管理画面でフィールドグループを作る。
4. `acf-json/`にJSONが保存されたことを確認する(プラグインのacf-jsonから読んだグループは、プラグインのacf-jsonへ書き戻される)。
5. `wp tmswp acf sync`を実行する。acf-bridgeを登録したKernelが2つ以上あるときは`--app=<名前空間>`で対象を選ぶ。
6. `app/Acf/*Fields.php`の生成アクセサ経由で値を読む。

## 完全な実装例

```php
<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;

require_once __DIR__ . '/vendor/autoload.php';

Kernel::boot(KernelConfig::theme(appDir: __DIR__ . '/app', appNamespace: 'App', extensions: [new AcfBridge()]));
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
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
#[UseMetaStorage(AcfMetaStorage::class)]
final class AcfProductPost extends PostType
{
    // ACFのフィールドグループで管理するキーも宣言できる(ストレージがacf-bridgeの申告と同じAcfMetaStorageのため)
    #[MetaSchema(type: MetaValueType::String)]
    public const META_PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('acf_product')->label('ACF製品')->labelLanguage(LabelLanguage::Ja);
    }
}
```

## してはいけないこと

- `get_field('price')` / `the_field()` をアプリコードに散らさない。
- `app/Acf/` の生成ファイルを手で編集しない。
- ACFのフィールドグループで管理するキーを、`#[UseMetaStorage(AcfMetaStorage::class)]`の無いエンティティの`#[MetaSchema]`で宣言しない。起動時に`DuplicateMetaKeyException`になり、`wp tmswp acf check`もE101で失敗する(rules/meta.mdの「ACFのフィールドグループで管理するキー」)。

## 確認方法

1. `wp tmswp acf check` が通ること。
2. `composer qa` が通ること。
3. 管理画面で入力した値が生成アクセサ経由で表示されること。
