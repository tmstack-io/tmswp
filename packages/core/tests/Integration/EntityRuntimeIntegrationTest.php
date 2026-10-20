<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Exception\EntityDataException;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Core\Post\Thumbnail;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;
use TMSWP\Core\Tests\Support\IntegrationTestCase;
use TMSWP\Core\Tests\Support\RecordingLogger;
use TMSWP\Core\Tests\Support\UsesTempDirectories;
use TMSWP\Core\User\UserRole;
use TMSWP\Core\User\UserRoleDefinition;

/**
 * エンティティの builtin のアダプタ・ロールの差分の通知・キャッチアップでの再試行・ファサードの実行時の扱い
 * (Thumbnail の属性、親の深さの超過、ブロックエディタのフィルタ)を、実物の WordPress で確かめる。
 */
final class EntityRuntimeIntegrationTest extends IntegrationTestCase
{
    use UsesTempDirectories;

    private const string LATE_BOOT_MESSAGE = "Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.";

    /** @var list<Closure(string): void> */
    private array $autoloaders = [];

    /** productionPluginConfig() の Kernel のログを記録するロガー */
    private RecordingLogger $logger;

    public function set_up(): void
    {
        parent::set_up();
        $this->logger = new RecordingLogger();
    }

    public function tear_down(): void
    {
        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    public function testBuiltinPostTypeAdapterToMissingPostTypeThrowsWithoutAddingSupports(): void
    {
        try {
            MissingBuiltinPost::register(self::themeKernel());
            self::fail('EntityDefinitionException was not thrown');
        } catch (EntityDefinitionException $exception) {
            self::assertSame('builtin post type does not exist: tmswp_no_such_type', $exception->getMessage());
        }

        // 例外の前に supports を足していない
        self::assertFalse(post_type_supports('tmswp_no_such_type', 'title'));
    }

    public function testBuiltinTaxonomyAdapterToMissingTaxonomyThrowsWithOrWithoutObjectType(): void
    {
        foreach ([MissingBuiltinTaxonomy::class, MissingBuiltinTaxonomyWithObjectType::class] as $class) {
            try {
                $class::register(self::themeKernel());
                self::fail("EntityDefinitionException was not thrown for {$class}");
            } catch (EntityDefinitionException $exception) {
                self::assertSame('builtin taxonomy does not exist: tmswp_no_such_tax', $exception->getMessage());
            }
        }

        self::assertNotContains('tmswp_no_such_tax', get_object_taxonomies('post'));
    }

    public function testBuiltinRoleAdapterToMissingRoleThrows(): void
    {
        try {
            MissingBuiltinRole::register(self::themeKernel());
            self::fail('EntityDefinitionException was not thrown');
        } catch (EntityDefinitionException $exception) {
            self::assertSame('builtin role does not exist: tmswp_no_such_role', $exception->getMessage());
        }

        self::assertNull(get_role('tmswp_no_such_role'));
    }

    /**
     * 他のプラグインが use_block_editor_for_post_type に bool 以外(null)を返しても落ちない。対象外の投稿タイプの値は
     * 受け取ったまま返し、対象の投稿タイプには宣言した値を返す。
     */
    public function testBlockEditorPreferencePassesValuesOfOtherPostTypesThroughUnchanged(): void
    {
        BlockEditorOffPage::register(self::themeKernel());

        self::assertNull(apply_filters('use_block_editor_for_post_type', null, 'post'));
        self::assertTrue(apply_filters('use_block_editor_for_post_type', true, 'post'));
        self::assertFalse(apply_filters('use_block_editor_for_post_type', null, 'page'));
        self::assertFalse(apply_filters('use_block_editor_for_post_type', true, 'page'));
    }

    /**
     * init の後の本番の boot(キャッチアップ)でも、builtin のタクソノミーの紐付けの再試行が、同じ Kernel の投稿
     * タイプの登録(init 10)の後、MetaSchema の登録(init 20)の前に実行される。紐付け先が登録された投稿タイプは
     * 警告なしで紐付き、登録されない投稿タイプは warning が1回記録される。
     */
    public function testCatchUpRetriesBuiltinTaxonomyAfterRegisteringPostTypes(): void
    {
        $config = $this->productionPluginConfig('BuiltinRetry');
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $postType = 'tmswp_rt_' . $suffix;
        $missing = 'tmswp_missing_' . $suffix;
        $this->writeFile($config->appDir . '/Post/RetryPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

final class RetryPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Retry', 'Retries');
    }
}
PHP);
        $this->writeFile($config->appDir . '/Tax/CategoryAdapter.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Tax;

use {$namespace}\\Post\\RetryPost;
use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

final class CategoryAdapter extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::builtin('category')->objectType(RetryPost::class, '{$missing}');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\RetryPost', AsPostType::class, []),
            new DiscoveredComponent($namespace . '\\Tax\\CategoryAdapter', AsTaxonomy::class, []),
        ], []));

        try {
            Kernel::boot($config);

            self::assertContains('category', get_object_taxonomies($postType));
            self::assertSame(
                [
                    ['error', self::LATE_BOOT_MESSAGE],
                    ['error', self::LATE_BOOT_MESSAGE],
                    ['warning', "failed to attach taxonomy 'category' to post type '{$missing}'"],
                    ['error', self::LATE_BOOT_MESSAGE],
                ],
                array_map(static fn(array $record): array => [$record['level'], $record['message']], $this->logger->records),
            );
        } finally {
            unregister_taxonomy_for_object_type('category', $postType);
            unregister_post_type($postType);
        }
    }

    public function testRoleDriftFromDeclarationIsReportedAsWarnings(): void
    {
        $logger = self::fixtureLogger();
        $before = \count($logger->records);
        // 宣言(表示名 New label・cap は read と upload_files)と違う状態のロールが DB にある
        add_role('tmswp_drift', 'Old label', ['read' => true, 'edit_posts' => true, 'tmswp_legacy' => false]);

        try {
            DriftRole::register(self::themeKernel());

            self::assertSame(
                [
                    [
                        'level' => 'warning',
                        'message' => 'tmswp.user_role: the label of role {role} in the database ({stored}) differs from the declared label ({declared}); add_role() runs only once, so remove the role to apply the declared label',
                        'context' => ['role' => 'tmswp_drift', 'stored' => 'Old label', 'declared' => 'New label'],
                    ],
                    [
                        'level' => 'warning',
                        'message' => 'tmswp.user_role: role {role} has capabilities that are not declared: {capabilities}; capabilities removed from the declaration stay in the database until removed explicitly',
                        'context' => ['role' => 'tmswp_drift', 'capabilities' => 'edit_posts, tmswp_legacy'],
                    ],
                ],
                \array_slice($logger->records, $before),
            );
            // 通知するだけで、DB の表示名と cap は消さない。宣言の cap は足す
            $role = get_role('tmswp_drift');
            self::assertNotNull($role);
            self::assertSame('Old label', wp_roles()->role_names['tmswp_drift']);
            self::assertTrue($role->has_cap('edit_posts'));
            self::assertTrue($role->has_cap('upload_files'));
        } finally {
            remove_role('tmswp_drift');
        }
    }

    /** 初回の add_role・宣言どおりの既存ロール・宣言に無い cap を持つ builtin のロールでは、差分を記録しない。 */
    public function testRoleWithoutDriftIsNotReported(): void
    {
        $logger = self::fixtureLogger();
        $before = \count($logger->records);
        add_role('tmswp_in_sync', 'In sync', ['read' => true, 'upload_files' => true]);

        try {
            NewRole::register(self::themeKernel());
            InSyncRole::register(self::themeKernel());
            BuiltinEditorRole::register(self::themeKernel());

            self::assertNotNull(get_role('tmswp_new_role'));
            self::assertSame([], \array_slice($logger->records, $before));
        } finally {
            remove_role('tmswp_new_role');
            remove_role('tmswp_in_sync');
        }
    }

    public function testThumbnailImgRendersAttributesWithHtmlRenderer(): void
    {
        $thumbnail = new Thumbnail('https://example.com/a.jpg?x=1&y=2', 300, null, 'A "quoted" alt');

        self::assertSame(
            '<img src="https://example.com/a.jpg?x=1&#038;y=2" alt="A &quot;quoted&quot; alt" class="hero wide" loading="lazy" hidden width="300">',
            $thumbnail->img([
                'class' => ['hero', 'wide'],
                'loading' => 'lazy',
                'decoding' => null,
                'hidden' => true,
                'inert' => false,
                // src・alt・width・height は大文字小文字に関係なく上書きできない
                'SRC' => 'https://example.com/other.jpg',
                'Width' => 1,
            ]),
        );
    }

    public function testThumbnailFromFallbackFilterRejectsDisallowedProtocolOnRender(): void
    {
        $productPost = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\ProductPost', PostType::class);
        $postId = self::createPost($productPost::slug());
        add_filter('tmswp/post/thumbnail_fallback', static fn(): Thumbnail => new Thumbnail('javascript:alert(1)', null, null, ''));

        $thumbnail = $productPost::thumbnail($postId);
        self::assertInstanceOf(Thumbnail::class, $thumbnail);

        try {
            $thumbnail->img();
            self::fail('InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('HTML attribute src has a URL with a disallowed protocol: javascript:alert(1)', $exception->getMessage());
        }
    }

    /** debug の Kernel(fixture のテーマ)では、親の循環による深さの超過は EntityDataException になる。 */
    public function testParentDepthExceededThrowsEntityDataExceptionInDebug(): void
    {
        $productPost = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\ProductPost', PostType::class);
        $category = self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductCategoryTaxonomy', Taxonomy::class);
        $postId = $this->createPostCycle($productPost::slug());
        $termId = $this->createTermCycle($category::slug());

        try {
            $productPost::parents($postId);
            self::fail('EntityDataException was not thrown for the post');
        } catch (EntityDataException $exception) {
            self::assertSame("post parent depth of post {$postId} exceeded 100 ({$productPost})", $exception->getMessage());
        }

        try {
            $category::parents($termId);
            self::fail('EntityDataException was not thrown for the term');
        } catch (EntityDataException $exception) {
            self::assertSame("term parent depth of term {$termId} exceeded 100 ({$category})", $exception->getMessage());
        }
    }

    /** 本番の Kernel では、親の深さの超過は例外にせず、その Kernel のロガーに error で記録して空配列を返す。 */
    public function testParentDepthExceededIsLoggedAndReturnsEmptyListInProduction(): void
    {
        $config = $this->productionPluginConfig('ParentLoop');
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $postType = 'tmswp_lp_' . $suffix;
        $taxonomy = 'tmswp_lt_' . $suffix;
        $this->writeFile($config->appDir . '/Post/LoopPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

final class LoopPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Loop', 'Loops')->hierarchical();
    }
}
PHP);
        $this->writeFile($config->appDir . '/Tax/LoopTaxonomy.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Tax;

use {$namespace}\\Post\\LoopPost;
use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

final class LoopTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('{$taxonomy}')->label('Loop', 'Loops')->objectType(LoopPost::class)->hierarchical();
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\LoopPost', AsPostType::class, []),
            new DiscoveredComponent($namespace . '\\Tax\\LoopTaxonomy', AsTaxonomy::class, []),
        ], []));

        try {
            Kernel::boot($config);
            $loopPost = self::fixtureClass($namespace . '\\Post\\LoopPost', PostType::class);
            $loopTaxonomy = self::fixtureClass($namespace . '\\Tax\\LoopTaxonomy', Taxonomy::class);
            $postId = $this->createPostCycle($postType);
            $termId = $this->createTermCycle($taxonomy);
            $before = \count($this->logger->records);

            self::assertSame([], $loopPost::parents($postId));
            self::assertSame([], $loopTaxonomy::parents($termId, true));
            self::assertSame(
                [
                    [
                        'level' => 'error',
                        'message' => 'tmswp.post: post parent depth of post {post} exceeded {limit} ({class})',
                        'context' => ['post' => $postId, 'limit' => 100, 'class' => $loopPost],
                    ],
                    [
                        'level' => 'error',
                        'message' => 'tmswp.taxonomy: term parent depth of term {term} exceeded {limit} ({class})',
                        'context' => ['term' => $termId, 'limit' => 100, 'class' => $loopTaxonomy],
                    ],
                ],
                \array_slice($this->logger->records, $before),
            );
        } finally {
            unregister_taxonomy($taxonomy);
            unregister_post_type($postType);
        }
    }

    /**
     * meta() は、無い対象・スコープ外の対象を、定義の誤り(EntityDefinitionException)ではなく呼び出しの誤りとして
     * InvalidArgumentException にする。
     */
    public function testMetaForMissingOrMismatchedEntityIsInvalidArgument(): void
    {
        $productPost = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\ProductPost', PostType::class);
        $category = self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductCategoryTaxonomy', Taxonomy::class);
        $member = self::fixtureClass(self::THEME_NAMESPACE . '\\User\\MemberRole', UserRole::class);
        $pageId = self::createPost('page');
        $cases = [
            'missing post' => [static fn(): mixed => $productPost::meta(PHP_INT_MAX), 'post'],
            'post of another type' => [static fn(): mixed => $productPost::meta($pageId), 'post'],
            'missing term' => [static fn(): mixed => $category::meta(PHP_INT_MAX), 'term'],
            'missing user' => [static fn(): mixed => $member::meta(PHP_INT_MAX), 'user'],
        ];

        foreach ($cases as $name => [$call, $kind]) {
            try {
                $call();
                self::fail("InvalidArgumentException was not thrown for {$name}");
            } catch (InvalidArgumentException $exception) {
                self::assertSame("cannot create MetaBag for missing or mismatched {$kind}", $exception->getMessage(), $name);
            }
        }
    }

    /** fixture のテーマが test チャネルに登録した RecordingLogger を返す。 */
    private static function fixtureLogger(): RecordingLogger
    {
        $logger = self::themeKernel()->logger()->channel('test');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /** 互いを親にした2件の投稿を作り(post_parent の循環)、その1件の ID を返す。 */
    private function createPostCycle(string $postType): int
    {
        $first = self::createPost($postType);
        $second = self::createPost($postType);
        // wp_update_post() は循環を検出して直すため、DB を直接書き換える
        self::wpdb()->update(self::wpdb()->posts, ['post_parent' => $second], ['ID' => $first]);
        self::wpdb()->update(self::wpdb()->posts, ['post_parent' => $first], ['ID' => $second]);
        clean_post_cache($first);
        clean_post_cache($second);

        return $first;
    }

    /** 互いを親にした2件のタームを作り(parent の循環)、その1件の ID を返す。 */
    private function createTermCycle(string $taxonomy): int
    {
        $first = wp_insert_term('Loop first', $taxonomy);
        $second = wp_insert_term('Loop second', $taxonomy);
        self::assertIsArray($first);
        self::assertIsArray($second);
        $firstId = (int) $first['term_id'];
        $secondId = (int) $second['term_id'];
        // wp_update_term() は循環を検出して直すため、DB を直接書き換える
        self::wpdb()->update(self::wpdb()->term_taxonomy, ['parent' => $secondId], ['term_id' => $firstId, 'taxonomy' => $taxonomy]);
        self::wpdb()->update(self::wpdb()->term_taxonomy, ['parent' => $firstId], ['term_id' => $secondId, 'taxonomy' => $taxonomy]);
        clean_term_cache([$firstId, $secondId], $taxonomy);

        return $firstId;
    }

    /** app/ のクラスを、名前空間に対応するパスから読み込む autoload を登録する(tear_down で外す)。 */
    private function registerAppAutoloader(KernelConfig $config): void
    {
        $namespace = $config->appNamespace;
        $this->autoloaders[] = $autoloader = static function (string $class) use ($namespace, $config): void {
            if (str_starts_with($class, $namespace . '\\')) {
                require $config->appDir . '/' . str_replace('\\', '/', substr($class, \strlen($namespace) + 1)) . '.php';
            }
        };
        spl_autoload_register($autoloader);
    }

    /**
     * 本番相当(debug=false)のプラグイン形態の構成を、一時ディレクトリのプラグインで作る。ログはこのテストの
     * RecordingLogger に記録する。
     */
    private function productionPluginConfig(string $name): KernelConfig
    {
        $root = $this->createTempDirectory('integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $logger = $this->logger;

        return KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            'TmswpIntegration' . $name . uniqid(),
            false,
            logChannels: static fn(): array => ['integration' => new LogChannel($logger)],
        );
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("ファイルを書き込めません: {$path}");
        }
    }
}

final class MissingBuiltinPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('tmswp_no_such_type')->supports('title');
    }
}

final class BlockEditorOffPage extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('page')->blockEditor(false);
    }
}

final class MissingBuiltinTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::builtin('tmswp_no_such_tax');
    }
}

final class MissingBuiltinTaxonomyWithObjectType extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::builtin('tmswp_no_such_tax')->objectType('post');
    }
}

final class DriftRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('tmswp_drift')->label('New label')->capabilities('read', 'upload_files');
    }
}

final class NewRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('tmswp_new_role')->label('New role')->capabilities('read');
    }
}

final class InSyncRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('tmswp_in_sync')->label('In sync')->capabilities('read', 'upload_files');
    }
}

final class MissingBuiltinRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::builtin('tmswp_no_such_role')->capabilities('read');
    }
}

final class BuiltinEditorRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::builtin('editor')->capabilities('read');
    }
}
