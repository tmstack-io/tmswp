<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Tests\Support\UnitTestCase;

/**
 * 同梱翻訳(packages/<pkg>/languages/)の検査。
 *
 * - .pot は `wp i18n make-pot` がソースから抽出した文字列であり、ja の .po はその全文字列を、過不足なく訳している。
 * - .l10n.php は WordPress の WP_Translation_File_PHP が読む形式(トップレベルのヘッダと messages)で、.po と同じ訳を持つ。
 * - .mo はヘッダ(msgid "")を含み、.po と同じ訳を持つ。
 *
 * 生成物はすべて `composer i18n:build`(WP-CLI)が作る。WordPress が実際に訳を返すことは統合テスト
 * (TranslationIntegrationTest)で確かめる。
 */
final class L10nTest extends UnitTestCase
{
    /** gettext の文脈と msgid を区切る文字(WP_Translation_File と .mo のキー) */
    private const CONTEXT_SEPARATOR = "\4";

    /** 複数形の各形を連結する文字(WP_Translation_File と .mo の値) */
    private const PLURAL_SEPARATOR = "\0";

    /** @return iterable<string, array{string}> */
    public static function packages(): iterable
    {
        yield 'core' => ['core'];
        yield 'ja-toolkit' => ['ja-toolkit'];
        yield 'settings-page' => ['settings-page'];
    }

    #[DataProvider('packages')]
    public function testPotIsGeneratedByWpCli(string $package): void
    {
        $pot = self::readPo(self::languagesDir($package) . "/tmswp-{$package}.pot");

        self::assertStringContainsString('X-Generator: WP-CLI ', $pot['header']);
        self::assertStringContainsString("X-Domain: tmswp\n", $pot['header']);
    }

    #[DataProvider('packages')]
    public function testJapanesePoTranslatesExactlyTheStringsExtractedFromSource(string $package): void
    {
        $dir = self::languagesDir($package);
        $pot = self::readPo("{$dir}/tmswp-{$package}.pot");
        $po = self::readPo("{$dir}/tmswp-ja.po");

        $extracted = array_keys($pot['entries']);
        $translated = array_keys($po['entries']);
        sort($extracted);
        sort($translated);

        self::assertSame(
            [],
            array_values(array_diff($extracted, $translated)),
            "strings extracted from packages/{$package}/src are missing in tmswp-ja.po",
        );
        self::assertSame(
            [],
            array_values(array_diff($translated, $extracted)),
            "tmswp-ja.po has strings that are not extracted from packages/{$package}/src",
        );

        foreach ($po['entries'] as $key => $entry) {
            self::assertSame($pot['entries'][$key]['plural'], $entry['plural'], "plural msgid differs from the POT: {$key}");

            foreach ($entry['translations'] as $translation) {
                self::assertNotSame('', $translation, "untranslated: {$key}");
            }
        }
    }

    #[DataProvider('packages')]
    public function testL10nPhpHasWpTranslationFilePhpStructureAndMatchesPo(string $package): void
    {
        $dir = self::languagesDir($package);
        $po = self::readPo("{$dir}/tmswp-ja.po");
        $data = require "{$dir}/tmswp-ja.l10n.php";

        self::assertIsArray($data);
        self::assertSame('tmswp', $data['domain'] ?? null);
        self::assertSame('ja', $data['language'] ?? null);
        self::assertSame('nplurals=1; plural=0;', $data['plural-forms'] ?? null);
        self::assertArrayHasKey('messages', $data);

        // WP_Translation_File_PHP はトップレベルの messages を「キー => 訳(複数形は \0 連結)」として読む
        $expected = [];

        foreach ($po['entries'] as $key => $entry) {
            $expected[$key] = implode(self::PLURAL_SEPARATOR, $entry['translations']);
        }

        self::assertSame($expected, $data['messages']);
    }

    #[DataProvider('packages')]
    public function testMoHasHeaderAndMatchesPo(string $package): void
    {
        $dir = self::languagesDir($package);
        $po = self::readPo("{$dir}/tmswp-ja.po");
        $mo = self::readMo("{$dir}/tmswp-ja.mo");

        self::assertArrayHasKey('', $mo, '.mo must contain the header entry (msgid "")');
        self::assertStringContainsString("Language: ja\n", $mo['']);
        self::assertStringContainsString("Plural-Forms: nplurals=1; plural=0;\n", $mo['']);
        unset($mo['']);

        // .mo は複数形の原文を「単数 \0 複数」、訳を「各形の \0 連結」で持つ
        $expected = [];

        foreach ($po['entries'] as $key => $entry) {
            $original = $entry['plural'] === null ? $key : $key . self::PLURAL_SEPARATOR . $entry['plural'];
            $expected[$original] = implode(self::PLURAL_SEPARATOR, $entry['translations']);
        }

        ksort($expected, SORT_STRING);
        ksort($mo, SORT_STRING);

        self::assertSame($expected, $mo);
    }

