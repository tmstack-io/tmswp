<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use WP_UnitTestCase;

final class WpEnvironmentTest extends WP_UnitTestCase
{
    public function testWordPressVersionIsSupported(): void
    {
        self::assertGreaterThanOrEqual(0, version_compare(get_bloginfo('version'), '7.0'));
    }
}
