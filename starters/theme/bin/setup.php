<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$slug = basename($root);
$name = ucwords(str_replace(['-', '_'], ' ', $slug));
$projectNameToken = '{' . '{project_name}' . '}';
$projectSlugToken = '{' . '{project_slug}' . '}';

replacePlaceholders($root . '/composer.json', $name, $slug, $projectNameToken, $projectSlugToken);
replacePlaceholders($root . '/README.md', $name, $slug, $projectNameToken, $projectSlugToken);
replacePlaceholders($root . '/style.css', $name, $slug, $projectNameToken, $projectSlugToken);

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
