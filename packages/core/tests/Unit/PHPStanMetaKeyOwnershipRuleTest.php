<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TMSWP\Core\PHPStan\MetaKeyOwnershipRule;

require_once __DIR__ . '/../fixtures/phpstan/schema-classes.php';

/**
 * @extends RuleTestCase<MetaKeyOwnershipRule>
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PHPStanMetaKeyOwnershipRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(MetaKeyOwnershipRule::class);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan/extension.neon'];
    }

    public function testReportsForeignEntityConstantsAndDeclaredStringLiterals(): void
    {
        $this->analyse([__DIR__ . '/../fixtures/phpstan/meta-key-rule.php'], [
            [
                'Meta key TMSWP\Core\Tests\Unit\PHPStanOtherPost::META_OTHER does not belong to entity TMSWP\Core\Tests\Unit\PHPStanProductPost.',
                12,
            ],
            [
                "Use TMSWP\Core\Tests\Unit\PHPStanProductPost::META_PRICE instead of string literal 'price'.",
                13,
            ],
        ]);
    }
}
