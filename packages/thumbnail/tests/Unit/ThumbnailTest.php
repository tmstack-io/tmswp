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
use TMSWP\Core\Log\Log;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Core\Tests\Support\UnitTestCase;
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
        Log::setRegistry(null);
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
        $logger = new ThumbnailArrayLogger();
        $registry = new LoggerRegistry();
        $registry->set('default', $logger);
        Log::setRegistry($registry);
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

        $logger = new ThumbnailArrayLogger();
        $registry = new LoggerRegistry();
        $registry->set('default', $logger);
        Log::setRegistry($registry);
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
