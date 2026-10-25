<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$slug = basename($root);
$name = ucwords(str_replace(['-', '_'], ' ', $slug));
$projectNameToken = '{' . '{project_name}' . '}';
$projectSlugToken = '{' . '{project_slug}' . '}';

replacePlaceholders($root . '/AGENTS.md', $name, $slug, $projectNameToken, $projectSlugToken);
replacePlaceholders($root . '/composer.json', $name, $slug, $projectNameToken, $projectSlugToken);
replacePlaceholders($root . '/README.md', $name, $slug, $projectNameToken, $projectSlugToken);
replacePlaceholders($root . '/plugin.php', $name, $slug, $projectNameToken, $projectSlugToken);
replacePlaceholders($root . '/tmswp-kernel.php', $name, $slug, $projectNameToken, $projectSlugToken);
renamePluginFile($root, $slug);

function replacePlaceholders(string $file, string $name, string $slug, string $projectNameToken, string $projectSlugToken): void
{
    $contents = file_get_contents($file);

    if (!is_string($contents)) {
        throw new RuntimeException("ファイルを読み込めません: {$file}");
    }

    $updated = str_replace(
        [$projectNameToken, $projectSlugToken],
        [$name, $slug],
        $contents,
    );

    if (file_put_contents($file, $updated) === false) {
        throw new RuntimeException("ファイルを書き込めません: {$file}");
    }
}

function renamePluginFile(string $root, string $slug): void
{
    $old = $root . '/plugin.php';
    $new = $root . '/' . $slug . '.php';

    if ($old === $new) {
        if (!is_file($old)) {
            throw new RuntimeException("プラグインファイルがありません: {$old}");
        }

        return;
    }

    if (!is_file($old)) {
        throw new RuntimeException("リネーム元のプラグインファイルがありません: {$old}");
    }

    if (is_file($new)) {
        throw new RuntimeException("リネーム先のプラグインファイルが既に存在します: {$new}");
    }

    if (!rename($old, $new)) {
        throw new RuntimeException("プラグインファイルをリネームできません: {$old} -> {$new}");
    }
}
