<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use ReflectionMethod;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Core\Option\OptionRepositoryFactory;
use TMSWP\Core\Option\WpOptionStorage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Tests\Support\UsesTempDirectories;
use WP_Post;
use WP_UnitTestCase;

final class MetaOptionIntegrationTest extends WP_UnitTestCase
{
    use UsesTempDirectories;

    /** @var list<callable(string): void> */
    private array $autoloaders = [];

    /** @var list<string> */
    private array $postTypes = [];

    /** @var list<array{objectType: string, key: string, subtype: string}> */
    private array $metaKeys = [];

    /** @var list<string> */
    private array $optionKeys = [];

    /** @var list<int> */
    private array $posts = [];

    public function tear_down(): void
    {
        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        foreach ($this->posts as $postId) {
            wp_delete_post($postId, true);
        }

        foreach ($this->metaKeys as $metaKey) {
            unregister_meta_key($metaKey['objectType'], $metaKey['key'], $metaKey['subtype']);
        }

        foreach ($this->postTypes as $postType) {
            unregister_post_type($postType);
        }

        foreach ($this->optionKeys as $optionKey) {
            delete_option($optionKey);
        }

        $this->autoloaders = [];
        $this->postTypes = [];
        $this->metaKeys = [];
        $this->optionKeys = [];
        $this->posts = [];
        Kernel::reset();
        $this->cleanupTempDirectories();

        parent::tear_down();
    }

