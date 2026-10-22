<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidTemplateSlugException;
use TMSWP\Core\Exception\TemplateNotFoundException;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\View\Template;
use TMSWP\Core\View\TemplateLocator;

final class ViewTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Template::setLocator(null);

        parent::tearDown();
    }

    public function testTemplateLocatorResolvesThemeBeforeFallbackAndRejectsInvalidSlug(): void
    {
        $child = $this->createTempDirectory('template-child');
        $parent = $this->createTempDirectory('template-parent');
        $fallback = $this->createTempDirectory('template-fallback');
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $parent);

        $this->writeFile($fallback . '/parts/card.php', 'fallback');
        $this->writeFile($parent . '/parts/card.php', 'parent');
        $this->writeFile($child . '/parts/card.php', 'child');

        $locator->addFallbackDir($fallback);
        $locator->addFallbackDir($fallback);

        self::assertSame([$fallback], $locator->fallbackDirs());
        self::assertSame($child . '/parts/card.php', $locator->locate('parts/card'));

        unlink($child . '/parts/card.php');
        $locator->flush();
        self::assertSame($parent . '/parts/card.php', $locator->locate('parts/card'));

        unlink($parent . '/parts/card.php');
        $locator->flush();
        self::assertSame($fallback . '/parts/card.php', $locator->locate('parts/card'));

        $this->expectException(InvalidTemplateSlugException::class);
        $locator->locate('../card');
    }

    #[DataProvider('emptyFallbackDirProvider')]
    public function testTemplateLocatorRejectsFallbackDirThatIsEmptyAfterTrimmingSeparators(string $dir): void
    {
        $locator = new TemplateLocator();

        try {
            $locator->addFallbackDir($dir);
            self::fail('TemplateLocator::addFallbackDir() は空になるパスで例外を投げる必要があります。');
        } catch (InvalidArgumentException $exception) {
            self::assertSame("template fallback directory must not be empty: '{$dir}'", $exception->getMessage());
            self::assertSame([], $locator->fallbackDirs());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function emptyFallbackDirProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'slash' => ['/'];
        yield 'backslash' => ['\\'];
        yield 'mixed separators' => ['/\\/'];
    }

    public function testTemplateRenderIncludesOnlyArgsVariableAndCleansBufferOnFailure(): void
    {
        $child = $this->createTempDirectory('render-child');
        $parent = $this->createTempDirectory('render-parent');
        $fallback = $this->createTempDirectory('render-fallback');
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $parent);

        $this->writeFile(
            $fallback . '/parts/card.php',
            <<<'PHP'
<?php
echo $args['title'] . '|' . implode(',', array_keys(get_defined_vars()));
PHP,
        );
        $this->writeFile(
            $fallback . '/parts/failing.php',
            <<<'PHP'
<?php
echo 'dirty';
throw new RuntimeException('render failed');
PHP,
        );

        $locator->addFallbackDir($fallback);
        Template::setLocator($locator);

        self::assertSame('Card|args', Template::render('parts/card', ['title' => 'Card']));

        ob_start();

        try {
            Template::render('parts/failing');
            self::fail('Template::render() は例外を再送出する必要があります。');
        } catch (RuntimeException $exception) {
            self::assertSame('render failed', $exception->getMessage());
            self::assertSame('', (string) ob_get_clean());
        }
    }

    public function testTemplateIncludesFileOutsideTemplateClassScope(): void
    {
        $fallback = $this->createTempDirectory('scope-fallback');
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $fallback . '/missing-child');
        Functions\when('get_template_directory')->alias(static fn(): string => $fallback . '/missing-parent');

        $this->writeFile(
            $fallback . '/parts/probe.php',
            <<<'PHP'
<?php
$results = [];

try {
    new \TMSWP\Core\View\Template();
    $results[] = 'constructor:accessible';
} catch (\Error $error) {
    $results[] = 'constructor:' . $error->getMessage();
}

try {
    \TMSWP\Core\View\Template::$locator;
    $results[] = 'property:accessible';
} catch (\Error $error) {
    $results[] = 'property:' . $error->getMessage();
}

$results[] = isset($this) ? 'this:bound' : 'this:unbound';
echo implode("\n", $results);
PHP,
        );

        $locator->addFallbackDir($fallback);
        Template::setLocator($locator);

        self::assertSame(
            implode("\n", [
                'constructor:Call to private TMSWP\Core\View\Template::__construct() from global scope',
                'property:Cannot access private property TMSWP\Core\View\Template::$locator',
                'this:unbound',
            ]),
            Template::render('parts/probe'),
        );
    }

    public function testTemplatePartFiresGetTemplatePartHooksBeforeIncludingTemplate(): void
    {
        $fallback = $this->createTempDirectory('hook-fallback');
        $locator = new TemplateLocator();
        $args = ['title' => 'Card'];

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $fallback . '/missing-child');
        Functions\when('get_template_directory')->alias(static fn(): string => $fallback . '/missing-parent');

        $this->writeFile($fallback . '/parts/card.php', '<?php echo $args[\'title\'];');

        $locator->addFallbackDir($fallback);
        Template::setLocator($locator);

        Actions\expectDone('get_template_part_parts/card')
            ->once()
            ->with('parts/card', null, $args)
            ->whenHappen(static function (): void {
                echo '[slug]';
            });
        Actions\expectDone('get_template_part')
            ->once()
            ->with('parts/card', '', ['parts/card.php'], $args)
            ->whenHappen(static function (): void {
                echo '[generic]';
            });

        self::assertSame('[slug][generic]Card', Template::render('parts/card', $args));
    }

    public function testTemplatePartLocatesTemplateAfterFiringHooks(): void
    {
        $child = $this->createTempDirectory('hook-created-child');

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $child);

        Actions\expectDone('get_template_part_parts/created')
            ->once()
            ->whenHappen(function () use ($child): void {
                $this->writeFile($child . '/parts/created.php', '<?php echo \'created by hook\';');
            });

        self::assertSame('created by hook', Template::render('parts/created'));
    }

    public function testTemplatePartFiresGetTemplatePartHooksBeforeThrowingForMissingTemplate(): void
    {
        $child = $this->createTempDirectory('missing-hook-child');
        $fired = [];

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $child);

        Actions\expectDone('get_template_part_parts/missing')
            ->once()
            ->with('parts/missing', null, [])
            ->whenHappen(static function () use (&$fired): void {
                $fired[] = 'slug';
            });
        Actions\expectDone('get_template_part')
            ->once()
            ->with('parts/missing', '', ['parts/missing.php'], [])
            ->whenHappen(static function () use (&$fired): void {
                $fired[] = 'generic';
            });

        try {
            Template::part('parts/missing');
            self::fail('Template::part() は存在しないテンプレートで例外を投げる必要があります。');
        } catch (TemplateNotFoundException) {
            self::assertSame(['slug', 'generic'], $fired);
        }
    }

    public function testTemplatePartRejectsInvalidSlugBeforeFiringHooks(): void
    {
        Actions\expectDone('get_template_part')->never();

        $this->expectException(InvalidTemplateSlugException::class);

        Template::part('../card');
    }

    public function testTemplatePartThrowsWhenTemplateIsMissing(): void
    {
        $child = $this->createTempDirectory('missing-child');
        $parent = $this->createTempDirectory('missing-parent');

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $parent);

        $this->expectException(TemplateNotFoundException::class);
        $this->expectExceptionMessage('template \'parts/missing\' was not found.');

        Template::part('parts/missing');
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("テストファイル用ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("テストファイルを書き込めません: {$path}");
        }
    }
}
