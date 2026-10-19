# テンプレート前処理を分離する

テーマでは `actions/<template>.php` にテンプレート前処理を書ける。ファイルは配列を返すだけにする。
出力・フック登録・404直呼びは禁止。

## 手順

1. テンプレート `page-contact.php` に対応して `actions/page-contact.php` を作る。
2. 必要なデータを返す。
3. テンプレート側では `tms_var()` で読む。

## 完全な実装例

`actions/page-contact.php`:

```php
<?php

declare(strict_types=1);

return [
    'title' => get_the_title(),
];
```

`page-contact.php`:

```php
<?php

declare(strict_types=1);

get_header();
?>
<h1><?php echo esc_html((string) tms_var('title')); ?></h1>
<?php
get_footer();
```

## してはいけないこと

- actionsファイルでechoしない。
- `$wp_query->set_404()` を直接呼ばない。
- 対応テンプレートがない actions ファイルを置かない。

## 確認方法

1. `composer tms:check` が通ること。
2. `composer qa` が通ること。
