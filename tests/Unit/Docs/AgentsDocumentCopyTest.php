<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Testing\UnitTestCase;

/**
 * docs/11の全文の写し(monorepoのAGENTS.mdと、テーマのスターターのAGENTS.md)が、実物のファイルと同じ内容である
 * ことを確かめる。写しは、見出しの後の最初の````markdownのフェンスの中身(閉じる````の行の前まで)である。
 * docs/11はmonorepoにだけあるため、monorepoのルートのテストに置く。
 */
final class AgentsDocumentCopyTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function copies(): iterable
    {
        return [
            'monorepo AGENTS.md' => ['## 4. コアmonorepo直下 AGENTS.md(全文案)', 'AGENTS.md'],
            'theme starter AGENTS.md' => ['### 5.1 theme版全文(starters/theme/AGENTS.md)', 'starters/theme/AGENTS.md'],
        ];
    }

    #[DataProvider('copies')]
    public function testDocs11CopyIsTheSameAsTheFile(string $heading, string $file): void
    {
        $root = \dirname(__DIR__, 3);
        $document = self::read($root . '/docs/11-ai-docs.md');
        $pattern = '/^' . preg_quote($heading, '/') . '\n.*?^````markdown\n(.*?)^````$/msu';

        self::assertSame(1, preg_match($pattern, $document, $matches), "docs/11 has no copy of {$file} under \"{$heading}\"");
        self::assertSame(self::read($root . '/' . $file), $matches[1]);
    }

    private static function read(string $file): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source, $file);

        return $source;
    }
}
