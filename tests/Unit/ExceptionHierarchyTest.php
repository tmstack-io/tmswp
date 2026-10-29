<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit;

use OutOfBoundsException;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;
use TMSWP\Core\Exception\TmswpExceptionInterface;
use TMSWP\Core\Exception\UnknownLogChannelException;
use TMSWP\Testing\UnitTestCase;

/**
 * packages/*\/src のすべての例外クラスが TMSWP のマーカー interface を実装することを、ファイルを列挙して確かめる。
 */
final class ExceptionHierarchyTest extends UnitTestCase
{
    public function testEveryExceptionClassInPackageSourcesImplementsTheMarkerInterface(): void
    {
        $exceptionClasses = self::exceptionClasses();

        self::assertNotSame([], $exceptionClasses);

        $missing = [];

        foreach ($exceptionClasses as $class => $file) {
            self::assertTrue(class_exists($class), "{$class} must be autoloadable from {$file}");

            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->implementsInterface(Throwable::class), "{$class} in {$file} must be Throwable");

            if (!$reflection->implementsInterface(TmswpExceptionInterface::class)) {
                $missing[] = $class;
            }
        }

        self::assertSame([], $missing, 'exception classes must implement TmswpExceptionInterface');
    }

    public function testEveryFileInExceptionDirectoriesIsChecked(): void
    {
        $checked = array_values(self::exceptionClasses());

        foreach (self::sourceFiles() as $file) {
            if (str_contains($file, '/src/Exception/') && !str_ends_with($file, '/TmswpExceptionInterface.php')) {
                self::assertContains($file, $checked);
            }
        }
    }

    public function testUnknownLogChannelExceptionIsAFinalOutOfBoundsException(): void
    {
        $reflection = new ReflectionClass(UnknownLogChannelException::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isSubclassOf(OutOfBoundsException::class));
        self::assertTrue($reflection->implementsInterface(TmswpExceptionInterface::class));
    }

    /**
     * 例外クラスの宣言を集める。Exception/ 配下のクラスと、名前が Exception・Error で終わるクラスを継承するクラスが対象。
     * 継承関係の判定はクラスを読み込まずに字句だけで行い、WordPress のクラスに依存するソースを読み込まない。
     *
     * @return array<class-string, string> FQCN => ファイルパス
     */
    private static function exceptionClasses(): array
    {
        $classes = [];

        foreach (self::sourceFiles() as $file) {
            foreach (self::declaredClasses($file) as $class => $parent) {
                $inExceptionDirectory = str_contains($file, '/src/Exception/');
                $extendsThrowableName = $parent !== null && preg_match('/(Exception|Error)$/', $parent) === 1;

                if ($inExceptionDirectory || $extendsThrowableName) {
                    /** @var class-string $class */
                    $classes[$class] = $file;
                }
            }
        }

        ksort($classes);

        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $packages = glob(\dirname(__DIR__, 2) . '/packages/*/src', GLOB_ONLYDIR);
        self::assertIsArray($packages);

        $files = [];

        foreach ($packages as $sourceDir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * ファイルで宣言されたクラス(interface・trait・enum・無名クラスを除く)と、その extends の名前を返す。
     *
     * @return array<string, string|null> FQCN => extends に書かれた名前(無ければ null)
     */
    private static function declaredClasses(string $file): array
    {
        $source = file_get_contents($file);
        self::assertIsString($source);

        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn(PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $namespace = '';
        $classes = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = $tokens[$index + 1]->text . '\\';
                continue;
            }

            if (!$token->is(T_CLASS) || ($index > 0 && $tokens[$index - 1]->is([T_NEW, T_DOUBLE_COLON]))) {
                continue;
            }

            $name = $tokens[$index + 1]->text;
            $parent = $tokens[$index + 2]->is(T_EXTENDS) ? $tokens[$index + 3]->text : null;
            $classes[$namespace . $name] = $parent;
        }

        return $classes;
    }
}
