<?php

declare(strict_types=1);

// プラグインのKernelの構成。本体ファイルのブートと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。
// 本体ファイルのパスはこのファイルの位置から決めるため、どちらの経路でも同じ値になる。
// WordPressの関数はここで呼ばない(拡張のコンストラクタも同じ)
return TMSWP\Core\Kernel\KernelConfig::plugin(
    file: __DIR__ . '/{{project_slug}}.php',
    appDir: __DIR__ . '/app',
    appNamespace: 'PluginApp',
    // @PSR12(starter)とPER-CS(monorepo)のどちらでも同じ整形になるよう、アロー関数でなく無名関数で書く
    logChannels: static function (): array {
        return [
            'default' => new TMSWP\Core\Log\LogChannel(new TMSWP\Core\Log\SimpleLogger()),
        ];
    },
);
