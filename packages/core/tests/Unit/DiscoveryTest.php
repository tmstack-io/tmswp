<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Error;
use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Hook\Hook;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
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

    public function testItSortsHookMethodsByStrcmp(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('sorted-hook-app');

        // 数字だけのフック名は配列の <=> では数値として比べられ '9' が先になる。strcmp では '10' が先になる
        $this->writePhpFile($appDir, 'Hooks/NumericHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class NumericHook extends Hook
{
    #[Filter('9')]
    #[Action('10')]
    public function handle(): void
    {
    }
}
PHP);
        $this->writePhpFile($appDir, 'Hooks/AlphaHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class AlphaHook extends Hook
{
    #[Action('init')]
    public function handle(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $result = $this->scan($namespace, $appDir);

        self::assertSame(
            [
                ["{$namespace}\\Hooks\\AlphaHook", 'init'],
                ["{$namespace}\\Hooks\\NumericHook", '10'],
                ["{$namespace}\\Hooks\\NumericHook", '9'],
            ],
            array_map(static fn($hookMethod): array => [$hookMethod->class, $hookMethod->hook], $result->hookMethods),
        );
    }

    public function testItWrapsParseErrorRaisedByAutoload(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('parse-error-app');
        $this->writePhpFile($appDir, 'Broken.php', "<?php\n\nnamespace {$namespace};\n\nfinal class Broken {\n");
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringContainsString("{$namespace}\\Broken", $exception->getMessage());
        $file = realpath($appDir . '/Broken.php');
        self::assertIsString($file);
        self::assertStringContainsString($file, $exception->getMessage());
        self::assertInstanceOf(ParseError::class, $exception->getPrevious());
    }

    public function testItWrapsErrorRaisedByAutoload(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('missing-parent-app');
        $this->writePhpFile($appDir, 'Orphan.php', "<?php\n\nnamespace {$namespace};\n\nfinal class Orphan extends MissingParent\n{\n}\n");
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringContainsString("failed to autoload {$namespace}\\Orphan", $exception->getMessage());
        self::assertInstanceOf(Error::class, $exception->getPrevious());
    }

    public function testItRejectsAsHookClassNotExtendingHook(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('plain-hook-app');

        // register() という名前のメソッドがあっても、Hook を継承していなければ #[AsHook] は誤り
        $this->writePhpFile($appDir, 'Hooks/PlainHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\AsHook;

#[AsHook]
final class PlainHook
{
    public function register(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringContainsString('#[AsHook] class must extend ' . Hook::class, $exception->getMessage());
        self::assertStringContainsString("{$namespace}\\Hooks\\PlainHook", $exception->getMessage());
    }

    public function testItFailsRegardlessOfDebug(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('non-debug-broken-app');
        $this->writePhpFile($appDir, 'Missing.php', "<?php\n\nnamespace {$namespace};\n\nfinal class Other\n{\n}\n");
        $this->registerAppAutoloader($namespace, $appDir);
        $config = KernelConfig::theme($appDir, $namespace, false, false);

        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage("is expected to define class {$namespace}\\Missing");

        (new Discovery($config, new DiscoveryFile($config)))->scan();
    }

    public function testItWrapsParseErrorOfAttributeClass(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('broken-attribute-app');
        $supportDir = $this->createTempDirectory('broken-attribute-support');

        // 属性クラスは app/ の外に置く。getAttributes(..., IS_INSTANCEOF) が autoload したときに ParseError になる
        $this->writePhpFile($supportDir, 'BrokenAttribute.php', "<?php\n\nnamespace {$namespace}Support;\n\n#[\\Attribute]\nfinal class BrokenAttribute {\n");
        $this->writePhpFile($appDir, 'Tagged.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

#[\\{$namespace}Support\\BrokenAttribute]
final class Tagged
{
}
PHP);
        $this->registerAppAutoloader($namespace . 'Support', $supportDir);
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringContainsString("failed to read {$namespace}\\Tagged", $exception->getMessage());
        self::assertStringContainsString('class: ' . $namespace . '\\Tagged', $exception->getMessage());
        self::assertStringContainsString('Tagged.php', $exception->getMessage());
        self::assertInstanceOf(ParseError::class, $exception->getPrevious());
    }

    public function testItWrapsUndefinedClassConstantInAttributeArguments(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('undefined-constant-app');
        $this->writePhpFile($appDir, 'Option/BrokenOption.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Option;

use TMSWP\\Core\\Attribute\\AsOptionGroup;

#[AsOptionGroup(prefix: self::MISSING_PREFIX)]
enum BrokenOption: string
{
    case Email = 'email';
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringContainsString("failed to read {$namespace}\\Option\\BrokenOption", $exception->getMessage());
        self::assertStringContainsString('MISSING_PREFIX', $exception->getMessage());
        self::assertStringContainsString('BrokenOption.php', $exception->getMessage());
        self::assertInstanceOf(Error::class, $exception->getPrevious());
    }

    /** @return iterable<string, array{string}> */
    public static function environmentDependentAttributeArguments(): iterable
    {
        return [
            '値' => ["['styles' => ['main' => __DIR__ . '/main.css']]"],
            '配列のキー' => ["[__DIR__ . '/main.css' => 'main']"],
            '入れ子の配列のキー' => ["['styles' => [__DIR__ => 'main']]"],
        ];
    }

    #[DataProvider('environmentDependentAttributeArguments')]
    public function testItRejectsEnvironmentDependentPathInAttributeArguments(string $assets): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('absolute-argument');
        $this->writeAssetsApp($root, $namespace, $assets, "'init'");

        $exception = $this->scanFailure($namespace, $root . '/app');

        self::assertStringContainsString("attribute argument of {$namespace}\\Assets contains the environment-dependent path {$this->realPath($root)}: '{$this->realPath($root)}", $exception->getMessage());
    }

    public function testItRejectsEnvironmentDependentPathInHookName(): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('absolute-hook');
        $this->writeAssetsApp($root, $namespace, "['styles/main.css' => 'main']", "__DIR__ . '/x'");

        $exception = $this->scanFailure($namespace, $root . '/app');

        self::assertStringContainsString(
            "hook name of {$namespace}\\Hook\\ProbeHook::handle contains the environment-dependent path {$this->realPath($root)}: '{$this->realPath($root)}/app/Hook/x'",
            $exception->getMessage(),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function rootBoundaries(): iterable
    {
        return [
            'ルートそのもの' => ['{ROOT}', true],
            'ルートの下のファイル' => ['{ROOT}/x.php', true],
            'URL の中のルート' => ['file://{ROOT}/x', true],
            '名前がルートで始まる別のディレクトリ' => ['{ROOT}bc', false],
            'ルートの後に区切り文字以外が続く' => ['{ROOT}-b', false],
        ];
    }

    /** ルートの直後が文字列の終わりか区切り文字のときだけ、環境に依存するとみなす */
    #[DataProvider('rootBoundaries')]
    public function testItMatchesRootOnlyAtSeparatorBoundary(string $template, bool $rejected): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('boundary');
        $value = str_replace('{ROOT}', $this->realPath($root), $template);
        $this->writeAssetsApp($root, $namespace, var_export(['path' => $value], true), "'init'");

        if ($rejected) {
            self::assertStringContainsString("'{$value}'", $this->scanFailure($namespace, $root . '/app')->getMessage());

            return;
        }

        $result = $this->scan($namespace, $root . '/app');
        self::assertSame(['assets' => ['path' => $value]], $result->components[0]->attributeArguments);
    }

    /** @return iterable<string, array{string}> */
    public static function wordPressRootConstants(): iterable
    {
        return [
            'ABSPATH' => ['ABSPATH'],
            'WP_CONTENT_DIR' => ['WP_CONTENT_DIR'],
        ];
    }

    /**
     * WP_CONTENT_DIR は、プラグイン・テーマ等を外に置く構成ではディレクトリが無くても WordPress が起動するため、存在しない定数のパスで止めない。
     * ABSPATH は存在しなければ WordPress 自体が起動しないが、扱いを WP_CONTENT_DIR と揃える。
     * 定数は取り消せずプロセス全体に漏れるので、分離プロセス内でだけ定義する。
     */
    #[DataProvider('wordPressRootConstants')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testItScansWhenWordPressRootDoesNotExist(string $constant): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('missing-wp-root-app');
        \define($constant, $this->createTempDirectory('missing-wp-root') . '/missing');
        $this->writeAssetsApp($root, $namespace, "['styles/main.css' => 'main']", "'init'");

        $result = $this->scan($namespace, $root . '/app');

        self::assertSame(['assets' => ['styles/main.css' => 'main']], $result->components[0]->attributeArguments);
    }

    /** @return iterable<string, array{string}> */
    public static function missingContentDirDefinitions(): iterable
    {
        return [
            '区切り文字なし' => [''],
            '末尾に区切り文字' => ['/'],
        ];
    }

    /** 存在しない WP_CONTENT_DIR でも、設定された値(末尾の区切り文字を除く)を含む文字列は拒否する */
    #[DataProvider('missingContentDirDefinitions')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testItRejectsPathUnderMissingWpContentDir(string $suffix): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('missing-content-app');
        $content = $this->createTempDirectory('missing-content') . '/content';
        \define('WP_CONTENT_DIR', $content . $suffix);
        $this->writeAssetsApp($root, $namespace, var_export(['path' => WP_CONTENT_DIR . '/x.php'], true), "'init'");

        $exception = $this->scanFailure($namespace, $root . '/app');

        self::assertStringContainsString(
            "contains the environment-dependent path {$content}: '{$content}{$suffix}/x.php'",
            $exception->getMessage(),
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function symlinkedContentDirNotations(): iterable
    {
        return [
            'リンクの表記' => [false],
            '実パス' => [true],
        ];
    }

    /** WP_CONTENT_DIR が symlink なら、リンクの表記と実パスのどちらで書いたパスも拒否する */
    #[DataProvider('symlinkedContentDirNotations')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testItRejectsBothNotationsOfSymlinkedWpContentDir(bool $resolved): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('linked-content-app');
        $base = $this->createTempDirectory('linked-content');
        mkdir($base . '/real');
        $link = $base . '/link';
        self::assertTrue(symlink($base . '/real', $link));

        try {
            \define('WP_CONTENT_DIR', $link);
            $content = $resolved ? $this->realPath($link) : $link;
            $this->writeAssetsApp($root, $namespace, var_export(['path' => $content . '/x.php'], true), "'init'");

            $exception = $this->scanFailure($namespace, $root . '/app');

            self::assertStringContainsString(
                "contains the environment-dependent path {$content}: '{$content}/x.php'",
                $exception->getMessage(),
            );
        } finally {
            // 一時ディレクトリの後始末は symlink を rmdir しようとして失敗するため、先にリンクだけ消す
            unlink($link);
        }
    }

    /**
     * 同じソースを絶対パスの違う2つのルートに置き、それぞれ別の PHP プロセスで scan → render する。
     * 環境に依存しない引数・フック名ならバイト列が一致し、__DIR__ を引数の値・配列のキー・フック名に使うと
     * どちらのルートでも例外になる。
     */
    public function testRenderedDiscoveryFileIsIdenticalAcrossRootsUnlessArgumentsContainAbsolutePaths(): void
    {
        $script = $this->createTempDirectory('render-script') . '/render.php';
        $this->writePhpFile(\dirname($script), 'render.php', <<<'PHP'
<?php

declare(strict_types=1);

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\KernelConfig;

[, $autoload, $root, $namespace] = $argv;
require $autoload;

spl_autoload_register(static function (string $class) use ($root, $namespace): void {
    $prefix = $namespace . '\\';

    if (str_starts_with($class, $prefix) && is_file($file = $root . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) {
        require $file;
    }
});

$config = KernelConfig::theme($root . '/app', $namespace, true, false);

try {
    echo (new DiscoveryFile($config))->render((new Discovery($config, new DiscoveryFile($config)))->scan());
} catch (DiscoveryException $exception) {
    echo 'DiscoveryException: ' . $exception->getMessage();
}
PHP);

        [[$first, $firstRoot], [$second, $secondRoot]] = $this->renderInTwoRoots($script, 'portable', "['styles/main.css' => ['media' => 'all']]", "'init'");
        self::assertStringContainsString("'styles/main.css' => [", $first);
        self::assertStringContainsString("'hook' => 'init'", $first);
        self::assertSame($first, $second);
        self::assertStringNotContainsString($firstRoot, $first);
        self::assertStringNotContainsString($secondRoot, $second);

        $assetsError = 'DiscoveryException: attribute argument of Example\\Tests\\PortableApp\\Assets contains the environment-dependent path ';
        $hookError = 'DiscoveryException: hook name of Example\\Tests\\PortableApp\\Hook\\ProbeHook::handle contains the environment-dependent path ';
        $failures = [
            [$assetsError, $this->renderInTwoRoots($script, 'value', "['styles' => ['main' => __DIR__ . '/main.css']]", "'init'")],
            [$assetsError, $this->renderInTwoRoots($script, 'key', "[__DIR__ . '/main.css' => 'main']", "'init'")],
            [$hookError, $this->renderInTwoRoots($script, 'hook', "['styles/main.css' => ['media' => 'all']]", "__DIR__ . '/x'")],
        ];

        foreach ($failures as [$prefix, $outputs]) {
            foreach ($outputs as [$output, $root]) {
                self::assertStringStartsWith($prefix . $root . ': ', $output);
            }
        }
    }

    /**
     * 同じソースを2つのルートに書き、それぞれ別の PHP プロセスで render した出力とルートの実パスを返す。
     *
     * @return array{array{string, string}, array{string, string}}
     */
    private function renderInTwoRoots(string $script, string $variant, string $assets, string $hook): array
    {
        $outputs = [];

        foreach (['first', 'second'] as $name) {
            $root = $this->createTempDirectory("portable-{$variant}-{$name}");
            $this->writeAssetsApp($root, 'Example\\Tests\\PortableApp', $assets, $hook);
            $outputs[] = [$this->runPhp($script, $root, 'Example\\Tests\\PortableApp'), $this->realPath($root)];
        }

        return [$outputs[0], $outputs[1]];
    }

    /**
     * 属性の引数と、Hook のフック名を差し替えられる app/ をルートの下に書く。属性クラスも app/ に置く
     * (コンポーネント属性を持たない普通のクラスとして走査される)。
     */
    private function writeAssetsApp(string $root, string $namespace, string $assets, string $hook): void
    {
        $this->writePhpFile($root, 'app/Attribute/AssetsAttribute.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Attribute;

use TMSWP\\Core\\Attribute\\ComponentAttributeInterface;
use TMSWP\\Core\\Hook\\HookInitializer;
use TMSWP\\Core\\Kernel\\KernelContext;

#[\\Attribute(\\Attribute::TARGET_CLASS)]
final class AssetsAttribute implements ComponentAttributeInterface
{
    /** @param array<array-key, mixed> \$assets */
    public function __construct(public array \$assets) {}

    public function hookName(KernelContext \$context): string
    {
        return 'init';
    }

    public function hookPriority(): int
    {
        return 10;
    }

    public function initializerClass(): string
    {
        return HookInitializer::class;
    }
}
PHP);
        $this->writePhpFile($root, 'app/Assets.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

#[Attribute\\AssetsAttribute(assets: {$assets})]
final class Assets
{
}
PHP);
        $this->writePhpFile($root, 'app/Hook/ProbeHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class ProbeHook extends Hook
{
    #[Action({$hook})]
    public function handle(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $root . '/app');
    }

    private function realPath(string $path): string
    {
        $realPath = realpath($path);
        self::assertIsString($realPath);

        return $realPath;
    }

    private function runPhp(string $script, string $root, string $namespace): string
    {
        $process = proc_open(
            [PHP_BINARY, $script, \dirname(__DIR__, 4) . '/vendor/autoload.php', $root, $namespace],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "render script failed: {$stderr}");
        self::assertIsString($stdout);

        return $stdout;
    }

    private function scanFailure(string $namespace, string $appDir): DiscoveryException
    {
        try {
            $this->scan($namespace, $appDir);
        } catch (DiscoveryException $exception) {
            return $exception;
        }

        self::fail('DiscoveryException was not thrown');
    }

    private function scan(string $namespace, string $appDir): DiscoveryResult
    {
        $config = KernelConfig::theme($appDir, $namespace, true, false);

        return (new Discovery($config, new DiscoveryFile($config)))->scan();
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
