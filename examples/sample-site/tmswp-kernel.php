<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Block\BlockCategory;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Log\SimpleLogger;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\JapaneseSlug;
use TMSWP\SettingsPage\SettingsPages;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;

// テーマのKernelの構成。functions.phpのブートと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。
// WordPressの関数はここで呼ばない(拡張のコンストラクタも同じ)
return KernelConfig::theme(
    appDir: __DIR__ . '/app',
    appNamespace: 'SampleSite',
    // 使う拡張だけを渡す。拡張はこのKernelでだけ有効になる(vendor/tmswp/core/rules/extensions.md)
    extensions: [
        // acf-json/から生成したapp/Acf/のアクセサと、AcfMetaStorage
        new AcfBridge(),
        // app/SettingsPage/の#[AsSettingsPage]のページ
        new SettingsPages(),
        // tms_thumb()の署名付きのサムネイルのURL
        new Thumbnail(new ThumbnailConfig(defaultQuality: 80)),
        // 日本語のタイトルから作られたスラッグを、{post_type}-{post_id}にする。KanaTextFieldの訳もこの登録で読み込む
        new JaToolkit(new JapaneseSlug()),
    ],
    logChannels: static function (): array {
        return [
            'default' => new LogChannel(new SimpleLogger()),
        ];
    },
    // labelLanguage()を指定しない投稿タイプ・タクソノミーの定義のラベルを、日本語で作る
    labelLanguage: LabelLanguage::Ja,
    // blocks/のブロックのカテゴリ。見出しはWordPressなしでも読まれるため、__()を使わない
    blockCategories: [
        new BlockCategory('sample-site', 'Sample Site', 'store'),
    ],
);
