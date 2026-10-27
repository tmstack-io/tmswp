# 公式拡張を導入する

コアに含まれない機能は公式拡張パッケージを使う。vendor配下をコピーして改変しない。
この文書は登録の仕方と主な使い方の手引きである。各拡張の詳しい仕様は、TMSWPの設計文書のdocs/08-extensions.md(thumbnail・settings-page・ja-toolkit)とdocs/09(acf-bridge)にある。

| パッケージ | 機能 | 導入 | 入口のクラス |
|---|---|---|---|
| `tmswp/thumbnail` | 署名付きサムネイルURL | `composer require tmswp/thumbnail` | `TMSWP\Thumbnail\Thumbnail` |
| `tmswp/settings-page` | 管理画面設定ページ | `composer require tmswp/settings-page` | `TMSWP\SettingsPage\SettingsPages` |
| `tmswp/acf-bridge` | ACF連携 | `composer require tmswp/acf-bridge` | `TMSWP\AcfBridge\AcfBridge` |
| `tmswp/ja-toolkit` | 日本語サイト対応 | `composer require tmswp/ja-toolkit` | `TMSWP\JaToolkit\JaToolkit` |

## 登録の仕方

拡張は、`composer require`しただけでは何もしない。使う拡張だけを、テーマ・プラグインのルートの`tmswp-kernel.php`で、`KernelConfig::theme()` / `KernelConfig::plugin()`の`extensions`に入口のクラスのインスタンスとして渡す。拡張は、渡したKernelでだけ有効になる。Kernelは、bootの中で`extensions`の各インスタンスの`register()`を、渡した順に1回ずつ呼ぶ。

`tmswp-kernel.php`は、WordPressを起動しない`tms-acf`(acf-bridge)も読む。このファイルの中と、拡張・設定のクラス(`ThumbnailConfig`・`BridgeConfig`・ja-toolkitのモジュール等)のコンストラクタでは、WordPressの関数(`__()`・`get_option()`等)を呼ばない。

テーマの`tmswp-kernel.php`:

```php
<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;
use TMSWP\SettingsPage\SettingsPages;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;

return KernelConfig::theme(
    __DIR__ . '/app',
    'App',
    extensions: [
        new AcfBridge(),
        new SettingsPages(),
        new Thumbnail(new ThumbnailConfig(defaultQuality: 80)),
        new JaToolkit(new JapaneseSlug(), new FullwidthSearchNormalizer()),
    ],
);
```

テーマの`functions.php`(プラグインは本体ファイル)は、このファイルの`KernelConfig`でbootするだけにする。`Kernel::boot()`の後に拡張を登録するAPIは無い。

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;

