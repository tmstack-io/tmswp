<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\AddTagToChangelogReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushNextDevReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushTagReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetCurrentMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetNextMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\TagVersionReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\UpdateBranchAliasReleaseWorker;

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

    // `monorepo-builder release vX.Y.Z`が順に実行するワーカー。mainとタグのpushをこのコマンドが自ら行う(docs/RELEASING.md)
    $mbConfig->workers([
        SetCurrentMutualDependenciesReleaseWorker::class, // packages/*・starters/*のtmswp/*の制約を^X.Y(PATCHが0より大きければ^X.Y.Z)にする
        AddTagToChangelogReleaseWorker::class,            // CHANGELOG.mdの「## Unreleased」を「## X.Y.Z - <日付>」にする
        TagVersionReleaseWorker::class,                   // git add .・git commit・git push origin mainの後、git tag vX.Y.Z
        PushTagReleaseWorker::class,                      // git push --tags(split.ymlがタグをsplit先へ複製する)
        SetNextMutualDependenciesReleaseWorker::class,    // tmswp/*の制約を^X.(Y+1)にする
        UpdateBranchAliasReleaseWorker::class,            // branch-aliasを既に持つパッケージ(packages/*)だけ、X.(Y+1).x-devにする
        PushNextDevReleaseWorker::class,                  // git add .・git commit -m "open X.(Y+1).x-dev"・git push origin main
    ]);
};
