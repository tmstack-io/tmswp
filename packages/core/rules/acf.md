# ACFでフィールドを管理する

ACFを使う場合は`acf-json`を真実源にし、`composer acf:sync`(`vendor/bin/tms-acf sync`)で型付きアクセサを生成する。
アプリコードで `get_field()` を直書きしない。

## 手順

1. `composer require tmswp/acf-bridge` を実行する。
2. テーマ・プラグインのルートの`tmswp-kernel.php`が返す`KernelConfig`の`extensions`に`new AcfBridge()`を渡す(テーマとプラグインでそれぞれ登録できる)。
3. composer.jsonの`scripts`に`"acf:check": "tms-acf check"`と`"acf:sync": "tms-acf sync"`を足す(スターターには含まれない)。
4. ACF管理画面でフィールドグループを作る。
5. `acf-json/`にJSONが保存されたことを確認する(プラグインのacf-jsonから読んだグループは、プラグインのacf-jsonへ書き戻される)。
6. テーマ・プラグインのルートで`composer acf:sync`(`vendor/bin/tms-acf sync`)を実行する。WordPressを起動せずに`tmswp-kernel.php`の構成で生成する。別のディレクトリからは`--root=<ルート>`で対象を選ぶ。
7. `app/Acf/*Fields.php`の生成アクセサ経由で値を読む。

## 完全な実装例

`tmswp-kernel.php`(テーマのルート。WordPressの関数を呼ばない):

```php
<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\KernelConfig;

return KernelConfig::theme(appDir: __DIR__ . '/app', appNamespace: 'App', extensions: [new AcfBridge()]);
```

`functions.php`:

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;

require_once __DIR__ . '/vendor/autoload.php';

Kernel::boot(KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
```

テンプレート:

```php
<?php $fields = \App\Acf\ProductFields::of(get_the_ID()); ?>
<p><?php echo esc_html((string) $fields->price()); ?></p>
```

## MetaBagをACFへ委譲する場合

`AcfMetaStorage`は、ACFの値の書き込みのAPI・フィルタ(`update_field()`・`acf/update_value`等)とpost_idの解決(`acf/validate_post_id`)を通さずに、メタと参照のメタ(`_{キー}`)を`WpMetaStorage`と同じ規約で直接読み書きする(文書09 §8.1)。`#[MetaSchema]`で宣言できるACFのフィールドのキーは、単純な値の型(`AcfMetaStorage::SUPPORTED_FIELD_TYPES`。text・number・true_false・date_picker等。selectはmultipleでないもの(配列の値は空の配列も含めて拒否し、ほかの値は共通のメタの規約に従う)、radioはsave_other_choiceでないもの、image・fileは単一の添付ファイルのID(int))のフィールドだけである。

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
    // ACFのフィールドグループで管理するキーも宣言できる(ストレージがacf-bridgeの申告と同じAcfMetaStorageのため)。
    // 宣言できるのは、単純な値の型(AcfMetaStorage::SUPPORTED_FIELD_TYPES)のフィールドだけ
    #[MetaSchema(type: MetaValueType::String)]
    public const META_PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('acf_product')->label('ACF製品')->labelLanguage(LabelLanguage::Ja);
    }
}
```

flexible_contentのgetterは、レイアウトごとの型の別名(`Sections_Hero`等)のunionの`list<>`を返す。`acf_fc_layout`で`match`すると、PHPStanが各レイアウトのキーを型どおりに扱える:

```php
<?php foreach (\App\Acf\PageFields::of(get_the_ID())->sections() as $section) : ?>
    <?php echo match ($section['acf_fc_layout']) {
        'hero' => '<h2>' . esc_html($section['title']) . '</h2>',
        'text_block' => wp_kses_post($section['body']),
    }; ?>
<?php endforeach; ?>
```

## してはいけないこと

- `get_field('price')` / `the_field()` をアプリコードに散らさない。
- `app/Acf/` の生成ファイルを手で編集しない。
- ACFのフィールドグループのlocationに`block`(ACF Blocks)を使わない。acf-bridgeはACF Blocksのアクセサを生成せず、syncとcheckがW006を出す(`check --strict`は失敗する)。独自ブロックは、フレームワークの独自ブロックの方式(文書12 §1.11)で作る。
- ACFのフィールドグループで管理するキーを、`#[UseMetaStorage(AcfMetaStorage::class)]`の無いエンティティの`#[MetaSchema]`で宣言しない。起動時に`DuplicateMetaKeyException`になり、`wp tmswp acf check`もE101で失敗する(E101はKernelのbootが要るため、WordPressを起動しない`tms-acf check`では検査しない)(rules/meta.mdの「ACFのフィールドグループで管理するキー」)。
- 単純な値の型でないACFのフィールド(gallery・relationship・group・repeater等)のキーを、`#[UseMetaStorage(AcfMetaStorage::class)]`のエンティティの`#[MetaSchema]`で宣言しない。`AcfMetaStorage`での書き込みが`AcfBridgeRuntimeException`になる。生成アクセサ(`XxxFields`)で読み書きする。

## 確認方法

1. `vendor/bin/tms-acf check --strict`(`composer acf:check -- --strict`)と、E101を含めて検査する`wp tmswp acf check --strict`が通ること。
2. `composer qa` が通ること。
3. 管理画面で入力した値が生成アクセサ経由で表示されること。
