<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Kernel\ComponentCache;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class ComponentCacheTest extends UnitTestCase
{
    public function testItStoresAndLoadsDiscoveryResult(): void
    {
        $cache = new ComponentCache($this->createTempDirectory('cache') . '/components.php', true);
        $result = new DiscoveryResult(
            [
                new DiscoveredComponent(ContainerService::class, AsHook::class, [], __FILE__, $this->mtime(__FILE__)),
            ],
            [
                new DiscoveredHookMethod(ContainerService::class, 'handle', 'init', 10, 0),
            ],
            [__FILE__ => $this->mtime(__FILE__)],
            [__DIR__ => $this->mtime(__DIR__)],
        );

        self::assertTrue($cache->store($result));

        $loaded = $cache->load();

        self::assertInstanceOf(DiscoveryResult::class, $loaded);
        self::assertCount(1, $loaded->components);
        self::assertCount(1, $loaded->hookMethods);
        self::assertSame(ContainerService::class, $loaded->components[0]->class);
        self::assertSame('init', $loaded->hookMethods[0]->hook);
    }

    public function testItIgnoresInvalidSchema(): void
    {
        $file = $this->createTempDirectory('invalid-cache') . '/components.php';
        $this->writeFile($file, "<?php return ['schema' => 1];");

        self::assertNull((new ComponentCache($file, true))->load());
    }

    public function testItIgnoresAppDirMismatch(): void
    {
        $file = $this->createTempDirectory('app-dir-mismatch-cache') . '/components.php';
        $payload = [
            'schema' => ComponentCache::SCHEMA_VERSION,
            'appDir' => '/wrong/app',
            'generated' => time(),
            'dirs' => [__DIR__ => $this->mtime(__DIR__)],
            'files' => [],
            'components' => [],
            'hookMethods' => [],
        ];

        $this->writeFile($file, '<?php return ' . var_export($payload, true) . ';');

        self::assertNull((new ComponentCache($file, true))->load());
    }

    public function testItIgnoresStaleMtimes(): void
    {
        $cache = new ComponentCache($this->createTempDirectory('stale-cache') . '/components.php', true);
        $result = new DiscoveryResult([], [], [__FILE__ => 0], [__DIR__ => $this->mtime(__DIR__)]);

        self::assertTrue($cache->store($result));
        self::assertNull($cache->load());
    }

    private function writeFile(string $file, string $contents): void
    {
        $bytes = file_put_contents($file, $contents);

        if ($bytes === false) {
            self::fail("テストファイルを書き込めません: {$file}");
        }
    }

    private function mtime(string $path): int
    {
        $mtime = filemtime($path);

        if ($mtime === false) {
            self::fail("mtime を取得できません: {$path}");
        }

        return $mtime;
    }
}
