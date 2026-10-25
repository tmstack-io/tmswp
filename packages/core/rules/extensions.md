# 公式拡張を導入する

コアに含まれない機能は公式拡張パッケージを使う。vendor配下をコピーして改変しない。

| パッケージ | 機能 | 導入 |
|---|---|---|
| `tmswp/thumbnail` | 署名付きサムネイルURL | `composer require tmswp/thumbnail` |
| `tmswp/settings-page` | 管理画面設定ページ | `composer require tmswp/settings-page` |
| `tmswp/acf-bridge` | ACF連携 | `composer require tmswp/acf-bridge` |
| `tmswp/ja-toolkit` | 日本語サイト対応 | `composer require tmswp/ja-toolkit` |

## ブートストラップ例

使う拡張だけを明示登録する。acf-bridgeはテーマ・プラグインのルートの`tmswp-kernel.php`が返す`KernelConfig`の`extensions`に渡してKernelごとに登録し、それ以外の拡張は`functions.php` / プラグインのエントリポイントで`Kernel::boot()`の直後に登録する。`tmswp-kernel.php`はWordPressを起動しない`tms-acf`も読むため、WordPressの関数を呼ばない。

`tmswp-kernel.php`:

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
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;
use TMSWP\SettingsPage\SettingsPages;
use TMSWP\Thumbnail\Thumbnail;

Kernel::boot(KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));

Thumbnail::register();
SettingsPages::register();
JaToolkit::enable([
    JapaneseSlug::class,
    FullwidthSearchNormalizer::class,
]);
```

## 設定ページ例

`app/SettingsPage/SiteSettingsPage.php` では `#[AsSettingsPage]` と `SettingsPage` を使い、
保存時は `OptionFormBridge::persist()` で `#[AsOptionGroup]` のオプションへ反映する。

```php
<?php

declare(strict_types=1);

namespace App\SettingsPage;

use App\Option\SiteOption;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormResult;
use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\OptionFormBridge;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPageDefinition;

#[AsSettingsPage]
final class SiteSettingsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'site-settings', pageTitle: 'サイト設定');
    }

    protected function createForm(): Form
    {
        $form = new Form(FormDefinition::create('site_settings')->text('contact_email', '通知先'));
        OptionFormBridge::applyDefaults($form, SiteOption::class);

        return $form;
    }

    protected function onSubmit(FormResult $result): void
    {
        OptionFormBridge::persist($result, SiteOption::class);
    }

    protected function render(?Form $form): void
    {
        // 管理画面のHTMLを出力する。
    }
}
```

## サムネイルURL例

```php
<?php

declare(strict_types=1);

$url = tms_thumb(get_post_thumbnail_id(), 480, 320, ['fit' => 'crop']);
```

## ACF連携例

`acf-json/`を置いたうえで`composer acf:sync`(`vendor/bin/tms-acf sync`)を実行し、生成された`App\Acf\*`クラスを使う。
テーマとプラグインの両方でacf-bridgeを使うときは、それぞれの`tmswp-kernel.php`の`extensions`に`new AcfBridge()`を渡し、それぞれのルートで`tms-acf sync`を実行してKernelごとに生成する。

```php
<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\KernelConfig;

// プラグインのtmswp-kernel.php。本体ファイルのパスはこのファイルの位置から決める。
// acf-jsonはプラグインのディレクトリのacf-json/、生成先はapp/Acf/
return KernelConfig::plugin(__DIR__ . '/my-plugin.php', __DIR__ . '/app', 'Vendor\Plugin', extensions: [new AcfBridge()]);
```

ACFのフィールドグループで管理するキーを`#[MetaSchema]`で宣言するエンティティには、`#[UseMetaStorage(AcfMetaStorage::class)]`を付ける(rules/meta.mdの「ACFのフィールドグループで管理するキー」)。

## してはいけないこと

- 拡張が提供する機能をコピーして自作しない。
- ja-toolkit の全モジュールを無条件に有効化しない。
- ACFの値取得を `get_field()` 直書きで済ませない。`rules/acf.md` を使う。

## 確認方法

1. `composer require` 後に `composer qa` が通ること。
2. 各拡張のREADMEにある最短利用例が動くこと。
