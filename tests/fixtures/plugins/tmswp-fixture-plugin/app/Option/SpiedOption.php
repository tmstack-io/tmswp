<?php

declare(strict_types=1);

namespace TmswpFixturePlugin\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Tests\Support\RecordedComponent;

/**
 * Initializer の呼び出しの記録用。初期化は RecordingInitializer が記録してから OptionGroupInitializer に委ねる。
 *
 * OptionGroupInitializer が使う OptionRepositoryFactory は列挙型の #[AsOptionGroup] を読むため、
 * #[AsOptionGroup] も付ける。そのため探索ではこの列挙型が2つのコンポーネント(#[AsOptionGroup] と
 * #[RecordedComponent])になり、それぞれの Initializer が呼ばれる(OptionRepositoryFactory::preload() は
 * 同じ列挙型に対して2回目以降は何もしない)。
 */
#[AsOptionGroup(prefix: 'tmswp_fixture_spied_')]
#[RecordedComponent(AsOptionGroup::class)]
enum SpiedOption: string
{
    #[OptionSchema(type: MetaValueType::String, default: 'spied')]
    case Value = 'value';
}
