<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Integration;

use ACF_Local_JSON;
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Core\Tests\Support\IntegrationTestCase;

/**
 * fixtureのテーマとプラグインが、それぞれKernelConfigのextensionsにAcfBridgeを登録した状態を、有効化した無料版の
 * ACFで確かめる。テーマのacf-jsonはgroup_tmswp_acf_post(tmswp_acf_postのprice・subtitle)、プラグインのacf-jsonは
 * group_tmswp_plugin_post(postのtmswp_plugin_note)を持ち、生成物(app/Acf/AcfManifest.php等)は
 * `wp tmswp acf sync --app=...`で生成してある。
 */
final class AcfBridgeKernelIntegrationTest extends IntegrationTestCase
{
    /** テーマとプラグインのKernelに別々のAcfBridgeが登録され、構成はそれぞれのacf-json・app/Acf・名前空間を向く。 */
    public function testBridgeIsRegisteredPerKernelForThemeAndPlugin(): void
    {
        $theme = AcfBridge::forKernel(self::themeKernel());
        $plugin = AcfBridge::forKernel(self::pluginKernel());

        self::assertNotSame($theme, $plugin);
        self::assertSame(self::themeKernel(), $theme->kernel());
        self::assertSame(self::pluginKernel(), $plugin->kernel());
        self::assertSame([get_stylesheet_directory() . '/acf-json'], $theme->config()->jsonDirs);
        self::assertSame([\dirname((string) self::pluginKernel()->config()->pluginFile) . '/acf-json'], $plugin->config()->jsonDirs);
        self::assertSame(self::THEME_NAMESPACE . '\Acf', $theme->config()->namespace);
        self::assertSame(self::PLUGIN_NAMESPACE . '\Acf', $plugin->config()->namespace);
        self::assertSame(self::pluginKernel()->config()->appDir . '/Acf', $plugin->config()->outputDir);
    }

    /**
     * ACFが両方のacf-jsonを読み込み(acf/settings/load_json)、管理画面での保存先(acf/json/save_paths)は、そのグループを
     * 読んだKernelのacf-jsonになる。プラグインのグループがテーマのacf-jsonに書かれない。
     */
    public function testAcfLoadsAndSavesFieldGroupsInTheAcfJsonOfTheRegisteringKernel(): void
    {
        $themeDir = AcfBridge::forKernel(self::themeKernel())->config()->jsonDirs[0];
        $pluginDir = AcfBridge::forKernel(self::pluginKernel())->config()->jsonDirs[0];
        $files = acf_get_local_json_files();

        self::assertIsArray($files);
        self::assertSame($themeDir . '/group_tmswp_acf_post.json', $files['group_tmswp_acf_post'] ?? null);
        self::assertSame($pluginDir . '/group_tmswp_plugin_post.json', $files['group_tmswp_plugin_post'] ?? null);

        $default = [get_stylesheet_directory() . '/acf-json'];
        self::assertSame([$pluginDir], apply_filters('acf/json/save_paths', $default, ['key' => 'group_tmswp_plugin_post']));
        self::assertSame([$themeDir], apply_filters('acf/json/save_paths', $default, ['key' => 'group_tmswp_acf_post']));
        self::assertSame($default, apply_filters('acf/json/save_paths', $default, ['key' => 'group_tmswp_new']));

        // プラグインのacf-jsonのフィールドを、ACFが名前からfield keyへ解決できる
        $postId = self::createPost('post');
        self::assertTrue((bool) update_field('tmswp_plugin_note', 'from plugin', $postId));
        self::assertSame('from plugin', get_field('tmswp_plugin_note', $postId, false));
        self::assertSame('field_tmswp_plugin_post_note', get_post_meta($postId, '_tmswp_plugin_note', true));
    }

    /**
     * テーマとプラグインのacf-jsonに同じkeyのJSONがあるとき、保存先は、ACFが実際に読み込んだファイル(load_jsonの並び
     * でテーマ、プラグインの順のため、後のプラグインのもの)のディレクトリになり、もう一方のKernelのファイルを上書きしない。
     */
    public function testSaveDirectoryFollowsTheFileAcfLoadedWhenTwoKernelsShareAKey(): void
    {
        $themeDir = AcfBridge::forKernel(self::themeKernel())->config()->jsonDirs[0];
        $pluginDir = AcfBridge::forKernel(self::pluginKernel())->config()->jsonDirs[0];
        $localJson = acf_get_instance('ACF_Local_JSON');
        self::assertInstanceOf(ACF_Local_JSON::class, $localJson);
        $files = [$themeDir . '/group_tmswp_shared.json', $pluginDir . '/group_tmswp_shared.json'];
        $group = ['key' => 'group_tmswp_shared', 'title' => 'Shared'];

        try {
            foreach ($files as $file) {
                self::assertNotFalse(file_put_contents($file, '{"key": "group_tmswp_shared", "title": "Shared", "fields": [], "location": [], "active": true}'));
            }

            $localJson->scan_files();

            self::assertSame($pluginDir . '/group_tmswp_shared.json', acf_get_local_json_files()['group_tmswp_shared'] ?? null);
            self::assertSame([$pluginDir], $localJson->get_save_paths('group_tmswp_shared', $group));
            self::assertSame([$pluginDir], apply_filters('acf/json/save_paths', [$themeDir], $group));
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            $localJson->scan_files();
        }

        self::assertArrayNotHasKey('group_tmswp_shared', acf_get_local_json_files());
    }

