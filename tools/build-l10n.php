<?php

declare(strict_types=1);

if ($argc < 2) {
    throw new RuntimeException('usage: php tools/build-l10n.php <po-file> [<po-file> ...]');
}

for ($i = 1; $i < $argc; ++$i) {
    build($argv[$i]);
}

function build(string $poFile): void
{
    $entries = parsePo($poFile);
    $base = preg_replace('/\.po\z/', '', $poFile);

    if (!is_string($base)) {
        throw new RuntimeException("PO file must end with .po: {$poFile}");
    }

    writeMo($base . '.mo', $entries);
    writeL10nPhp($base . '.l10n.php', $entries);
}

/**
 * @return array<string, string>
 */
function parsePo(string $poFile): array
{
    $contents = file_get_contents($poFile);

    if (!is_string($contents)) {
        throw new RuntimeException("cannot read PO file: {$poFile}");
    }

    $entries = [];
    $current = null;
    $msgid = null;
    $msgstr = null;

    $lines = preg_split("/\r\n|\n|\r/", $contents);

    if ($lines === false) {
        throw new RuntimeException("cannot split PO file into lines: {$poFile}");
    }

    foreach ($lines as $line) {
        if ($line === '') {
            if ($msgid !== null && $msgid !== '') {
                $entries[$msgid] = $msgstr ?? '';
            }

            $current = null;
            $msgid = null;
            $msgstr = null;
            continue;
        }

        if (str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'msgid "')) {
            $current = 'msgid';
            $msgid = poUnescape(poQuotedValue($line, 'msgid'));
            continue;
        }

        if (str_starts_with($line, 'msgstr "')) {
            $current = 'msgstr';
            $msgstr = poUnescape(poQuotedValue($line, 'msgstr'));
            continue;
        }

        if (preg_match('/^"(.*)"$/', $line, $matches) === 1) {
            if ($current === 'msgid') {
                $msgid .= poUnescape($matches[1]);
            } elseif ($current === 'msgstr') {
                $msgstr .= poUnescape($matches[1]);
            }
        }
    }

    if ($msgid !== null && $msgid !== '') {
        $entries[$msgid] = $msgstr ?? '';
    }

    ksort($entries, SORT_STRING);

    return $entries;
}

function poUnescape(string $value): string
{
    return strtr($value, [
        '\\n' => "\n",
        '\\"' => '"',
        '\\\\' => '\\',
    ]);
}

function poQuotedValue(string $line, string $keyword): string
{
    $prefix = $keyword . ' "';

    if (!str_starts_with($line, $prefix)) {
        throw new RuntimeException("invalid PO line: {$line}");
    }

    return substr($line, strlen($prefix), -1);
}

/**
 * @param array<string, string> $entries
 */
function writeMo(string $target, array $entries): void
{
    $ids = array_keys($entries);
    $strings = array_values($entries);
    $count = count($entries);
    $offsetOriginals = 28;
    $offsetTranslations = $offsetOriginals + $count * 8;
    $offsetStrings = $offsetTranslations + $count * 8;
    $originalTable = '';
    $translationTable = '';
    $stringData = '';

    foreach ($ids as $id) {
        $originalTable .= pack('VV', strlen($id), $offsetStrings + strlen($stringData));
        $stringData .= $id . "\0";
    }

    foreach ($strings as $string) {
        $translationTable .= pack('VV', strlen($string), $offsetStrings + strlen($stringData));
        $stringData .= $string . "\0";
    }

    $mo = pack('V*', 0x950412de, 0, $count, $offsetOriginals, $offsetTranslations, 0, 0)
        . $originalTable
        . $translationTable
        . $stringData;

    if (file_put_contents($target, $mo) === false) {
        throw new RuntimeException("cannot write MO file: {$target}");
    }
}

/**
 * @param array<string, string> $entries
 */
function writeL10nPhp(string $target, array $entries): void
{
    $messages = [
        '' => [
            'domain' => 'messages',
            'plural-forms' => 'nplurals=1; plural=0;',
            'lang' => 'ja',
        ],
    ];

    foreach ($entries as $id => $translation) {
        $messages[$id] = [$translation];
    }

    $php = "<?php\n\nreturn " . var_export([
        'domain' => 'messages',
        'locale_data' => ['messages' => $messages],
    ], true) . ";\n";

    if (file_put_contents($target, $php) === false) {
        throw new RuntimeException("cannot write l10n.php file: {$target}");
    }
}