Kernel::boot(KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
```

テーマとプラグインの両方で拡張を使うときは、それぞれの`tmswp-kernel.php`で別のインスタンスを`new`して渡す。拡張はKernelごとに別々に動く。ただしthumbnailは、どちらか1つのKernelにだけ登録する(下の「制約」)。

```php
<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\JaToolkit\JaToolkit;

// プラグインのtmswp-kernel.php。本体ファイルのパスはこのファイルの位置から決める。
// thumbnailはテーマのKernelに登録済みなので、ここには渡さない
return KernelConfig::plugin(
    __DIR__ . '/my-plugin.php',
    __DIR__ . '/app',
    'Vendor\Plugin',
    extensions: [new AcfBridge(), new JaToolkit()],
);
```

## 制約

- 1つのインスタンスは、1つのKernelにだけ登録できる。同じインスタンスを2つのKernelに渡すと、2つ目のKernelの`register()`が例外を投げる。同じクラスの拡張を同じKernelに2つ渡すと、`KernelConfig`が`KernelException`を投げる。どちらも設定の誤りとして、debugに関係なく起動を止める。
- thumbnailを登録できるKernelは、サイトに1つだけである。2つ目のKernelの`register()`は`LogicException`になる。`tms_thumb()`・RESTのルート・`wp tmswp thumb`は、登録したKernelのdebug・ロガーと、そのKernelに渡した`ThumbnailConfig`に従う。thumbnailを登録したKernelが無い状態で`tms_thumb()`を呼ぶと`LogicException`になる。
- thumbnailは、訪問者(ログインしていないユーザー)からREST APIのルート`tmswp/v1/thumb`が使えることを前提にする。uploadsはローカルのファイルシステムに置く。
- ja-toolkitのKanaTextFieldの日本語訳(検証メッセージ)は、`JaToolkit`をKernelに登録したときに読み込まれる。モジュールを使わずKanaTextFieldだけを使う場合も、モジュールを渡さない`new JaToolkit()`を登録する。
- settings-pageの設定ページでは、確認画面を使えない。`createForm()`が確認画面の有効なフォームを返すと、`LogicException`になる。
- settings-pageのslugは、起動したすべてのKernelの設定ページの中で一意にする。重複すると`LogicException`になる。

## サムネイルURL例

`tms_thumb(int|string $src, ?int $width = null, ?int $height = null, array $options = []): ?string`で、署名付きのサムネイルのURLを得る。`$src`は添付ファイルのIDか、uploads配下の画像のURLである。

- `$options`のキーは、`crop`(bool。既定は`ThumbnailConfig`の`defaultCrop`で`true`)と`quality`(1〜100の整数。既定は`defaultQuality`)だけを使う。ほかのキー・型の違う値は、debugに関係なく`InvalidThumbOptionException`になる。
- 画像が無いとき(アイキャッチの未設定で`$src`が`0`、添付ファイルやその実体が無い)は`null`を返す。テンプレートでは`null`を分岐し、`<img>`を出さない。

```php
<?php

declare(strict_types=1);

// get_post_thumbnail_id()は投稿が無いとfalseを返すので、0(画像が無い)にしてから渡す。
// アイキャッチが無いとnullを返すので、imgを出さない
$thumb = tms_thumb((int) get_post_thumbnail_id(), 480, 320, ['crop' => true, 'quality' => 70]);
?>
<?php if ($thumb !== null) : ?>
<img src="<?php echo esc_url($thumb); ?>" alt="">
<?php endif; ?>
```

## 設定ページ例

`app/SettingsPage/SiteSettingsPage.php`では`#[AsSettingsPage]`と`SettingsPage`を使い、
保存時は`OptionFormBridge::persist()`で`#[AsOptionGroup]`のオプションへ反映する。
`app/`に置いた`#[AsSettingsPage]`のクラスは、`SettingsPages`を登録したKernelが属性探索で見つける。`app/`の外のクラスは、`new SettingsPages([OtherPage::class])`のようにコンストラクタに渡す。
`createForm()`は確認画面の無いフォームを返す(上の「制約」)。

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

## 日本語サイト対応例

ja-toolkitは、`JaToolkit`のコンストラクタに渡したモジュールだけを有効にする(上の「登録の仕方」の例)。モジュールは`TMSWP\JaToolkit\Module\`の`JapaneseSlug`(日本語スラッグの変換)・`FullwidthSearchNormalizer`(全角スペースの検索の正規化)・`DisableTexturize`(wptexturizeの無効化)で、どれもインスタンスで渡す。

カナの入力項目は、`KanaTextField::create()`で作る。フォーム定義の`add()`に渡す。正規化(半角カナの全角化、ひらがなとカタカナの変換)と文字種の検証が付く。

```php
<?php

declare(strict_types=1);

namespace App\Form;

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\JaToolkit\Form\KanaTextField;
use TMSWP\JaToolkit\Form\KanaType;

final class ProfileForm
{
    public static function create(): Form
    {
        return new Form(
            FormDefinition::create('profile')
                ->text('full_name', __('Name', 'my-theme'), [new Required()])
                ->add(KanaTextField::create('name_kana', __('Name (katakana)', 'my-theme'), KanaType::Katakana, [new Required()], maxLength: 50))
        );
    }
}
```

## ACF連携例

`acf-json/`を置いたうえで`composer acf:sync`(`vendor/bin/tms-acf sync`)を実行し、生成された`App\Acf\*`クラスを使う。
テーマとプラグインの両方でacf-bridgeを使うときは、それぞれの`tmswp-kernel.php`の`extensions`に`new AcfBridge()`を渡し(上の「登録の仕方」の例)、それぞれのルートで`tms-acf sync`を実行してKernelごとに生成する。プラグインのacf-jsonはプラグインのディレクトリの`acf-json/`、生成先は`app/Acf/`である。生成の調整が要るときは、`new AcfBridge(new BridgeConfig(...))`のように`BridgeConfig`を渡す(vendor/tmswp/acf-bridge/rules/sync-check.md)。

ACFのフィールドグループで管理するキーを`#[MetaSchema]`で宣言するエンティティには、`#[UseMetaStorage(AcfMetaStorage::class)]`を付ける(rules/meta.mdの「ACFのフィールドグループで管理するキー」)。

## してはいけないこと

- 拡張が提供する機能をコピーして自作しない。
- 拡張を`tmswp-kernel.php`の`extensions`以外の場所(`functions.php`の`Kernel::boot()`の後等)で登録しようとしない。
- 1つのインスタンスを複数のKernelに渡さない。thumbnailを2つ以上のKernelに登録しない。
- `tmswp-kernel.php`と拡張・設定のクラスのコンストラクタで、WordPressの関数を呼ばない。
- `tms_thumb()`に`crop`・`quality`以外のキーを渡さない。戻り値を`null`の分岐なしに出力しない。
- ja-toolkit の全モジュールを無条件に有効化しない。
- ACFの値取得を `get_field()` 直書きで済ませない。`rules/acf.md` を使う。

## 確認方法

1. `composer require` 後に `composer qa` が通ること。
2. 各拡張のREADMEにある最短利用例が動くこと。
