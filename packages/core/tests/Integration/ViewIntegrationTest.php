<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Tests\Support\IntegrationTestCase;
use TMSWP\Core\View\Template;

final class ViewIntegrationTest extends IntegrationTestCase
{
    public function testThemePartRendersFormPassedThroughArgs(): void
    {
        $form = new Form(FormDefinition::create('contact')->text('name', 'お名前', [new Required()]));

        self::assertStringContainsString(
            'name="name"',
            Template::render('parts/contact-form', ['form' => $form]),
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
