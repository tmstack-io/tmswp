<?php

declare(strict_types=1);

// プラグインのKernelの構成。本体ファイルのブートと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。
// 本体ファイルのパスはこのファイルの位置から決めるため、どちらの経路でも同じ値になる。
// WordPressの関数はここで呼ばない(拡張のコンストラクタも同じ)。
// 本体ファイルの名前と名前空間(appNamespace)は、create-projectのときにbin/setup.phpがスラッグから決める
return TMSWP\Core\Kernel\KernelConfig::plugin(
    file: __DIR__ . '/plugin.php',
    appDir: __DIR__ . '/app',
    appNamespace: 'TmswpStarterPlugin',
    logChannels: static function (): array {
        return [
            'default' => new TMSWP\Core\Log\LogChannel(new TMSWP\Core\Log\SimpleLogger()),
        ];
    },
    // labelLanguage()を指定しない投稿タイプ・タクソノミーの定義のラベルを、日本語で作る
    labelLanguage: TMSWP\Core\Label\LabelLanguage::Ja,
);
