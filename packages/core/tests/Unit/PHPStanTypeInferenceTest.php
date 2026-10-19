<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TMSWP\Core\Tests\Support\PHPStanFixtureAutoloader;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PHPStanTypeInferenceTest extends TypeInferenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PHPStanFixtureAutoloader::register();
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan/extension.neon'];
    }

    public function testMetaBagAndOptionGetterTypesAreInferredFromSchemas(): void
    {
        $this->assertTypesInFile(__DIR__ . '/../fixtures/phpstan/type-inference.php');
    }

    public function testInferenceDoesNotLoadAnalysedClasses(): void
    {
        $this->assertTypesInFile(__DIR__ . '/../fixtures/phpstan/side-effect-type-inference.php');

        self::assertTrue(PHPStanFixtureAutoloader::sideEffectClassesAreUnloaded());
    }

    private function assertTypesInFile(string $file): void
    {
        $asserts = self::gatherAssertTypes($file);
        self::assertNotSame([], $asserts);

        foreach ($asserts as $assert) {
            $this->assertFileAsserts(...$assert);
        }
    }
}
