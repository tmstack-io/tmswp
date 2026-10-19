<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Tests\Support\UnitTestCase;

final class L10nTest extends UnitTestCase
{
    public function testCoreJapaneseTranslationsCoverFormMessages(): void
    {
        $messages = $this->messages(\dirname(__DIR__, 2) . '/languages/tmswp-ja.l10n.php');

        foreach ([
            ', ',
            'Please complete all parts of {label}.',
            '{label} is invalid.',
            'Your session has expired. Please submit the form again.',
            'The form could not be resubmitted. Please start again.',
            'The confirmation screen has expired. Please review your entries and continue again.',
            'This form has already been submitted.',
            'The uploaded file is too large.',
            'The file was only partially uploaded.',
            'Please select a file.',
            'The temporary upload folder is missing.',
            'The file could not be written.',
            'A PHP extension stopped the file upload.',
            'The file upload failed.',
            '{label} is required.',
            'Please enter a valid email address for {label}.',
            '{label} must be {length} characters or fewer.',
            '{label} must be at least {length} characters.',
            '{label} has an invalid format.',
            '{label} must be between {min} and {max}.',
            '{label} has an invalid choice.',
            '{label} must match {otherLabel}.',
            'Please upload {label} as one of these file types: {extensions}.',
            '{label} must be {size} or smaller.',
            'Please enter a valid URL for {label}.',
        ] as $msgid) {
            self::assertArrayHasKey($msgid, $messages);
            $translation = $messages[$msgid];
            self::assertIsArray($translation);
            self::assertNotSame('', $translation[0] ?? '');
        }
    }

    public function testJaToolkitJapaneseTranslationsCoverKanaMessages(): void
    {
        $messages = $this->messages(\dirname(__DIR__, 3) . '/ja-toolkit/languages/tmswp-ja.l10n.php');

        foreach ([
            'Please enter {label} in katakana.',
            'Please enter {label} in hiragana.',
        ] as $msgid) {
            self::assertArrayHasKey($msgid, $messages);
            $translation = $messages[$msgid];
            self::assertIsArray($translation);
            self::assertNotSame('', $translation[0] ?? '');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function messages(string $file): array
    {
        $data = require $file;

        self::assertIsArray($data);
        self::assertIsArray($data['locale_data'] ?? null);
        self::assertIsArray($data['locale_data']['messages'] ?? null);

        $messages = $data['locale_data']['messages'];
        $result = [];

        foreach ($messages as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }
}
