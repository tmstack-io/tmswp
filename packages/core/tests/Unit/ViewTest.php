<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use Closure;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use TMSWP\Core\Exception\InvalidTemplateSlugException;
use TMSWP\Core\Exception\TemplateNotFoundException;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\View\ActionRouter;
use TMSWP\Core\View\Template;
use TMSWP\Core\View\TemplateLocator;
use TMSWP\Core\View\View;
use WP_Query;

final class ViewTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Template::setLocator(null);
        View::reset();
        unset($GLOBALS['wp_query']);

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

    public function testViewBagAndTmsVarHelperMergeAndWarnOnMissingWithoutDefault(): void
    {
        View::set(['posts' => [1], 'nullable' => null]);
        View::set(['form' => 'contact']);

        self::assertTrue(View::has('nullable'));
        self::assertSame([1], tms_var('posts'));
        self::assertSame('contact', View::get('form'));
        self::assertSame([], View::get('missing', []));
    }

    public function testActionRouterPassesThroughWhenTemplateIsNotActionTarget(): void
    {
        $router = new ActionRouter();
        $child = $this->createTempDirectory('router-child');
        $parent = $this->createTempDirectory('router-parent');

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $parent);

        self::assertNull($router->dispatch(null));
        self::assertSame('/plugin/template.php', $router->dispatch('/plugin/template.php'));
        self::assertSame($child . '/single-event.php', $router->dispatch($child . '/single-event.php'));
    }

    public function testActionRouterIncludesParentActionAndSetsViewVars(): void
    {
        $router = new ActionRouter();
        $child = $this->createTempDirectory('router-action-child');
        $parent = $this->createTempDirectory('router-action-parent');
        $GLOBALS['wp_query'] = Mockery::mock(WP_Query::class);

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $parent);

        $this->writeFile(
            $parent . '/actions/single-event.php',
            <<<'PHP'
<?php
return [
    'query' => $wp_query instanceof \WP_Query,
    'visible' => array_keys(get_defined_vars()),
];
PHP,
        );

        $template = $child . '/single-event.php';

        self::assertSame($template, $router->dispatch($template));
        self::assertTrue(View::get('query'));
        self::assertSame(['wp_query'], View::get('visible'));
    }

    public function testActionRouterForcesNotFoundThroughSignal(): void
    {
        $router = new ActionRouter();
        $child = $this->createTempDirectory('router-404-child');
        $parent = $this->createTempDirectory('router-404-parent');
        $wpQuery = Mockery::mock(WP_Query::class);
        $wpQuery->expects('set_404');
        $GLOBALS['wp_query'] = $wpQuery;

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $parent);
        Functions\when('get_404_template')->alias(static fn(): string => $child . '/404.php');
        Functions\when('get_index_template')->alias(static fn(): string => $child . '/index.php');
        Functions\expect('status_header')->once()->with(404);
        Functions\expect('nocache_headers')->once();

        $this->writeFile(
            $child . '/actions/single-event.php',
            <<<'PHP'
<?php
return \TMSWP\Core\View\ActionRouter::notFound();
PHP,
        );

        self::assertSame($child . '/404.php', $router->dispatch($child . '/single-event.php'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testActionRouterWarnsOnInvalidReturnInDebug(): void
    {
        \define('WP_DEBUG', true);

        $router = new ActionRouter();
        $child = $this->createTempDirectory('router-invalid-child');
        $parent = $this->createTempDirectory('router-invalid-parent');
        $GLOBALS['wp_query'] = Mockery::mock(WP_Query::class);

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $parent);
        Functions\when('is_admin')->alias(static fn(): bool => false);
        Functions\expect('add_action')->once()->with('wp_footer', Mockery::type(Closure::class), 999, 0);
        Functions\expect('wp_trigger_error')
            ->once()
            ->with(ActionRouter::class . '::dispatch', 'actions file must return array, ActionRouter::notFound(), or null.', E_USER_WARNING);

        $this->writeFile(
            $child . '/actions/single-event.php',
            <<<'PHP'
<?php
return 'invalid';
PHP,
        );

        $template = $child . '/single-event.php';

        self::assertSame($template, $router->dispatch($template));
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
