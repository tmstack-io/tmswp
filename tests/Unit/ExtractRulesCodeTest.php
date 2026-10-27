<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Testing\UnitTestCase;

/**
 * tools/extract-rules-code.php(rules/のコード例の抽出)をCLIとして検証する。
 *
 * スクリプトは自身の親ディレクトリをリポジトリのルートとみなすため、一時ディレクトリのtools/に写して、
 * その一時ディレクトリのpackages/<パッケージ>/rules/から抽出させる。
 */
final class ExtractRulesCodeTest extends UnitTestCase
{
    /**
     * namespace付きのコード例は、クラスの修飾子の組み合わせに関係なく、宣言のクラス名でapp/に書き出す。
     */
    #[DataProvider('classDeclarations')]
    public function testWritesNamespacedExampleUnderItsClassName(string $declaration): void
    {
        $root = $this->createRoot();
        $this->writeFile($root . '/packages/core/rules/form-mail.md', $this->recipe(<<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Form;

            #[Example]
            {$declaration} ContactForm
            {
            }
            PHP));

        $result = $this->runExtract($root);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString("{$declaration} ContactForm", $this->read($root . '/var/rules-code/app/Form/ContactForm.php'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function classDeclarations(): iterable
    {
        yield 'class' => ['class'];
        yield 'final class' => ['final class'];
        yield 'readonly class' => ['readonly class'];
        yield 'final readonly class' => ['final readonly class'];
        yield 'readonly final class' => ['readonly final class'];
        yield 'abstract class' => ['abstract class'];
        yield 'enum' => ['enum'];
    }

    /**
     * 全パッケージのrules/から抽出し、namespaceの無いコード例はtemplates/に「レシピ名-連番」で書き出す。
     */
    public function testExtractsExamplesOfAllPackages(): void
    {
        $root = $this->createRoot();
        $this->writeFile($root . '/packages/core/rules/view.md', $this->recipe("<?php\n\necho 'core';"));
        $this->writeFile($root . '/packages/acf-bridge/rules/sync-check.md', $this->recipe("<?php\n\necho 'acf-bridge';"));

        $result = $this->runExtract($root);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame("<?php\n\necho 'core';\n", $this->read($root . '/var/rules-code/templates/view-1.php'));
        self::assertSame("<?php\n\necho 'acf-bridge';\n", $this->read($root . '/var/rules-code/templates/sync-check-1.php'));
    }

    public function testFailsWhenNamespacedExampleHasNoClassDeclaration(): void
    {
        $root = $this->createRoot();
        $this->writeFile($root . '/packages/core/rules/hooks.md', $this->recipe("<?php\n\nnamespace App\\Hook;\n\nfunction helper(): void\n{\n}"));

        $result = $this->runExtract($root);

        // 例外のメッセージは、CLIのdisplay_errorsの設定によって標準出力か標準エラーのどちらかに出る
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('namespace付きコード例にクラス名がありません', $result['stdout'] . $result['stderr']);
    }

    /** 一時ディレクトリにtools/extract-rules-code.phpを写し、その一時ディレクトリを返す。*/
    private function createRoot(): string
    {
        $root = $this->createTempDirectory('extract-rules-code');
        $this->writeFile($root . '/tools/extract-rules-code.php', $this->read(\dirname(__DIR__, 2) . '/tools/extract-rules-code.php'));

        return $root;
    }

    /** コード例を1つ含むレシピのMarkdownを返す。*/
    private function recipe(string $code): string
    {
        return "# Recipe\n\n```php\n{$code}\n```\n";
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            self::fail("ファイルを読み込めません: {$path}");
        }

        return $contents;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("テストファイルを書き込めません: {$path}");
        }
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runExtract(string $root): array
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([PHP_BINARY, $root . '/tools/extract-rules-code.php'], $descriptors, $pipes, $root);

        if (!\is_resource($process)) {
            self::fail('extract-rules-codeプロセスを起動できません。');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => \is_string($stdout) ? $stdout : '',
            'stderr' => \is_string($stderr) ? $stderr : '',
        ];
    }
}
