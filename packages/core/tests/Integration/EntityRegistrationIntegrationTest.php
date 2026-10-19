<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Tests\Support\UsesTempDirectories;
use WP_Post_Type;
use WP_Role;
use WP_Taxonomy;
use WP_UnitTestCase;

final class EntityRegistrationIntegrationTest extends WP_UnitTestCase
{
    use UsesTempDirectories;

    /** @var list<callable(string): void> */
    private array $autoloaders = [];

    /** @var list<string> */
    private array $postTypes = [];

    /** @var list<string> */
    private array $taxonomies = [];

    /** @var list<string> */
    private array $roles = [];

    public function tear_down(): void
    {
        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        foreach ($this->taxonomies as $taxonomy) {
            unregister_taxonomy($taxonomy);
        }

        foreach ($this->postTypes as $postType) {
            unregister_post_type($postType);
        }

        foreach ($this->roles as $role) {
            remove_role($role);
        }

        $this->autoloaders = [];
        $this->postTypes = [];
        $this->taxonomies = [];
        $this->roles = [];
        Kernel::reset();
        $this->cleanupTempDirectories();

        parent::tear_down();
    }

    public function testKernelBootRegistersPostTypeTaxonomyAndUserRole(): void
    {
        $namespace = $this->runtimeNamespace('EntityApp');
        $appDir = $this->createTempDirectory('integration-entity-app');
        $cacheDir = $this->createTempDirectory('integration-entity-cache');
        $suffix = substr(str_replace('.', '', uniqid('', true)), -8);
        $postTypeSlug = $this->trackPostType('tmswp_p_' . $suffix);
        $taxonomySlug = $this->trackTaxonomy('tmswp_t_' . $suffix);
        $roleSlug = $this->trackRole('tmswp_r_' . $suffix);

        $this->writeEntityClasses($namespace, $appDir, $postTypeSlug, $taxonomySlug, $roleSlug);
        $this->registerAppAutoloader($namespace, $appDir);

        Kernel::boot(KernelConfig::theme($appDir, $namespace, $cacheDir, false, false));

        self::assertTrue(post_type_exists($postTypeSlug));
        $postType = get_post_type_object($postTypeSlug);
        self::assertInstanceOf(WP_Post_Type::class, $postType);
        self::assertTrue($postType->has_archive);
        self::assertTrue($postType->show_in_rest);
        self::assertTrue(post_type_supports($postTypeSlug, 'thumbnail'));

        self::assertTrue(taxonomy_exists($taxonomySlug));
        $taxonomy = get_taxonomy($taxonomySlug);
        self::assertInstanceOf(WP_Taxonomy::class, $taxonomy);
        self::assertTrue($taxonomy->hierarchical);
        self::assertTrue($taxonomy->show_in_rest);
        self::assertContains($postTypeSlug, $taxonomy->object_type);

        $role = get_role($roleSlug);
        self::assertInstanceOf(WP_Role::class, $role);
        self::assertTrue($role->has_cap('read'));
        self::assertTrue($role->has_cap('tmswp_member'));
    }

    private function runtimeNamespace(string $prefix): string
    {
        return 'Example\\Tests\\Integration\\' . $prefix . uniqid();
    }

    private function trackPostType(string $postType): string
    {
        $this->postTypes[] = $postType;

        return $postType;
    }

    private function trackTaxonomy(string $taxonomy): string
    {
        $this->taxonomies[] = $taxonomy;

        return $taxonomy;
    }

    private function trackRole(string $role): string
    {
        $this->roles[] = $role;

        return $role;
    }

    private function registerAppAutoloader(string $namespace, string $appDir): void
    {
        $prefix = $namespace . '\\';
        $loader = static function (string $class) use ($appDir, $prefix): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, \strlen($prefix))) . '.php';
            $file = $appDir . DIRECTORY_SEPARATOR . $relative;

            if (!is_file($file)) {
                return;
            }

            require $file;
        };

        spl_autoload_register($loader);
        $this->autoloaders[] = $loader;
    }

    private function writeEntityClasses(
        string $namespace,
        string $appDir,
        string $postTypeSlug,
        string $taxonomySlug,
        string $roleSlug,
    ): void {
        $this->writeFile($appDir . '/Post/ProductPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
final class ProductPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postTypeSlug}')
            ->label('製品')
            ->hasArchive()
            ->showInRest()
            ->supports('title', 'thumbnail')
            ->rewriteSlug('{$postTypeSlug}');
    }
}
PHP);

        $this->writeFile($appDir . '/Tax/ProductCategoryTaxonomy.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Tax;

use {$namespace}\\Post\\ProductPost;
use TMSWP\\Core\\Attribute\\AsTaxonomy;
use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

#[AsTaxonomy]
final class ProductCategoryTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('{$taxonomySlug}')
            ->label('製品カテゴリ')
            ->objectType(ProductPost::class)
            ->hierarchical()
            ->showInRest();
    }
}
PHP);

        $this->writeFile($appDir . '/User/MemberRole.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\User;

use TMSWP\\Core\\Attribute\\AsUserRole;
use TMSWP\\Core\\User\\UserRole;
use TMSWP\\Core\\User\\UserRoleDefinition;

#[AsUserRole]
final class MemberRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('{$roleSlug}')
            ->label('会員')
            ->capabilities('read', 'tmswp_member');
    }
}
PHP);
    }

    private function writeFile(string $file, string $contents): string
    {
        $dir = \dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("fixture ディレクトリを作成できません: {$dir}");
        }

        $bytes = file_put_contents($file, $contents);

        if ($bytes === false) {
            self::fail("fixture ファイルを書き込めません: {$file}");
        }

        return $file;
    }
}
