<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Log;

use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\UnknownLogChannelException;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Testing\UnitTestCase;

/**
 * LoggerRegistryのチャネルの管理と、レベル・スコープによる振り分け(docs/07 §5)。旧iwf2bのLogTestの移植先
 * (docs/10 §8.1の19)。
 */
final class LoggerRegistryTest extends UnitTestCase
{
    public function testRegistryDispatchesByLevelAndScopeWithoutScopeLeak(): void
    {
        $default = new ArrayLogger();
        $mail = new ArrayLogger();
        $errors = new ArrayLogger();
        $registry = new LoggerRegistry([
            'default' => new LogChannel($default),
            'mail' => new LogChannel($mail, scopes: ['mail']),
            'errors' => new LogChannel($errors, levels: [LogLevel::ERROR]),
        ]);

        $registry->info('plain');
        $registry->scope('mail')->error('failed', ['id' => 10]);
        $registry->info('plain again');

        self::assertCount(3, $default->records);
        self::assertCount(1, $mail->records);
        self::assertCount(1, $errors->records);
        self::assertSame('mail', $mail->records[0]['context']['_scope']);
        self::assertArrayNotHasKey('_scope', $default->records[2]['context']);
    }

    public function testRegistryWithDeclaredChannelsNeverCreatesDefaultChannel(): void
    {
        $logger = new ArrayLogger();
        $registry = new LoggerRegistry(['errors' => new LogChannel($logger, levels: [LogLevel::ERROR])]);

        // 宣言したチャネルが受け付けない記録は、どこにも出ない(default チャネルを作らない)
        $registry->info('ignored');

        self::assertSame([], $logger->records);
        self::assertFalse($registry->has('default'));
    }

    public function testRegistryRejectsEmptyChannelName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('log channel must not be empty');

        new LoggerRegistry(['' => new LogChannel(new ArrayLogger())]);
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
