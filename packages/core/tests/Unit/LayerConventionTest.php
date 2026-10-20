<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use TMSWP\Core\Tests\Support\UnitTestCase;

/**
 * エンティティ層(docs/03 §16「静的解析・規約」)とメタ・オプション層(docs/04の受け入れ基準)のソースが、宣言の規約を
 * 守っていることを、ソースのトークンとリフレクションで確かめる。PHPStan level maxの通過はcomposer analyseが確かめる。
 */
final class LayerConventionTest extends UnitTestCase
{
    /** 動的な呼び出し・プロパティに使うマジックメソッド(docs/03 §15、docs/04の設計根拠1) */
    private const array MAGIC_METHODS = ['__call', '__callstatic', '__get', '__set', '__isset', '__unset'];

    /** @return iterable<string, array{list<string>}> */
    public static function layers(): iterable
    {
        yield 'entity' => [['Post', 'Tax', 'User', 'Entity', 'Query']];
        yield 'meta and option' => [['Meta', 'Option']];
    }

    /**
     * すべてのファイルがdeclare(strict_types=1)で始まり、そのファイルのクラスのすべてのメソッドが引数と戻り値の型を
     * 宣言している(コンストラクタの戻り値を除く)。
     *
     * @param list<string> $directories
     */
    #[DataProvider('layers')]
    public function testEveryFileIsStrictAndEverySignatureIsTyped(array $directories): void
    {
        foreach (self::sourceFiles($directories) as $file => $class) {
            $source = self::read($file);
            self::assertMatchesRegularExpression('/\A<\?php\s+declare\(strict_types=1\);/', $source, $file);

            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                $name = "{$class}::{$method->getName()}()";

                foreach ($method->getParameters() as $parameter) {
                    self::assertTrue($parameter->hasType(), "{$name} \${$parameter->getName()} has no type");
                }

                if ($method->getName() !== '__construct') {
                    self::assertTrue($method->hasReturnType(), "{$name} has no return type");
                }
            }
        }
    }

    /**
     * マジックメソッド(__callStatic・__get等)を宣言せず、extract()を呼ばない。
     *
     * @param list<string> $directories
     */
    #[DataProvider('layers')]
    public function testNoMagicMethodIsDeclaredAndExtractIsNotCalled(array $directories): void
    {
        $violations = [];

        foreach (array_keys(self::sourceFiles($directories)) as $file) {
            $tokens = self::significantTokens($file);

            foreach ($tokens as $index => $token) {
                $next = $tokens[$index + 1] ?? null;
                $previous = $tokens[$index - 1] ?? null;

                if ($token->is(T_FUNCTION) && $next !== null && \in_array(strtolower($next->text), self::MAGIC_METHODS, true)) {
                    $violations[] = "{$file}:{$next->line} declares {$next->text}()";
                }

                $calledName = ltrim(strtolower($token->text), '\\');

                if (
                    $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $calledName === 'extract' && $next?->text === '('
                    && !($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION]))
                ) {
                    $violations[] = "{$file}:{$token->line} calls extract()";
                }
            }
        }

        self::assertSame([], $violations);
    }

    /** メタ・オプション層のクラスは、interface・enum・traitを除いてfinalである。 */
    public function testMetaAndOptionClassesAreFinal(): void
    {
        $checked = 0;

        foreach (self::sourceFiles(['Meta', 'Option']) as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isInterface() || $reflection->isEnum() || $reflection->isTrait()) {
                continue;
            }

            self::assertTrue($reflection->isFinal(), "{$class} is not final");
            ++$checked;
        }

        self::assertGreaterThan(5, $checked);
    }

    /**
     * メタ・オプション層は、WordPressのフィルタ(apply_filters())でストレージや値を差し替える経路を持たない。差し替えは
     * #[UseMetaStorage]のクラス参照だけで行う。
     */
    public function testMetaAndOptionLayersHaveNoFilterBasedReplacement(): void
    {
        $violations = [];

        foreach (array_keys(self::sourceFiles(['Meta', 'Option'])) as $file) {
            foreach (self::significantTokens($file) as $token) {
                if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && \in_array(ltrim(strtolower($token->text), '\\'), ['apply_filters', 'apply_filters_ref_array'], true)) {
                    $violations[] = "{$file}:{$token->line} calls {$token->text}()";
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * packages/core/srcの指定したディレクトリのPHPファイルと、そこで宣言するクラス(PSR-4の名前)を返す。
     *
     * @param list<string> $directories
     *
     * @return array<string, class-string>
     */
    private static function sourceFiles(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $paths = glob(\dirname(__DIR__, 2) . "/src/{$directory}/*.php");
            self::assertIsArray($paths);
            self::assertNotSame([], $paths, $directory);

            foreach ($paths as $path) {
                $class = "TMSWP\\Core\\{$directory}\\" . basename($path, '.php');
                self::assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class), "{$path} does not declare {$class}");
                $files[$path] = $class;
            }
        }

        return $files;
    }

    /** @return list<PhpToken> 空白・コメントを除いたトークン */
    private static function significantTokens(string $file): array
    {
        return array_values(array_filter(
            PhpToken::tokenize(self::read($file)),
            static fn(PhpToken $token): bool => !$token->isIgnorable(),
        ));
    }

    private static function read(string $file): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source, $file);

        return $source;
    }
}
