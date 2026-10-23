<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPageDefinition;
use TMSWP\SettingsPage\SettingsPages;

final class SettingsPageTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        SettingsPages::reset();
        parent::tearDown();
    }

    public function testDefinitionDerivesTitlesFromSlug(): void
    {
        $definition = new SettingsPageDefinition(slug: 'site-settings');

        self::assertSame('Site Settings', $definition->pageTitle);
        self::assertSame('Site Settings', $definition->menuTitle);
    }

    public function testRegisterRejectsDuplicateCall(): void
    {
        SettingsPages::register();

        $this->expectException(LogicException::class);

        SettingsPages::register();
    }

    public function testManualRegisterSchedulesMenuAndRejectsDuplicateSlug(): void
    {
        Actions\expectAdded('admin_menu')->once();

        SettingsPages::register([SiteSettingsPageFixture::class]);

        $this->expectException(LogicException::class);

        SettingsPages::registerPage(DuplicateSiteSettingsPageFixture::class);
    }

    public function testFormApplyDefaultsSurvivesGetHandle(): void
    {
        Functions\when('nocache_headers')->justReturn();
        $form = new Form(FormDefinition::create('site')->text('title', 'Title'));

        $form->applyDefaults(['title' => 'Configured']);
        $form->handle(new \TMSWP\Core\Form\FormRequest('GET'));

        self::assertSame('Configured', $form->values()['title']);
    }
}

final class SiteSettingsPageFixture extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'site-settings');
    }

    protected function render(?Form $form): void {}
}

final class DuplicateSiteSettingsPageFixture extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'site-settings');
    }

    protected function render(?Form $form): void {}
}
