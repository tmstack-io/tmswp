<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;
use Stringable;
use TMSWP\Core\Exception\UnknownLogChannelException;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Core\Log\SimpleLogger;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class LogTest extends UnitTestCase
{
    public function testRegistryDispatchesByLevelAndScopeWithoutScopeLeak(): void
    {
        $registry = new LoggerRegistry();
        $default = new ArrayLogger();
        $mail = new ArrayLogger();
        $errors = new ArrayLogger();

        $registry->set('default', $default);
        $registry->set('mail', $mail, scopes: ['mail']);
        $registry->set('errors', $errors, levels: [LogLevel::ERROR]);

        $registry->info('plain');
        $registry->scope('mail')->error('failed', ['id' => 10]);
        $registry->info('plain again');

        self::assertCount(3, $default->records);
        self::assertCount(1, $mail->records);
        self::assertCount(1, $errors->records);
        self::assertSame('mail', $mail->records[0]['context']['_scope']);
        self::assertArrayNotHasKey('_scope', $default->records[2]['context']);
    }

    public function testRegistryDoesNotRecreateDefaultChannelAfterExplicitConfiguration(): void
    {
        $registry = new LoggerRegistry();
        $logger = new ArrayLogger();

        $registry->set('default', $logger);
        $registry->remove('default');
        $registry->info('ignored');

        self::assertSame([], $logger->records);
    }

    public function testRegistryRejectsUnknownLevelAndChannel(): void
    {
        $registry = new LoggerRegistry();

        $this->expectException(UnknownLogChannelException::class);
        $registry->channel('missing');
    }

    public function testRegistryRejectsUnknownLevel(): void
    {
        $registry = new LoggerRegistry();

        $this->expectException(PsrInvalidArgumentException::class);
        $registry->log('unknown', 'message');
    }

    public function testSimpleLoggerWritesFormattedLinesProtectionFilesAndRotates(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        Functions\when('wp_mkdir_p')->alias(static function (string $path): bool {
            return is_dir($path) || mkdir($path, 0775, true);
        });
        Functions\when('wp_date')->alias(static fn(string $format): string => $format === 'Ymd' ? '20260613' : '2026-06-13 09:00:00');

        $levelDir = $dir . '/info';
        mkdir($levelDir, 0775, true);
        file_put_contents($levelDir . '/old-a.log', 'old');
        file_put_contents($levelDir . '/old-b.log', 'old');
        touch($levelDir . '/old-a.log', 1000);
        touch($levelDir . '/old-b.log', 2000);

        $logger = new SimpleLogger($dir, '{level}/{date}.log', maxFiles: 2);
        $exception = new RuntimeException('boom');
        $logger->info('created {id} {missing}', ['id' => 10, 'object' => new stdClass(), 'exception' => $exception]);

        $file = $levelDir . '/20260613.log';
        self::assertFileExists($dir . '/.htaccess');
        self::assertFileExists($dir . '/index.php');
        self::assertFileExists($file);

        $contents = file_get_contents($file);
        self::assertIsString($contents);
        self::assertStringContainsString('2026-06-13 09:00:00 [INFO] created 10 {missing} {"id":10,"object":{}}', $contents);
        self::assertStringContainsString(RuntimeException::class . ': boom in ', $contents);
        self::assertFileDoesNotExist($levelDir . '/old-a.log');
        self::assertFileExists($levelDir . '/old-b.log');
    }
}

final class ArrayLogger implements LoggerInterface
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
