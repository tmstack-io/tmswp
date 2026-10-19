<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\PHPStan;

use TMSWP\Core\Tests\Unit\PHPStanProductPost;
use TMSWP\Core\Tests\Unit\PHPStanSiteOption;

use function PHPStan\Testing\assertType;

assertType('int', PHPStanProductPost::meta(1)->get(PHPStanProductPost::META_PRICE));
assertType('string|null', PHPStanProductPost::meta(1)->get(PHPStanProductPost::META_CODE));
assertType('array<int, int>', PHPStanProductPost::meta(1)->get(PHPStanProductPost::META_RELATED_IDS));
assertType('string', PHPStanSiteOption::ContactEmail->get());
assertType('bool', PHPStanSiteOption::Maintenance->get());
