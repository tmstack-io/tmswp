<?php

declare(strict_types=1);

// テーマのKernelの構成。functions.phpのブートと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。
// WordPressの関数はここで呼ばない(拡張のコンストラクタも同じ)。
// 名前空間(appNamespace)は、create-projectのときにbin/setup.phpがスラッグから決める
return TMSWP\Core\Kernel\KernelConfig::theme(
    appDir: __DIR__ . '/app',
    appNamespace: 'TmswpStarterTheme',
    logChannels: static function (): array {
        return [
            'default' => new TMSWP\Core\Log\LogChannel(new TMSWP\Core\Log\SimpleLogger()),
        ];
    },
    // labelLanguage()を指定しない投稿タイプ・タクソノミーの定義のラベルを、日本語で作る
    labelLanguage: TMSWP\Core\Label\LabelLanguage::Ja,
);
