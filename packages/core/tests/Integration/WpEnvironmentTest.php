<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Tests\Support\IntegrationTestCase;

final class WpEnvironmentTest extends IntegrationTestCase
{
    public function testWordPressVersionIsSupported(): void
    {
        self::assertGreaterThanOrEqual(0, version_compare(get_bloginfo('version'), '7.0'));
    }
}
