<?php

declare(strict_types=1);

namespace TmswpStarterPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\SimpleLogger;

/**
 * tmswp-kernel.phpの構成を、WordPressを起動せずにKernelConfig::load()で読んで確かめる例。
 * ログのチャンネルのSimpleLoggerは、tests/bootstrap.phpが一時ディレクトリの下に定義したWP_CONTENT_DIRを出力先の基準にする。
 */
final class TmswpKernelTest extends TestCase
{
    public function testLogChannelsCanBeCreatedWithoutWordPress(): void
    {
        $config = KernelConfig::load(\dirname(__DIR__, 2) . '/tmswp-kernel.php');

        self::assertNotNull($config->logChannels);
        $channels = ($config->logChannels)();

        self::assertArrayHasKey('default', $channels);
        self::assertInstanceOf(SimpleLogger::class, $channels['default']->logger);
    }
}
