<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use TMSWP\AcfBridge\Json\AcfJsonRepository;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class AcfJsonRepositoryTest extends UnitTestCase
{
    public function testItReadsGroupsAndOptionsPagesFromLocalJson(): void
    {
        $dir = $this->createTempDirectory('acf-json');
        $this->writeJson($dir . '/group_product.json', [
            'key' => 'group_product',
            'title' => '製品情報',
            'active' => true,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
            'fields' => [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
            ],
        ]);
        $this->writeJson($dir . '/options.json', [
            'key' => 'ui_options_page_theme',
            'menu_slug' => 'theme-settings',
            'post_id' => 'theme_settings',
        ]);

        $repository = new AcfJsonRepository([$dir]);

        self::assertSame(['theme-settings' => 'theme_settings'], $repository->optionsPagePostIds());
        self::assertCount(1, $repository->fieldGroups());
        self::assertSame('group_product', $repository->fieldGroups()[0]->key);
    }

    public function testSourceHashIgnoresModifiedTimestamps(): void
    {
        $dir = $this->createTempDirectory('acf-json-hash');
        $file = $dir . '/group_product.json';
        $group = [
            'key' => 'group_product',
            'title' => '製品情報',
            'modified' => 1,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
            'fields' => [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number', 'modified' => 1],
            ],
        ];
        $this->writeJson($file, $group);
        $hash = (new AcfJsonRepository([$dir]))->sourceHash();
        $group['modified'] = 2;
        $group['fields'][0]['modified'] = 2;
        $this->writeJson($file, $group);

        self::assertSame($hash, (new AcfJsonRepository([$dir]))->sourceHash());
    }

    public function testItReportsUnsupportedAndDuplicateJsonWarnings(): void
    {
        $first = $this->createTempDirectory('acf-json-first');
        $second = $this->createTempDirectory('acf-json-second');
        $group = [
            'key' => 'group_product',
            'title' => '製品情報',
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
            'fields' => [],
        ];
        $this->writeJson($first . '/group_product.json', $group);
        $this->writeJson($second . '/group_product.json', $group);
        $this->writeJson($first . '/post_type_product.json', ['key' => 'post_type_product']);

        $warnings = (new AcfJsonRepository([$first, $second]))->warnings();

        self::assertCount(2, $warnings);
        self::assertStringStartsWith('W001: unsupported ACF JSON', $warnings[0]);
        self::assertStringStartsWith("W010: duplicate ACF field group 'group_product'", $warnings[1]);
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($path, $json) === false) {
            self::fail("fixture JSON を書き込めません: {$path}");
        }
    }
}
