<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TMSWP\Core\PHPStan\Actions\NoSet404Rule;

/**
 * @extends RuleTestCase<NoSet404Rule>
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PHPStanNoSet404RuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoSet404Rule();
    }

    public function testReportsDirectWpQuerySet404Call(): void
    {
        $this->analyse([
            __DIR__ . '/../fixtures/phpstan/actions/good.php',
            __DIR__ . '/../fixtures/phpstan/actions/set-404-violation.php',
        ], [
            [
                'actions file must use ActionRouter::notFound() instead of $wp_query->set_404().',
                9,
            ],
        ]);
    }
}
