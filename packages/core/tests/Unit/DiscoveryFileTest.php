<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use RuntimeException;
use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\HookMethodKind;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class DiscoveryFileTest extends UnitTestCase
{
    public function testItWritesAndLoadsDiscoveryResult(): void
    {
        $file = new DiscoveryFile($this->config($this->createTempDirectory('round-trip')));
        $result = $this->discoveryResult();

        $file->write($result);

        self::assertEquals($result, $file->load());
        self::assertTrue($file->isUpToDate($result));
        self::assertSame([], glob(\dirname($file->path()) . '/*.tmp'));
    }

    public function testRenderedContentsDoNotDependOnAbsolutePaths(): void
    {
        $firstRoot = $this->createTempDirectory('render-first');
        $secondRoot = $this->createTempDirectory('render-second');
        $first = new DiscoveryFile($this->config($firstRoot));
        $second = new DiscoveryFile($this->config($secondRoot));

        $contents = $first->render($this->discoveryResult());

        self::assertSame($contents, $second->render($this->discoveryResult()));
        self::assertStringNotContainsString($firstRoot, $contents);
        self::assertStringNotContainsString(sys_get_temp_dir(), $contents);
        self::assertStringContainsString("'appDir' => 'app'", $contents);
    }

    public function testIsUpToDateDetectsMissingAndStaleFiles(): void
    {
        $file = new DiscoveryFile($this->config($this->createTempDirectory('up-to-date')));

        self::assertFalse($file->isUpToDate($this->discoveryResult()));

        $file->write(new DiscoveryResult([], []));

        self::assertFalse($file->isUpToDate($this->discoveryResult()));
        self::assertTrue($file->isUpToDate(new DiscoveryResult([], [])));
    }

    public function testWriteFailsWhenDirectoryIsNotWritable(): void
    {
        $root = $this->createTempDirectory('read-only');
        $file = new DiscoveryFile($this->config($root));
        self::assertTrue(chmod($root, 0555));

        try {
            $this->expectException(DiscoveryException::class);
            $this->expectExceptionMessage('failed to write discovery file');

            // 書き込めない場合の PHP の警告は DiscoveryException の前に出るため、警告を例外にせず読み捨てる
            set_error_handler(static fn(): bool => true, E_WARNING);
            $file->write($this->discoveryResult());
        } finally {
            restore_error_handler();
            chmod($root, 0775);
        }
    }

    public function testLoadFailsWhenFileIsMissing(): void
    {
        $root = $this->createTempDirectory('missing');

        $this->expectLoadFailure($root, 'discovery file not found');
    }

    public function testLoadFailsWhenIncludeThrows(): void
    {
        $root = $this->createTempDirectory('throwing');
        $this->writeDiscoveryFile($root, "<?php\n\nthrow new \\RuntimeException('broken discovery file');\n");

        try {
            (new DiscoveryFile($this->config($root)))->load();
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringContainsString('broken discovery file', $exception->getMessage());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function testLoadFailsWhenFileDoesNotReturnArray(): void
    {
        $root = $this->createTempDirectory('not-array');
        $this->writeDiscoveryFile($root, "<?php\n\nreturn 'not an array';\n");

        $this->expectLoadFailure($root, 'discovery file must return an array');
    }

    public function testLoadFailsOnSchemaMismatch(): void
    {
        $root = $this->createTempDirectory('schema');
        $this->writePayload($root, ['schema' => DiscoveryFile::SCHEMA_VERSION - 1] + $this->payload());

        $this->expectLoadFailure($root, 'discovery file schema mismatch');
    }

    public function testLoadFailsOnAppNamespaceMismatch(): void
    {
        $root = $this->createTempDirectory('namespace');
        $this->writePayload($root, ['appNamespace' => 'Example\\Other'] + $this->payload());

        $this->expectLoadFailure($root, 'another app namespace');
    }

    public function testLoadFailsOnAppDirMismatch(): void
    {
        $root = $this->createTempDirectory('app-dir');
        self::assertTrue(mkdir($root . '/other'));
        $this->writePayload($root, ['appDir' => 'other'] + $this->payload());

        $this->expectLoadFailure($root, 'another app directory');
    }

    public function testLoadFailsOnAbsoluteAppDir(): void
    {
        $root = $this->createTempDirectory('absolute-app-dir');
        $this->writePayload($root, ['appDir' => $root . '/app'] + $this->payload());

        $this->expectLoadFailure($root, 'another app directory');
    }

    public function testLoadFailsOnMalformedEntries(): void
    {
        $root = $this->createTempDirectory('malformed');
        $this->writePayload($root, ['hookMethods' => [['class' => 'Example\\Hook', 'method' => 'handle', 'hook' => 'init', 'priority' => '10', 'acceptedArgs' => 0]]] + $this->payload());

        $this->expectLoadFailure($root, 'invalid priority or acceptedArgs');
    }

    public function testLoadRejectsUnknownHookMethodKind(): void
    {
        $root = $this->createTempDirectory('hook-kind');
        $this->writePayload($root, ['hookMethods' => [['class' => 'Example\\Hook', 'method' => 'handle', 'hook' => 'init', 'priority' => 10, 'acceptedArgs' => 0, 'kind' => 'listener']]] + $this->payload());

        $this->expectLoadFailure($root, "invalid 'kind'");
    }

    private function expectLoadFailure(string $root, string $reason): void
    {
        $file = new DiscoveryFile($this->config($root));

        try {
            $file->load();
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
            self::assertStringContainsString($file->path(), $exception->getMessage());
            self::assertStringContainsString('wp tmswp discover', $exception->getMessage());
        }
    }

    private function config(string $root): KernelConfig
    {
        if (!is_dir($root . '/app')) {
            self::assertTrue(mkdir($root . '/app'));
        }

        return KernelConfig::theme($root . '/app', 'Example\\DiscoveryFile', false, false);
    }

    private function discoveryResult(): DiscoveryResult
    {
        return new DiscoveryResult(
            [
                new DiscoveredComponent('Example\\DiscoveryFile\\Option\\SiteOption', AsOptionGroup::class, ['prefix' => 'example_']),
                new DiscoveredComponent('Example\\DiscoveryFile\\Post\\ProductPost', AsPostType::class, ['type' => MetaValueType::Integer, 'list' => [1, 'two']]),
            ],
            [
                new DiscoveredHookMethod('Example\\DiscoveryFile\\Hook\\ProductHook', 'onSave', 'save_post_product', 10, 2, HookMethodKind::Action),
                new DiscoveredHookMethod('Example\\DiscoveryFile\\Hook\\ProductHook', 'title', 'the_title', 10, 0, HookMethodKind::Filter),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema' => DiscoveryFile::SCHEMA_VERSION,
            'appNamespace' => 'Example\\DiscoveryFile',
            'appDir' => 'app',
            'components' => [],
            'hookMethods' => [],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function writePayload(string $root, array $payload): void
    {
        $this->writeDiscoveryFile($root, '<?php return ' . var_export($payload, true) . ';');
    }

    private function writeDiscoveryFile(string $root, string $contents): void
    {
        $file = $this->config($root)->discoveryFile();

        if (file_put_contents($file, $contents) === false) {
            self::fail("探索結果ファイルを書き込めません: {$file}");
        }
    }
}
