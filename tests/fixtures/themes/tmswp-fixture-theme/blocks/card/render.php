<?php

declare(strict_types=1);

/*
 * 統合テスト用: 動的描画のブロック。WordPressがblock.jsonのrenderから読み込み、$attributesを渡す。
 *
 * @var array<string, mixed> $attributes
 */
$title = is_string($attributes['title'] ?? null) ? $attributes['title'] : '';

printf('<div %s>%s</div>', get_block_wrapper_attributes(), esc_html($title));
