<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

final class PreviewAcfSupport
{
    public static function register(): void
    {
        add_action('wp_insert_post', [new self(), 'saveRevisionFields'], 10, 1);
    }

    public function saveRevisionFields(int $postId): void
    {
        $parentId = wp_is_post_revision($postId);

        if (!\is_int($parentId) || $parentId < 1) {
            return;
        }

        $acf = $_POST['acf'] ?? null;

        if (!\is_array($acf)) {
            return;
        }

        if (!\function_exists('acf_verify_nonce') || acf_verify_nonce('post') !== true) {
            return;
        }

        if (!current_user_can('edit_post', $parentId)) {
            return;
        }

        if (!\function_exists('acf_update_values')) {
            return;
        }

        $values = wp_unslash($acf);

        if (\is_array($values)) {
            acf_update_values($values, $postId);
        }
    }
}