    /**
     * 管理キーは、そのグループを持つKernelのレジストリにだけ申告される(init 6)。宣言の無い管理キーはregister_*_meta
     * されない。
     */
    public function testManagedKeysAreMarkedInTheRegistryOfTheRegisteringKernel(): void
    {
        $themeRegistry = self::themeKernel()->container()->get(MetaSchemaRegistry::class);
        $pluginRegistry = self::pluginKernel()->container()->get(MetaSchemaRegistry::class);

        self::assertTrue($themeRegistry->isExternallyManaged(ObjectType::Post, 'tmswp_acf_post', 'subtitle'));
        self::assertFalse($pluginRegistry->isExternallyManaged(ObjectType::Post, 'tmswp_acf_post', 'subtitle'));
        self::assertTrue($pluginRegistry->isExternallyManaged(ObjectType::Post, 'post', 'tmswp_plugin_note'));
        self::assertFalse($themeRegistry->isExternallyManaged(ObjectType::Post, 'post', 'tmswp_plugin_note'));
        self::assertFalse(registered_meta_key_exists('post', 'subtitle', 'tmswp_acf_post'));
        self::assertFalse(registered_meta_key_exists('post', 'tmswp_plugin_note', 'post'));
    }

    /**
     * ACFのフィールドグループで管理するキー(price)を、#[MetaSchema]と#[UseMetaStorage(AcfMetaStorage::class)]で
     * 宣言したエンティティ(AcfStoredPost)は、申告と衝突せずに起動し、そのキーは宣言どおりregister_post_metaされる。
     * MetaBagの読み書きはACFを通る。
     */
    public function testAcfManagedKeyCanBeDeclaredWithMetaSchemaAndAcfMetaStorage(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\Acf\AcfStoredPost', PostType::class);
        $registry = self::themeKernel()->container()->get(MetaSchemaRegistry::class);

        self::assertTrue($registry->isExternallyManaged(ObjectType::Post, 'tmswp_acf_post', 'price'));
        self::assertTrue($registry->schemasFor($postClass)->has('price'));
        self::assertTrue(registered_meta_key_exists('post', 'price', 'tmswp_acf_post'));

        $postId = self::createPost('tmswp_acf_post');
        self::assertTrue($postClass::meta($postId)->set('price', '1500'));
        self::assertSame('1500', get_field('price', $postId, false));
        self::assertSame('field_tmswp_acf_post_price', get_post_meta($postId, '_price', true));
    }

    /**
     * 同じ管理キーをAcfMetaStorageでないエンティティが宣言していれば、申告がDuplicateMetaKeyExceptionになる。
     * メッセージは、衝突したキー・(ObjectType, サブタイプ)・宣言クラスと定数名・ACFのフィールドグループのkeyを含む。
     */
    public function testAcfManagedKeyDeclaredWithAnotherStorageIsRejected(): void
    {
        $registry = new MetaSchemaRegistry(self::themeKernel()->container());
        $registry->registerEntity(IntegrationWpStoredAcfPost::class, ObjectType::Post, 'tmswp_acf_post');

        try {
            AcfBridge::forKernel(self::themeKernel())->markManagedKeys($registry);
            self::fail('DuplicateMetaKeyException was not thrown');
        } catch (DuplicateMetaKeyException $exception) {
            self::assertSame(
                "externally managed meta key 'price' for post:tmswp_acf_post is already declared by " . IntegrationWpStoredAcfPost::class . '::PRICE'
                . ' with meta storage ' . WpMetaStorage::class . "; ACF field group 'group_tmswp_acf_post' manages it with meta storage " . AcfMetaStorage::class
                . '. Add #[UseMetaStorage(\\' . AcfMetaStorage::class . '::class)] to ' . IntegrationWpStoredAcfPost::class
                . ", remove the #[MetaSchema] constant PRICE, or remove the key from ACF field group 'group_tmswp_acf_post'",
                $exception->getMessage(),
            );
        }
    }
}

#[AsPostType]
final class IntegrationWpStoredAcfPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_acf_post');
    }
}