    private static function languagesDir(string $package): string
    {
        return \dirname(__DIR__, 3) . "/{$package}/languages";
    }

    /**
     * .po / .pot を読み、ヘッダと、キー(文脈があれば「文脈 \4 msgid」)ごとの項目を返す。廃止項目(#~)は読まない。
     *
     * @return array{
     *     header: string,
     *     entries: array<string, array{plural: string|null, translations: list<string>}>
     * }
     */
    private static function readPo(string $file): array
    {
        $contents = file_get_contents($file);
        self::assertIsString($contents, "cannot read {$file}");

        $blocks = preg_split('/\n{2,}/', trim($contents));
        self::assertIsArray($blocks);
        $header = null;
        $entries = [];

        foreach ($blocks as $block) {
            $fields = [];
            $last = null;

            foreach (explode("\n", $block) as $line) {
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (preg_match('/\A(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?) "(.*)"\z/', $line, $m) === 1) {
                    $last = $m[1];
                    self::assertArrayNotHasKey($last, $fields, "duplicated {$last} in {$file}");
                    $fields[$last] = stripcslashes($m[2]);

                    continue;
                }

                self::assertSame(1, preg_match('/\A"(.*)"\z/', $line, $m), "unexpected line in {$file}: {$line}");
                self::assertNotNull($last, "continuation line without a keyword in {$file}: {$line}");
                $fields[$last] .= stripcslashes($m[1]);
            }

            if ($fields === []) {
                // 廃止項目(#~)やコメントだけのブロック
                continue;
            }

            self::assertArrayHasKey('msgid', $fields, "entry without msgid in {$file}");

            $translations = [];

            foreach ($fields as $name => $value) {
                if ($name === 'msgstr') {
                    $translations[0] = $value;
                } elseif (preg_match('/\Amsgstr\[(\d+)\]\z/', $name, $m) === 1) {
                    $translations[(int) $m[1]] = $value;
                }
            }

            ksort($translations);
            self::assertSame(array_keys($translations), range(0, \count($translations) - 1), "msgstr indexes are not contiguous in {$file}");

            if ($fields['msgid'] === '' && !\array_key_exists('msgctxt', $fields)) {
                self::assertNull($header, "duplicated header in {$file}");
                $header = $translations[0] ?? '';

                continue;
            }

            $key = \array_key_exists('msgctxt', $fields)
                ? $fields['msgctxt'] . self::CONTEXT_SEPARATOR . $fields['msgid']
                : $fields['msgid'];
            self::assertArrayNotHasKey($key, $entries, "duplicated msgid in {$file}: {$key}");

            self::assertNotSame([], $translations, "entry without msgstr in {$file}: {$key}");

            // .pot の msgstr は空。.po の訳の有無は呼び出し側が検査する
            $entries[$key] = [
                'plural' => $fields['msgid_plural'] ?? null,
                'translations' => array_values($translations),
            ];
        }

        self::assertNotNull($header, "no header entry in {$file}");

        return ['header' => $header, 'entries' => $entries];
    }

    /**
     * .mo を読み、原文 => 訳 の対応を返す(GNU gettext の MO 形式。リトルエンディアン)。
     *
     * @return array<string, string>
     */
    private static function readMo(string $file): array
    {
        $data = file_get_contents($file);
        self::assertIsString($data, "cannot read {$file}");

        $word = static function (int $offset) use ($data, $file): int {
            $unpacked = unpack('V', $data, $offset);
            self::assertIsArray($unpacked, "truncated MO file: {$file}");
            self::assertIsInt($unpacked[1]);

            return $unpacked[1];
        };
        $string = static function (int $tableOffset, int $index) use ($data, $word): string {
            $length = $word($tableOffset + $index * 8);
            $offset = $word($tableOffset + $index * 8 + 4);

            return substr($data, $offset, $length);
        };

        self::assertSame(0x950412DE, $word(0), "not a little-endian MO file: {$file}");
        self::assertSame(0, $word(4), "unsupported MO revision: {$file}");

        $count = $word(8);
        $originals = $word(12);
        $translations = $word(16);
        $result = [];

        for ($i = 0; $i < $count; ++$i) {
            $result[$string($originals, $i)] = $string($translations, $i);
        }

        return $result;
    }
}
