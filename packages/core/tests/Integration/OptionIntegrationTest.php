<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use BackedEnum;
use ReflectionMethod;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Option\OptionRepositoryFactory;
use TMSWP\Core\Option\WpOptionStorage;
use TMSWP\Core\Tests\Support\IntegrationTestCase;

final class OptionIntegrationTest extends IntegrationTestCase
{
    private const PREFIX = 'tmswp_fixture_';

    public function testOptionRepositoryReadsDefaultsAndWritesWithSchemaAutoload(): void
    {
        $optionClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Option\\SiteOption', BackedEnum::class);
        $repository = Kernel::forClass($optionClass)->container()->get(OptionRepositoryFactory::class)->for($optionClass);
        self::assertSame('info@example.test', $repository->string('contact_email'));
        self::assertTrue($repository->set('contact_email', 'sales@example.test'));
        self::assertSame('sales@example.test', $repository->string('contact_email'));
        self::assertSame('sales@example.test', get_option(self::PREFIX . 'contact_email'));
        self::assertArrayNotHasKey(self::PREFIX . 'contact_email', wp_load_alloptions());
        self::assertTrue($repository->set('maintenance', '1'));
        self::assertTrue($repository->bool('maintenance'));
    }

    public function testWpOptionStorageKeepsStoredNullFalseAndEmptyValues(): void
    {
        $storage = new WpOptionStorage();

        self::assertTrue($storage->set(self::PREFIX . 'null_value', null));
        self::assertTrue($storage->set(self::PREFIX . 'false_value', false));
        self::assertTrue($storage->set(self::PREFIX . 'empty_value', ''));
        self::assertTrue($storage->has(self::PREFIX . 'null_value'));
        self::assertTrue($storage->has(self::PREFIX . 'false_value'));
        self::assertTrue($storage->has(self::PREFIX . 'empty_value'));
        self::assertNull($storage->get(self::PREFIX . 'null_value'));
        self::assertFalse($storage->get(self::PREFIX . 'false_value'));
        self::assertSame('', $storage->get(self::PREFIX . 'empty_value'));
    }

    public function testOptionAccessEnumDeletesAndRewritesGroup(): void
    {
        $probeResult = (new ReflectionMethod(self::THEME_NAMESPACE . '\\Option\\SiteOptionProbe', 'exercise'))->invoke(null);
        self::assertIsArray($probeResult);
        self::assertSame(self::PREFIX . 'contact_email', $probeResult['name']);
        self::assertSame('after-delete@example.test', $probeResult['contact']);
        self::assertFalse($probeResult['maintenanceHas']);
        self::assertSame([self::PREFIX . 'contact_email', self::PREFIX . 'maintenance'], $probeResult['deleted']);
    }
}
