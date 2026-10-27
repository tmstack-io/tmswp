<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Thumbnail\Cli\ThumbCommand;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;

/**
 * `wp tmswp thumb clear --days`の検証。日数は正の整数で、302のキャッシュの期間より長くなければならない。
 */
final class ThumbCommandTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidDaysProvider(): iterable
    {
        yield '負の値' => ['-1'];
        yield '0' => ['0'];
        yield '整数でない' => ['1.5'];
        yield '値の無いフラグ' => [true];
        yield '302のキャッシュの期間(2日)以下' => ['2'];
    }

    #[DataProvider('invalidDaysProvider')]
    public function testClearRejectsInvalidDays(mixed $days): void
    {
        $command = new ThumbCommand(new Thumbnail(new ThumbnailConfig(redirectMaxAge: 2 * 86400)));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wp tmswp thumb clear --days must be');

        $command->clear([], ['days' => $days]);
    }
}
