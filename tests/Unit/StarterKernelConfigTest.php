<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Tests\Support\UnitTestCase;

/**
 * スターターのtmswp-kernel.phpが返すKernelの構成を確かめる。
 */
final class StarterKernelConfigTest extends UnitTestCase
{
    #[DataProvider('starters')]
    public function testStarterKernelUsesTheStarterNamespaceAndJapaneseLabels(string $kind, KernelContext $context, string $namespace): void
    {
        $root = \dirname(__DIR__, 2) . '/starters/' . $kind;

        $config = KernelConfig::load($root . '/tmswp-kernel.php');

        self::assertSame($context, $config->context);
        self::assertSame($namespace, $config->appNamespace);
        self::assertSame(realpath($root . '/app'), $config->appDir);
        self::assertSame(LabelLanguage::Ja, $config->labelLanguage);
    }

    /**
     * @return iterable<string, array{string, KernelContext, string}>
     */
    public static function starters(): iterable
    {
        yield 'theme' => ['theme', KernelContext::Theme, 'TmswpStarterTheme'];
        yield 'plugin' => ['plugin', KernelContext::Plugin, 'TmswpStarterPlugin'];
    }
}
