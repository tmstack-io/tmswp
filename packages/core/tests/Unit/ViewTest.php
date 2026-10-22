<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use RuntimeException;
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
