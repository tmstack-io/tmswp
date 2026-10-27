<?php

declare(strict_types=1);

/*
 * Plugin Name: Sample Site mail capture (wp-env only)
 * Description: wp_mail()のメールを送らずに、オプションsample_site_captured_mailsに記録する。
 *
 * examples/sample-site/.wp-env.jsonがwp-content/mu-pluginsにマウントする、開発環境だけの設定。テーマには含めない。
 * wp-envのコンテナにはメールの送信の手段が無く、wp_mail()が失敗するため、フォームの完了処理を確かめられるようにする。
 * 記録したメールは`wp option get sample_site_captured_mails --format=json`で読む。直近の10通だけを残す。
 */

add_filter('pre_wp_mail', static function (?bool $return, array $atts): bool {
    $mails = get_option('sample_site_captured_mails', []);
    $mails = is_array($mails) ? array_slice($mails, -9) : [];
    $mails[] = [
        'to' => $atts['to'] ?? '',
        'subject' => $atts['subject'] ?? '',
        'message' => $atts['message'] ?? '',
        'headers' => $atts['headers'] ?? [],
        'captured_at' => gmdate('c'),
    ];
    update_option('sample_site_captured_mails', $mails, false);

    return true;
}, 10, 2);
