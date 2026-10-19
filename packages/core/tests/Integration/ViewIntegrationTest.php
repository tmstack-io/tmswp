<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Tests\Support\IntegrationTestCase;
use TMSWP\Core\View\Template;
use TMSWP\Core\View\View;
use WP_Query;

final class ViewIntegrationTest extends IntegrationTestCase
{
    public function tear_down(): void
    {
        View::reset();

        parent::tear_down();
    }

    public function testThemeBootDispatchesActionsOnTemplateInclude(): void
    {
        $GLOBALS['wp_query'] = new WP_Query();
        $template = get_stylesheet_directory() . '/index.php';

        self::assertSame($template, apply_filters('template_include', $template));
        self::assertSame('from action', View::get('message'));
    }

    public function testThemeActionCanCreateAndRenderForm(): void
    {
        $GLOBALS['wp_query'] = new WP_Query();
        $template = get_stylesheet_directory() . '/contact.php';

        self::assertSame($template, apply_filters('template_include', $template));
        self::assertStringContainsString(
            'name="name"',
            Template::render('parts/contact-form', ['form' => View::get('contactForm')]),
        );
    }

    public function testPluginBootRegistersTemplateFallbackDirectory(): void
    {
        self::assertSame($this->pluginTemplatesDir() . '/plugin/card.php', Template::locate('plugin/card'));
        self::assertSame('plugin fallback', Template::render('plugin/card'));
    }

    public function testPluginTemplateFallbackCanRenderFormControl(): void
    {
        $html = Template::render('plugin/form');

        self::assertStringContainsString('class="plugin-form-name"', $html);
        self::assertStringContainsString('name="name"', $html);
    }

    private function pluginTemplatesDir(): string
    {
        $pluginFile = self::pluginKernel()->config()->pluginFile;
        self::assertNotNull($pluginFile);

        return \dirname($pluginFile) . '/templates';
    }
}
