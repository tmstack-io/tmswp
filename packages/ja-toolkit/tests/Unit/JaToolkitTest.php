<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use InvalidArgumentException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\DisableTexturize;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;
use TMSWP\JaToolkit\ModuleInterface;

final class JaToolkitTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        JaToolkit::reset();
        parent::tearDown();
    }

    public function testEnableRegistersOnlyExplicitModules(): void
    {
        Functions\when('did_action')->justReturn(0);
        Filters\expectAdded('run_wptexturize')->once()->with('__return_false');
        Actions\expectAdded('init')->once();

        self::assertFalse(JaToolkit::isEnabled(DisableTexturize::class));

        JaToolkit::enable([DisableTexturize::class]);

        self::assertTrue(JaToolkit::isEnabled(DisableTexturize::class));
    }

    public function testEnableRejectsDuplicateModule(): void
    {
        Functions\when('did_action')->justReturn(0);
        Filters\expectAdded('run_wptexturize')->once();
        Actions\expectAdded('init')->once();

        JaToolkit::enable([DisableTexturize::class]);

        $this->expectException(LogicException::class);

        JaToolkit::enable([DisableTexturize::class]);
    }

    public function testEnableRejectsCallAfterInit(): void
    {
        Functions\when('did_action')->justReturn(1);

        $this->expectException(LogicException::class);

        JaToolkit::enable([DisableTexturize::class]);
    }

    public function testClassStringRequiresOptionalConstructor(): void
    {
        Functions\when('did_action')->justReturn(0);
        Actions\expectAdded('init')->once();

        $this->expectException(LogicException::class);

        JaToolkit::enable([RequiredConstructorModuleFixture::class]);
    }

    public function testJapaneseSlugConvertsEncodedMultibyteSlugAfterPostIdExists(): void
    {
        $module = new JapaneseSlug();

        self::assertSame('post-123', $module->filterSlug('%e6%9d%b1%e4%ba%ac', 123, 'publish', 'post'));
        self::assertSame('%e6%9d%b1%e4%ba%ac', $module->filterSlug('%e6%9d%b1%e4%ba%ac', 0, 'publish', 'post'));
        self::assertSame('plain-slug', $module->filterSlug('plain-slug', 123, 'publish', 'post'));
    }

    public function testFullwidthSearchNormalizerRegistersPreGetPosts(): void
    {
        Actions\expectAdded('pre_get_posts')->once();

        (new FullwidthSearchNormalizer())->register();
    }
}

final class RequiredConstructorModuleFixture implements ModuleInterface
{
    public function __construct(string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('value must not be empty');
        }
    }

    public function register(): void {}
}
