<?php

declare(strict_types=1);

namespace {
    if (!function_exists('acf')) {
        function acf(): bool
        {
            return true;
        }
    }

    if (!function_exists('get_field')) {
        function get_field(string $key, int|string $postId, bool $format = true): mixed
        {
            $values = $GLOBALS['tmswp_acf_bridge_test_values'] ?? [];

            return is_array($values) && isset($values[(string) $postId]) && is_array($values[(string) $postId])
                ? ($values[(string) $postId][$key] ?? null)
                : null;
        }
    }

    if (!function_exists('update_field')) {
        function update_field(string $key, mixed $value, int|string $postId): bool
        {
            $values = $GLOBALS['tmswp_acf_bridge_test_values'] ?? [];
            if (!is_array($values)) {
                $values = [];
            }

            $stored = $values[(string) $postId] ?? [];
            if (!is_array($stored)) {
                $stored = [];
            }

            $stored[$key] = $value;
            $values[(string) $postId] = $stored;
            $GLOBALS['tmswp_acf_bridge_test_values'] = $values;

            return true;
        }
    }

    if (!function_exists('delete_field')) {
        function delete_field(string $key, int|string $postId): bool
        {
            $values = $GLOBALS['tmswp_acf_bridge_test_values'] ?? [];
            if (!is_array($values)) {
                return true;
            }

            $stored = $values[(string) $postId] ?? [];
            if (!is_array($stored)) {
                return true;
            }

            unset($stored[$key]);
            $values[(string) $postId] = $stored;
            $GLOBALS['tmswp_acf_bridge_test_values'] = $values;

            return true;
        }
    }
}

namespace TMSWP\AcfBridge\Tests\Integration {
    use TMSWP\Core\Kernel\Kernel;
    use TMSWP\Core\Kernel\KernelConfig;
    use TMSWP\Core\Post\PostType;
    use TMSWP\Core\Tests\Support\UsesTempDirectories;
    use WP_UnitTestCase;

    final class AcfBridgeIntegrationTest extends WP_UnitTestCase
    {
        use UsesTempDirectories;

        /** @var list<callable(string): void> */
        private array $autoloaders = [];

        /** @var list<int> */
        private array $posts = [];

        /** @var list<string> */
        private array $postTypes = [];

        public function tear_down(): void
        {
            foreach ($this->autoloaders as $autoloader) {
                spl_autoload_unregister($autoloader);
            }

            foreach ($this->posts as $postId) {
                wp_delete_post($postId, true);
            }

            foreach ($this->postTypes as $postType) {
                unregister_post_type($postType);
            }

            unset($GLOBALS['tmswp_acf_bridge_test_values']);
            $this->autoloaders = [];
            $this->posts = [];
            $this->postTypes = [];
            Kernel::reset();
            $this->cleanupTempDirectories();

            parent::tear_down();
        }

        public function testUseMetaStorageEntityReadsAndWritesThroughAcfStorage(): void
        {
            $namespace = 'Example\\Tests\\Integration\\AcfBridgeApp' . uniqid();
            $appDir = $this->createTempDirectory('acf-bridge-app');
            $cacheDir = $this->createTempDirectory('acf-bridge-cache');
            $postType = 'tmswp_acf_' . substr(str_replace('.', '', uniqid('', true)), -8);
            $this->postTypes[] = $postType;
            $this->writeAcfPostClass($namespace, $appDir, $postType);
            $this->registerAppAutoloader($namespace, $appDir);

            Kernel::boot(KernelConfig::theme($appDir, $namespace, $cacheDir, false, false));

            $postId = wp_insert_post([
                'post_type' => $postType,
                'post_status' => 'publish',
                'post_title' => 'ACF Bridge Integration',
            ]);
            self::assertIsInt($postId);
            self::assertGreaterThan(0, $postId);
            $this->posts[] = $postId;

            $postClass = $namespace . '\\Post\\AcfStoredPost';
            self::assertTrue(post_type_exists($postType), "fixture post type が登録されていません: {$postType}");

            if (!is_subclass_of($postClass, PostType::class)) {
                self::fail("fixture post type class が見つかりません: {$postClass}");
            }

            $bag = $postClass::meta($postId);
            self::assertFalse($bag->has('price'));
            self::assertTrue($bag->set('price', '1200'));
            self::assertTrue($bag->has('price'));
            self::assertSame('1200', $bag->string('price'));
            self::assertTrue($bag->delete('price'));
            self::assertFalse($bag->has('price'));
        }

        private function writeAcfPostClass(string $namespace, string $appDir, string $postType): void
        {
            $dir = $appDir . '/Post';

            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                self::fail("fixture ディレクトリを作成できません: {$dir}");
            }

            $this->writeFile($dir . '/AcfStoredPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\AcfBridge\\AcfMetaStorage;
use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Attribute\\UseMetaStorage;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
#[UseMetaStorage(AcfMetaStorage::class)]
final class AcfStoredPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')
            ->label('ACF保存テスト')
            ->supports('title');
    }
}
PHP);
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

                if (is_file($file)) {
                    require $file;
                }
            };

            spl_autoload_register($loader);
            $this->autoloaders[] = $loader;
        }

        private function writeFile(string $file, string $contents): string
        {
            $bytes = file_put_contents($file, $contents);

            if ($bytes === false) {
                self::fail("fixture ファイルを書き込めません: {$file}");
            }

            return $file;
        }
    }
}
