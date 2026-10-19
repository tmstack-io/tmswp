<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../fixtures/phpstan/schema-classes.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PHPStanTypeInferenceTest extends TypeInferenceTestCase
{
    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan/extension.neon'];
    }

    public function testMetaBagAndOptionGetterTypesAreInferredFromSchemas(): void
    {
        $this->assertFileAsserts('assertType', __DIR__ . '/../fixtures/phpstan/type-inference.php');
        $this->addToAssertionCount(1);
    }
}
