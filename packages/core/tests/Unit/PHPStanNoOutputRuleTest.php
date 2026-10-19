<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TMSWP\Core\PHPStan\Actions\NoOutputRule;

/**
 * @extends RuleTestCase<NoOutputRule>
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PHPStanNoOutputRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoOutputRule();
    }

    public function testReportsOutputSyntaxButAllowsRedirectExit(): void
    {
        $this->analyse([
            __DIR__ . '/../fixtures/phpstan/actions/good.php',
            __DIR__ . '/../fixtures/phpstan/actions/output-violations.php',
        ], [
            [
                'actions file must not output with echo or print.',
                7,
            ],
            [
                'actions file must not output with echo or print.',
                8,
            ],
            [
                'actions file must not call output functions.',
                9,
            ],
            [
                'actions file must not call output functions.',
                10,
            ],
            [
                'actions file must not exit except immediately after wp_safe_redirect().',
                11,
            ],
            [
                'actions file must not contain inline HTML.',
                14,
            ],
        ]);
    }
}
