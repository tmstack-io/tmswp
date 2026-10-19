<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class AcfBridgeTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        AcfBridge::reset();
        parent::tearDown();
    }

    public function testBootRegistersHooksWithoutActiveAcfRuntime(): void
    {
        Filters\expectAdded('acf/settings/load_json')->once();
        Actions\expectAdded('init')->once();

        AcfBridge::boot(new BridgeConfig([], '/tmp/acf', 'App\\Acf'));

        self::assertSame('App\\Acf', AcfBridge::config()->namespace);

        AcfBridge::registerManagedKeys();
    }
}
