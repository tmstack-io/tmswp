<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Exception\Form\FormConfigException;
use wpdb;

final class JtiStore
{
    public function claim(string $jti, int $ttl): bool
    {
        $wpdb = $this->wpdb();

        $name = 'tmswp_form_jti_' . $jti;
        $expires = (string) (time() + $ttl);
        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $wpdb->options,
            $name,
            $expires,
        );
        if (!\is_string($sql)) {
            throw new FormConfigException('failed to prepare JTI claim query');
        }

        $wpdb->query($sql);
        $claimed = $wpdb->rows_affected === 1;

        if ($claimed && mt_rand(1, 100) === 1) {
            $this->gc();
        }

        return $claimed;
    }

    public function gc(): int
    {
        $wpdb = $this->wpdb();

        $like = $wpdb->esc_like('tmswp_form_jti_') . '%';
        $sql = $wpdb->prepare(
            'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
            $wpdb->options,
            $like,
            time(),
        );
        if (!\is_string($sql)) {
            throw new FormConfigException('failed to prepare JTI cleanup query');
        }

        $result = $wpdb->query($sql);

        return \is_int($result) ? $result : 0;
    }

    private function wpdb(): wpdb
    {
        global $wpdb;

        if (!class_exists(wpdb::class) || !$wpdb instanceof wpdb) {
            throw new FormConfigException('wpdb is not available');
        }

        return $wpdb;
    }
}
