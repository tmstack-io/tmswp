<?php

declare(strict_types=1);

// テーマのKernelの構成。functions.phpのブートと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。
// WordPressの関数はここで呼ばない(拡張のコンストラクタも同じ)
return TMSWP\Core\Kernel\KernelConfig::theme(
    appDir: __DIR__ . '/app',
    appNamespace: 'App',
    // @PSR12(starter)とPER-CS(monorepo)のどちらでも同じ整形になるよう、アロー関数でなく無名関数で書く
    logChannels: static function (): array {
        return [
            'default' => new TMSWP\Core\Log\LogChannel(new TMSWP\Core\Log\SimpleLogger()),
        ];
    },
);