    public function testKernelBootRegistersMetaSchemasAndOptionGroup(): void
    {
        $namespace = $this->runtimeNamespace('MetaOptionApp');
        $appDir = $this->createTempDirectory('integration-meta-option-app');
        $cacheDir = $this->createTempDirectory('integration-meta-option-cache');
        $suffix = substr(str_replace('.', '', uniqid('', true)), -8);
        $postTypeSlug = $this->trackPostType('tmswp_m3_p_' . $suffix);
        $storedPostTypeSlug = $this->trackPostType('tmswp_m3_s_' . $suffix);
        $priceKey = $this->trackMetaKey('post', 'tmswp_price_' . $suffix, $postTypeSlug);
        $codesKey = $this->trackMetaKey('post', 'tmswp_codes_' . $suffix, $postTypeSlug);
        $customKey = $this->trackMetaKey('post', 'tmswp_custom_' . $suffix, $storedPostTypeSlug);
        $optionPrefix = 'tmswp_m3_' . $suffix . '_';
        $this->trackOption($optionPrefix . 'contact_email');
        $this->trackOption($optionPrefix . 'maintenance');
        $this->trackOption($optionPrefix . 'null_value');
        $this->trackOption($optionPrefix . 'false_value');
        $this->trackOption($optionPrefix . 'empty_value');

        $this->writeMetaOptionClasses($namespace, $appDir, $postTypeSlug, $storedPostTypeSlug, $priceKey, $codesKey, $customKey, $optionPrefix);
        $this->registerAppAutoloader($namespace, $appDir);

        Kernel::boot(KernelConfig::theme($appDir, $namespace, $cacheDir, false, false));

        $postClass = $namespace . '\\Post\\ProductPost';
        if (!is_subclass_of($postClass, PostType::class)) {
            self::fail("fixture post type class が見つかりません: {$postClass}");
        }

        $registered = get_registered_meta_keys('post', $postTypeSlug);
        self::assertArrayHasKey($priceKey, $registered);
        self::assertSame('integer', $registered[$priceKey]['type']);
        self::assertSame(1200, $registered[$priceKey]['default']);
        self::assertTrue($registered[$priceKey]['single']);
        self::assertTrue($registered[$priceKey]['show_in_rest']);
        self::assertSame('価格', $registered[$priceKey]['description']);
        self::assertSame([$postClass, 'sanitizePrice'], $registered[$priceKey]['sanitize_callback']);
        self::assertTrue($registered[$priceKey]['revisions_enabled'] ?? false);
        self::assertArrayHasKey($codesKey, $registered);
        self::assertFalse($registered[$codesKey]['single']);

        $postId = wp_insert_post([
            'post_type' => $postTypeSlug,
            'post_status' => 'publish',
            'post_title' => 'M3 Meta Test',
        ]);
        self::assertIsInt($postId);
        self::assertGreaterThan(0, $postId);
        $this->posts[] = $postId;

        $bag = $postClass::meta($postId);
        $storage = new WpMetaStorage();

        self::assertNull($storage->get(ObjectType::Post, $postId, $priceKey));
        self::assertSame([], $storage->getAll(ObjectType::Post, $postId, $codesKey));
        self::assertSame([], $storage->getMultiple(ObjectType::Post, $postId, [$priceKey, $codesKey]));
        self::assertSame(1200, $bag->int($priceKey));
        self::assertTrue($bag->set($priceKey, 2500));
        self::assertSame(2500, $bag->int($priceKey));
        self::assertTrue($bag->set($codesKey, ['alpha', 'beta']));
        self::assertSame(['alpha', 'beta'], $bag->list($codesKey));
        $storedValues = $storage->getMultiple(ObjectType::Post, $postId, [$priceKey, $codesKey]);
        self::assertArrayHasKey($priceKey, $storedValues);
        self::assertArrayHasKey($codesKey, $storedValues);
        self::assertSame('alpha', $storedValues[$codesKey]);

        $nullMetaKey = 'tmswp_null_' . $suffix;
        $falseMetaKey = 'tmswp_false_' . $suffix;
        $emptyMetaKey = 'tmswp_empty_' . $suffix;
        self::assertTrue($storage->set(ObjectType::Post, $postId, $nullMetaKey, null));
        self::assertTrue($storage->set(ObjectType::Post, $postId, $falseMetaKey, false));
        self::assertTrue($storage->set(ObjectType::Post, $postId, $emptyMetaKey, ''));
        self::assertTrue($storage->has(ObjectType::Post, $postId, $nullMetaKey));
        self::assertTrue($storage->has(ObjectType::Post, $postId, $falseMetaKey));
        self::assertTrue($storage->has(ObjectType::Post, $postId, $emptyMetaKey));
        self::assertSame('', $storage->get(ObjectType::Post, $postId, $emptyMetaKey));
        self::assertTrue($storage->delete(ObjectType::Post, $postId, $emptyMetaKey));
        self::assertTrue($storage->delete(ObjectType::Post, $postId, $emptyMetaKey));

        $storedPostId = wp_insert_post([
            'post_type' => $storedPostTypeSlug,
            'post_status' => 'publish',
            'post_title' => 'M3 Custom Storage Test',
        ]);
        self::assertIsInt($storedPostId);
        self::assertGreaterThan(0, $storedPostId);
        $this->posts[] = $storedPostId;

        $storedPostClass = $namespace . '\\Post\\StoredPost';
        if (!is_subclass_of($storedPostClass, PostType::class)) {
            self::fail("fixture stored post type class が見つかりません: {$storedPostClass}");
        }

        $storedBag = $storedPostClass::meta($storedPostId);
        self::assertTrue($storedBag->set($customKey, 'custom-storage'));
        self::assertSame('custom-storage', $storedBag->string($customKey));
        self::assertTrue($storedBag->set('custom_null', null));
        self::assertTrue($storedBag->set('custom_false', false));
        self::assertTrue($storedBag->set('custom_empty', ''));
        self::assertTrue($storedBag->has('custom_null'));
        self::assertTrue($storedBag->has('custom_false'));
        self::assertTrue($storedBag->has('custom_empty'));
        self::assertNull($storedBag->get('custom_null'));
        self::assertFalse($storedBag->get('custom_false'));
        self::assertSame('', $storedBag->get('custom_empty'));
        self::assertFalse($storage->has(ObjectType::Post, $storedPostId, $customKey));

        $optionClass = $namespace . '\\Option\\SiteOption';
        if (!enum_exists($optionClass)) {
            self::fail("fixture option enum が見つかりません: {$optionClass}");
        }

        $factory = Kernel::forClass($optionClass)->container()->get(OptionRepositoryFactory::class);
        self::assertInstanceOf(OptionRepositoryFactory::class, $factory);

        $repository = $factory->for($optionClass);
        self::assertSame('info@example.test', $repository->string('contact_email'));
        self::assertTrue($repository->set('contact_email', 'sales@example.test'));
        self::assertSame('sales@example.test', $repository->string('contact_email'));
        self::assertSame('sales@example.test', get_option($optionPrefix . 'contact_email'));
        self::assertFalse($this->isAutoloaded($optionPrefix . 'contact_email'));
        self::assertTrue($repository->set('maintenance', '1'));
        self::assertTrue($repository->bool('maintenance'));

        $optionStorage = new WpOptionStorage();
        self::assertTrue($optionStorage->set($optionPrefix . 'null_value', null));
        self::assertTrue($optionStorage->set($optionPrefix . 'false_value', false));
        self::assertTrue($optionStorage->set($optionPrefix . 'empty_value', ''));
        self::assertTrue($optionStorage->has($optionPrefix . 'null_value'));
        self::assertTrue($optionStorage->has($optionPrefix . 'false_value'));
        self::assertTrue($optionStorage->has($optionPrefix . 'empty_value'));
        self::assertNull($optionStorage->get($optionPrefix . 'null_value'));
        self::assertFalse($optionStorage->get($optionPrefix . 'false_value'));
        self::assertSame('', $optionStorage->get($optionPrefix . 'empty_value'));

        $probeClass = $namespace . '\\Option\\SiteOptionProbe';
        if (!class_exists($probeClass) || !method_exists($probeClass, 'exercise')) {
            self::fail("fixture option probe が見つかりません: {$probeClass}");
        }

        $probeResult = (new ReflectionMethod($probeClass, 'exercise'))->invoke(null);
        self::assertIsArray($probeResult);
        self::assertSame($optionPrefix . 'contact_email', $probeResult['name']);
        self::assertSame('after-delete@example.test', $probeResult['contact']);
        self::assertFalse($probeResult['maintenanceHas']);
        self::assertSame([$optionPrefix . 'contact_email', $optionPrefix . 'maintenance'], $probeResult['deleted']);
    }

