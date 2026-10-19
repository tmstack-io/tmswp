# テンプレートパーツを扱う

WordPress標準テンプレート階層を使い、パーツは `get_template_part()` または `Template::part()` で読む。
パーツ内では `$args` だけを入力にする。

## 手順

1. `parts/card.php` を作る。
2. テンプレートから `$args` を渡す。
3. パーツ内で必ずエスケープして出力する。

## 完全な実装例

```php
<?php

declare(strict_types=1);

use TMSWP\Core\View\Template;

Template::part('parts/card', ['title' => get_the_title()]);
```

`parts/card.php`:

```php
<?php

declare(strict_types=1);

/** @var array{title: string} $args */
?>
<article>
    <h2><?php echo esc_html($args['title']); ?></h2>
</article>
```

## してはいけないこと

- パーツで `extract()` を使わない。
- パーツでグローバル変数に依存しない。
- `template_include` でテンプレート階層を迂回しない。

## 確認方法

1. `composer qa` が通ること。
2. パーツが表示され、渡した値が反映されること。
