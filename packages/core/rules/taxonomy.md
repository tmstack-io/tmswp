# カスタムタクソノミーを追加する

タクソノミー1つにつきクラス1つを `app/Tax/` に置く。`register_taxonomy()` は直接呼ばない。

## 手順

1. 紐付け先の投稿タイプクラスを用意する。
2. `app/Tax/<Name>Tax.php` を作る。
3. `#[AsTaxonomy]` を付け、`TMSWP\Core\Tax\Taxonomy` を継承する。
4. `composer qa` を通す。

## 完全な実装例

`app/Tax/NewsCategoryTax.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tax;

use App\Post\NewsPost;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;

#[AsTaxonomy]
final class NewsCategoryTax extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('news_category')
            ->label('お知らせカテゴリ')
            ->labelLanguage(LabelLanguage::Ja)
            ->objectType(NewsPost::class)
            ->hierarchical()
            ->showInRest();
    }
}
```

## テンプレートでの使い方

```php
<?php foreach (\App\Tax\NewsCategoryTax::all() as $term) : ?>
    <a href="<?php echo esc_url((string) \App\Tax\NewsCategoryTax::url($term)); ?>">
        <?php echo esc_html($term->name); ?>
    </a>
<?php endforeach; ?>
```

## してはいけないこと

- `register_taxonomy()` を直接呼ばない。
- `definition()` の中で WP 関数や `__()` を呼ばない。Kernel が boot の時点(init より前)でメタスキーマの登録のために呼び、結果を使い回す。
- 投稿タイプのスラッグ文字列を手で重複管理しない。`objectType()` には投稿タイプクラスを渡す。

## 確認方法

1. `composer qa` が通ること。
2. 投稿編集画面にタクソノミーUIが出ること。
