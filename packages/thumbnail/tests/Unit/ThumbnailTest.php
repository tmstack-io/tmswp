<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\Tests\Support\WpdbTestDouble;
use TMSWP\Thumbnail\Exception\InvalidThumbOptionException;
use TMSWP\Thumbnail\Exception\InvalidThumbSourceException;
use TMSWP\Thumbnail\RestController;
use TMSWP\Thumbnail\SourceResolver;
use TMSWP\Thumbnail\ThumbCache;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;
use TMSWP\Thumbnail\UrlSigner;

final class ThumbnailTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Thumbnail::reset();
        Kernel::reset();
        unset($GLOBALS['wpdb'], $GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        parent::tearDown();
    }

    public function testSignerUsesRawPayloadValues(): void
    {
        $signer = new UrlSigner('secret');
        $signature = $signer->sign('id:1', 50, 40, '');

        self::assertTrue($signer->verify('id:1', '50', '40', '', $signature));
        self::assertFalse($signer->verify('id:1', '050', '40', '', $signature));
    }

    public function testNormalizeOptionsSortsAndOmitsDefaults(): void
    {
        $config = new ThumbnailConfig();

        self::assertSame('', Thumbnail::normalizeOptions([], $config));
        self::assertSame('crop=0&quality=90', Thumbnail::normalizeOptions(['quality' => 90, 'crop' => false], $config));

        $this->expectException(InvalidThumbOptionException::class);
        Thumbnail::normalizeOptions(['invalid' => true], $config);
    }

    public function testParseOptionsRejectsOutOfRangeQuality(): void
    {
        $this->expectException(InvalidThumbOptionException::class);

        RestController::parseOptions('quality=101', new ThumbnailConfig());
    }

    public function testSourceResolverRejectsPathsOutsideUploads(): void
    {
        $uploads = $this->createTempDirectory('uploads');
        $evil = $this->createTempDirectory('uploads-evil');
        $file = $evil . '/image.jpg';
        file_put_contents($file, 'jpeg');

        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => $uploads,
            'baseurl' => 'https://example.test/wp-content/uploads',
        ]);
        Functions\when('get_attached_file')->justReturn($file);

        self::assertNull((new SourceResolver())->resolve('id:123'));
    }

    public function testUrlReturnsEmptyStringAndLogsWhenDebugIsDisabled(): void
    {
        $logger = $this->bootKernelContainingThumbnail();
        Actions\expectAdded('rest_api_init')->once();
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => $this->createTempDirectory('thumb-uploads'),
            'baseurl' => 'https://example.test/wp-content/uploads',
        ]);

        Thumbnail::register(new ThumbnailConfig());

        self::assertSame('', Thumbnail::url('missing.jpg', 100, 100));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('failed to build thumbnail URL', $logger->records[0]['message']);
        self::assertInstanceOf(InvalidThumbSourceException::class, $logger->records[0]['context']['exception']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUrlRethrowsAfterLoggingWhenDebugIsEnabled(): void
    {
        \define('WP_DEBUG', true);

        $logger = $this->bootKernelContainingThumbnail();
        Actions\expectAdded('rest_api_init')->once();
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => $this->createTempDirectory('thumb-uploads'),
            'baseurl' => 'https://example.test/wp-content/uploads',
        ]);

        Thumbnail::register(new ThumbnailConfig());

        try {
            Thumbnail::url('missing.jpg', 100, 100);
            self::fail('例外が送出されませんでした。');
        } catch (InvalidThumbSourceException $exception) {
            self::assertSame('thumbnail source could not be resolved: missing.jpg', $exception->getMessage());
            self::assertCount(1, $logger->records);
            self::assertSame('failed to build thumbnail URL', $logger->records[0]['message']);
        }
    }

    /**
     * packages/thumbnail をルートとする Kernel を boot し、そのログを記録するロガーを返す。
     *
     * Thumbnail は Kernel を受け取らず(Kernel ごとの拡張への移行は v0.7.0)、Log の静的な呼び出しで記録する。
     * 記録先は呼び出したファイル(packages/thumbnail/src/Thumbnail.php)を含むルートの Kernel になるため、
     * packages/thumbnail をルートとする Kernel を起動する。appDir には、走査の対象になるクラスの無い languages/ を使う。
     */
    private function bootKernelContainingThumbnail(): ThumbnailArrayLogger
    {
        $GLOBALS['wpdb'] = WpdbTestDouble::create();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        $logger = new ThumbnailArrayLogger();
        // テーマ形態の Kernel は子テーマのディレクトリも記録先の判定に使う(ここでは子テーマ無し)
        Functions\when('get_template_directory')->justReturn(sys_get_temp_dir());
        Functions\when('get_stylesheet_directory')->justReturn(sys_get_temp_dir());

        Kernel::boot(KernelConfig::theme(
            \dirname(__DIR__, 2) . '/languages',
            'Example\Tests\ThumbnailKernel' . uniqid(),
            true,
            logChannels: static fn(): array => ['default' => new LogChannel($logger)],
        ));

        return $logger;
    }

    public function testThumbCacheKeyChangesWhenMtimeChanges(): void
    {
        $cache = new ThumbCache('/tmp/cache', 'https://example.test/cache');

        self::assertNotSame(
            $cache->key('a.jpg', 1, 100, 100, ''),
            $cache->key('a.jpg', 2, 100, 100, ''),
        );
    }
}

final class ThumbnailArrayLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
