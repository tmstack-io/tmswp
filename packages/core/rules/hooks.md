# WPフックの置き場

テーマ/プラグイン固有のフック処理は `app/Hook/` に置く。
静的に書けるフックは `#[Action]` / `#[Filter]` を使う。

## 手順

1. `app/Hook/<Concern>Hook.php` を作る。
2. `#[AsHook]` を付け、`Hook` を継承する。
3. publicメソッドへ `#[Action]` / `#[Filter]` を付ける。

## 完全な実装例

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class ThemeSetupHook extends Hook
{
    #[Action('after_setup_theme')]
    public function setupTheme(): void
    {
        add_theme_support('post-thumbnails');
        add_theme_support('title-tag');
    }

    #[Filter('excerpt_more')]
    public function excerptMore(string $more): string
    {
        return '...';
    }
}
```

## 管理画面・wp_head クリーンアップの実装例

管理メニュー・管理バー・ダッシュボード・wp_head 出力の整理は、削除対象がテーマ毎に異なるためFWはコードを提供しない。`app/Hook/` のフッククラスとして実装する。

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;
use WP_Admin_Bar;

#[AsHook]
final class AdminCleanupHook extends Hook
{
    /** 削除対象はテーマの要件に合わせて決める(以下は一例)。 */
    private const MENUS = ['edit-comments.php', 'index.php'];

    private const ADMIN_BAR_NODES = ['wp-logo', 'comments', 'updates'];

    /** [ウィジェットID, コンテキスト] の組。 */
    private const DASHBOARD_WIDGETS = [
        ['dashboard_primary', 'side'],
        ['dashboard_quick_press', 'side'],
    ];

    #[Action('admin_menu', priority: 999)]
    public function removeAdminMenus(): void
    {
        foreach (self::MENUS as $menu) {
            remove_menu_page($menu);
        }
    }

    #[Action('admin_bar_menu', priority: 999)]
    public function removeAdminBarNodes(WP_Admin_Bar $bar): void
    {
        foreach (self::ADMIN_BAR_NODES as $node) {
            $bar->remove_node($node);
        }
    }

    #[Action('wp_dashboard_setup')]
    public function removeDashboardWidgets(): void
    {
        foreach (self::DASHBOARD_WIDGETS as [$id, $context]) {
            remove_meta_box($id, 'dashboard', $context);
        }

        remove_action('welcome_panel', 'wp_welcome_panel');
    }

    #[Action('init')]
    public function cleanupHead(): void
    {
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'rest_output_link_wp_head');
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        add_filter('emoji_svg_url', '__return_false');
    }
}
```

- 効果を理解した項目だけを削除する。全部入りのコピペをしない。
- 標準ウィジェットの解除が必要な場合も同様に `widgets_init`(priority 11)で `unregister_widget()` を呼ぶメソッドを足す。

## してはいけないこと

- `functions.php` に `add_action()` / `add_filter()` を直書きしない。
- テンプレート内でフック登録しない。
- 静的に書けるフックを `register()` に列挙しない。

## 確認方法

1. `composer qa` が通ること。
2. 対象フックの効果がWordPress上で確認できること。
