<?php

declare(strict_types=1);

namespace TmswpFixturePlugin\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Core\Tests\Support\RecordedComponent;

/**
 * Initializer の呼び出しの記録用。初期化は RecordingInitializer が記録してから ModelInitializer に委ねる
 * (Migrator への登録。テーブルは有効化・管理画面のリクエストで作られる)。
 */
#[RecordedComponent(AsModel::class)]
final class SpiedModel extends Model
{
    public function __construct(
        public int $id,
    ) {}

    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_fixture_spied')->id();
    }
}
