<?php

declare(strict_types=1);

/*
 * 統合テスト用: 入れ子のディレクトリ(build/blocks/layout/notice/)に置いた動的描画のブロック。
 *
 * @var array<string, mixed> $attributes
 */
$message = is_string($attributes['message'] ?? null) ? $attributes['message'] : '';

printf('<p class="tmswp-fixture-notice">%s</p>', esc_html($message));
