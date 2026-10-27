<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Tests\Unit;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TMSWP\JaToolkit\Module\DisableTexturize;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;
use TMSWP\JaToolkit\ModuleInterface;
use TMSWP\Testing\UnitTestCase;

/**
 * docs/08の受け入れ基準のうち、ja-toolkitのソースの形で決まる項目(grep検証の項目)を、src/のトークンで確かめる。
 */
final class JaToolkitSourceTest extends UnitTestCase
{
    /** WordPressの関数の有無を確かめて、検査を黙って飛ばす分岐(function_exists)が無い。 */
    public function testDoesNotCheckFunctionExistence(): void
    {
        self::assertSame([], self::findTokens(static fn(PhpToken $token): bool => $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && strtolower(ltrim($token->text, '\\')) === 'function_exists'));
    }

    /** CF7・Yoast・ACFのフック名(`wpcf7_*`・`wpseo_*`・`acf*`)が無い。 */
    public function testHasNoHookNamesOfOtherPlugins(): void
    {
        self::assertSame([], self::findTokens(static fn(PhpToken $token): bool => $token->is(T_CONSTANT_ENCAPSED_STRING) && preg_match('/\A[\'"](wpcf7_|wpseo_|acf)/i', $token->text) === 1));
    }

    /** `$_GET`・`$_POST`・`$_REQUEST`を読み書きしない(書き込みが無いことを、参照が無いことで確かめる)。 */
    public function testDoesNotTouchRequestSuperglobals(): void
    {
        self::assertSame([], self::findTokens(static fn(PhpToken $token): bool => $token->is(T_VARIABLE) && \in_array($token->text, ['$_GET', '$_POST', '$_REQUEST'], true)));
    }

    /** 収載するモジュールは、日本語に固有の3つだけ(汎用のクリーンアップのモジュールが無い)。 */
    public function testShipsOnlyTheThreeJapaneseModules(): void
    {
        $modules = [];
        $src = \dirname(__DIR__, 2) . '/src/';

        // src/のファイルを、PSR-4(TMSWP\JaToolkit\ => src/)のクラス名にしてオートロードする
        foreach (self::sourceFiles() as $file) {
            $class = 'TMSWP\JaToolkit\\' . str_replace('/', '\\', substr($file, \strlen($src), -\strlen('.php')));

            if ((class_exists($class) || interface_exists($class) || enum_exists($class)) && is_subclass_of($class, ModuleInterface::class)) {
                $modules[] = $class;
            }
        }

        sort($modules);

        self::assertSame([DisableTexturize::class, FullwidthSearchNormalizer::class, JapaneseSlug::class], $modules);
    }

    /**
     * src/のトークンのうち、条件に合うもの(ファイル名:行 トークン)。
     *
     * @param callable(PhpToken): bool $matches
     *
     * @return list<string>
     */
    private static function findTokens(callable $matches): array
    {
        $found = [];

        foreach (self::sourceFiles() as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents);

            foreach (PhpToken::tokenize($contents) as $token) {
                if ($matches($token)) {
                    $found[] = basename($file) . ':' . $token->line . ' ' . $token->text;
                }
            }
        }

        return $found;
    }

    /** @return list<string> src/のPHPファイルのパス(昇順) */
    private static function sourceFiles(): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);

            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        self::assertNotSame([], $files);
        sort($files);

        return $files;
    }
}
