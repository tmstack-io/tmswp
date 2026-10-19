<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\ComponentCache;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class DiscoveryTest extends UnitTestCase
{
    /** @var list<callable(string): void> */
    private array $autoloaders = [];

    protected function tearDown(): void
    {
        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        $this->autoloaders = [];

        parent::tearDown();
    }

    public function testItDiscoversMethodLevelHookAttributes(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('discovery-app');

        $this->writePhpFile($appDir, 'Hooks/SampleHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class SampleHook extends Hook
{
    #[Action('init', priority: 5)]
    public function boot(): void
    {
    }

    #[Filter('the_title', priority: 12)]
    public function title(string \$title): string
    {
        return \$title;
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $result = $this->scan($namespace, $appDir);

        self::assertCount(0, $result->components);
        self::assertCount(2, $result->hookMethods);
        self::assertSame("{$namespace}\\Hooks\\SampleHook", $result->hookMethods[0]->class);
        self::assertSame('boot', $result->hookMethods[0]->method);
        self::assertSame('init', $result->hookMethods[0]->hook);
        self::assertSame(5, $result->hookMethods[0]->priority);
        self::assertSame(0, $result->hookMethods[0]->acceptedArgs);
        self::assertSame('the_title', $result->hookMethods[1]->hook);
        self::assertSame(1, $result->hookMethods[1]->acceptedArgs);
    }

    public function testItTreatsHookRegisterOverrideAsComponent(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('manual-hook-app');

        $this->writePhpFile($appDir, 'Hooks/ManualHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class ManualHook extends Hook
{
    public function register(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $result = $this->scan($namespace, $appDir);

        self::assertCount(1, $result->components);
        self::assertCount(0, $result->hookMethods);
        self::assertSame("{$namespace}\\Hooks\\ManualHook", $result->components[0]->class);
    }

    public function testItAllowsOptionSchemaOnEnumCases(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('option-schema-app');

        $this->writePhpFile($appDir, 'Option/SiteOption.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Option;

use TMSWP\\Core\\Attribute\\AsOptionGroup;
use TMSWP\\Core\\Attribute\\OptionSchema;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Option\\OptionAccess;

#[AsOptionGroup(prefix: 'example_')]
enum SiteOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: 'info@example.test')]
    case ContactEmail = 'contact_email';
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $result = $this->scan($namespace, $appDir);

        self::assertCount(1, $result->components);
        self::assertSame("{$namespace}\\Option\\SiteOption", $result->components[0]->class);
    }

    public function testItRejectsMethodHooksWithoutAsHook(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('broken-hook-app');

        $this->writePhpFile($appDir, 'Hooks/BrokenHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;

final class BrokenHook
{
    #[Action('init')]
    public function boot(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $this->expectException(DiscoveryException::class);

        $this->scan($namespace, $appDir);
    }

    private function scan(string $namespace, string $appDir): \TMSWP\Core\Kernel\DiscoveryResult
    {
        $cacheDir = $this->createTempDirectory('discovery-cache');
        $config = KernelConfig::theme($appDir, $namespace, $cacheDir, true, false);

        return (new Discovery($config, new ComponentCache($cacheDir . '/components.php', true)))->scan();
    }

    private function runtimeNamespace(): string
    {
        return 'Example\\Tests\\RuntimeApp' . uniqid();
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

    private function writePhpFile(string $appDir, string $relativePath, string $contents): void
    {
        $file = $appDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = \dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("fixture ディレクトリを作成できません: {$dir}");
        }

        $bytes = file_put_contents($file, $contents);

        if ($bytes === false) {
            self::fail("fixture ファイルを書き込めません: {$file}");
        }
    }
}