    public function testObjectTypeRejectsInvalidIdsAndMismatchedObjects(): void
    {
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Post->resolveId(0));
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Post->resolveId(new WP_Post((object) ['ID' => 0])));
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Term->resolveId(new WP_Post((object) ['ID' => 1])));
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

    private function trackMetaKey(string $objectType, string $key, string $subtype): string
    {
        $this->metaKeys[] = ['objectType' => $objectType, 'key' => $key, 'subtype' => $subtype];

        return $key;
    }

    private function trackOption(string $optionKey): string
    {
        $this->optionKeys[] = $optionKey;

        return $optionKey;
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

    /** @param callable(): mixed $callback */
    private function assertInvalidMetaObject(callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            self::assertSame(InvalidArgumentException::class, $exception::class);

            return;
        }

        self::fail('InvalidArgumentException が送出されませんでした');
    }

    private function isAutoloaded(string $optionName): bool
    {
        $options = wp_load_alloptions();

        return \array_key_exists($optionName, $options);
    }

    private function writeMetaOptionClasses(
        string $namespace,
        string $appDir,
        string $postTypeSlug,
        string $storedPostTypeSlug,
        string $priceKey,
        string $codesKey,
        string $customKey,
        string $optionPrefix,
    ): void {
        $this->writeFile($appDir . '/Post/ProductPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Attribute\\WithMetaRevisions;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
#[WithMetaRevisions]
final class ProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 1200, showInRest: true, description: '価格', sanitize: [self::class, 'sanitizePrice'])]
    public const META_PRICE = '{$priceKey}';

    #[MetaSchema(type: MetaValueType::String, single: false, showInRest: true)]
    public const META_CODES = '{$codesKey}';

    public static function sanitizePrice(mixed \$value): int
    {
        return (int) \$value;
    }

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postTypeSlug}')
            ->label('製品')
            ->showInRest()
            ->supports('title', 'revisions');
    }
}
PHP);

        $this->writeFile($appDir . '/Post/StoredPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use {$namespace}\\Storage\\ArrayMetaStorage;
use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Attribute\\UseMetaStorage;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
#[UseMetaStorage(ArrayMetaStorage::class)]
final class StoredPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_CUSTOM = '{$customKey}';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$storedPostTypeSlug}')
            ->label('保存先差し替え')
            ->supports('title');
    }
}
PHP);

        $this->writeFile($appDir . '/Storage/ArrayMetaStorage.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Storage;

use TMSWP\\Core\\Meta\\MetaStorageInterface;
use TMSWP\\Core\\Meta\\ObjectType;

final class ArrayMetaStorage implements MetaStorageInterface
{
    private static array \$values = [];

    public function has(ObjectType \$type, int \$objectId, string \$key): bool
    {
        return array_key_exists(\$this->storageKey(\$type, \$objectId, \$key), self::\$values);
    }

    public function get(ObjectType \$type, int \$objectId, string \$key): mixed
    {
        return self::\$values[\$this->storageKey(\$type, \$objectId, \$key)] ?? null;
    }

    public function getAll(ObjectType \$type, int \$objectId, string \$key): array
    {
        \$value = \$this->get(\$type, \$objectId, \$key);

        return is_array(\$value) && array_is_list(\$value) ? \$value : [];
    }

    public function getMultiple(ObjectType \$type, int \$objectId, array \$keys): array
    {
        \$values = [];

        foreach (\$keys as \$key) {
            if (\$this->has(\$type, \$objectId, \$key)) {
                \$values[\$key] = \$this->get(\$type, \$objectId, \$key);
            }
        }

        return \$values;
    }

    public function set(ObjectType \$type, int \$objectId, string \$key, mixed \$value): bool
    {
        self::\$values[\$this->storageKey(\$type, \$objectId, \$key)] = \$value;

        return true;
    }

    public function setAll(ObjectType \$type, int \$objectId, string \$key, array \$values): bool
    {
        return \$this->set(\$type, \$objectId, \$key, \$values);
    }

    public function delete(ObjectType \$type, int \$objectId, string \$key): bool
    {
        unset(self::\$values[\$this->storageKey(\$type, \$objectId, \$key)]);

        return true;
    }

    private function storageKey(ObjectType \$type, int \$objectId, string \$key): string
    {
        return \$type->value . ':' . \$objectId . ':' . \$key;
    }
}
PHP);

        $this->writeFile($appDir . '/Option/SiteOption.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Option;

use TMSWP\\Core\\Attribute\\AsOptionGroup;
use TMSWP\\Core\\Attribute\\OptionSchema;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Option\\OptionAccess;

#[AsOptionGroup(prefix: '{$optionPrefix}')]
enum SiteOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: 'info@example.test', autoload: false)]
    case ContactEmail = 'contact_email';

    #[OptionSchema(type: MetaValueType::Boolean, default: false)]
    case Maintenance = 'maintenance';
}
PHP);

        $this->writeFile($appDir . '/Option/SiteOptionProbe.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Option;

final class SiteOptionProbe
{
    public static function exercise(): array
    {
        SiteOption::ContactEmail->set('probe@example.test');
        SiteOption::Maintenance->set(false);
        \$deleted = SiteOption::deleteAll();
        SiteOption::ContactEmail->set('after-delete@example.test');

        return [
            'name' => SiteOption::ContactEmail->name(),
            'contact' => SiteOption::ContactEmail->string(),
            'maintenanceHas' => SiteOption::Maintenance->has(),
            'deleted' => \$deleted,
        ];
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
