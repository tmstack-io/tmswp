<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;
use TMSWP\Tools\Release\CheckReleaseReadinessReleaseWorker;
use TMSWP\Tools\Release\CreateAnnotatedTagReleaseWorker;

return static function (MBConfig $mbConfig): void {
    $mbConfig->packageDirectories([
        __DIR__ . '/packages',
        __DIR__ . '/starters',
    ]);

    $mbConfig->defaultBranch('main');
    $mbConfig->packageAliasFormat('<major>.<minor>.x-dev');

    $mbConfig->dataToAppend([
        'require-dev' => [],
        'config' => [
            'sort-packages' => true,
        ],
    ]);

    // `monorepo-builder release vX.Y.Z`が順に実行するワーカーは、tools/release/の2つだけにする(docs/RELEASING.md)。
    // monorepo-builderが既定で登録するワーカーを外し、版の書き換え・mainへのcommitとpush・次の版への引き上げを行わない
    MBConfig::disableDefaultWorkers();

    $services = $mbConfig->services();
    // mainであること・origin/mainと同じであること・作業ツリーが空であること・タグが無いこと・版の一致・CHANGELOGの見出しを検査する
    $services->set(CheckReleaseReadinessReleaseWorker::class)->arg('$repositoryDirectory', __DIR__);
    // 注釈付きのタグvX.Y.Zを作り、git push origin refs/tags/vX.Y.Zでそのタグだけを送る(split.ymlがタグを同期先へ複製する)
    $services->set(CreateAnnotatedTagReleaseWorker::class)->arg('$repositoryDirectory', __DIR__);
};
