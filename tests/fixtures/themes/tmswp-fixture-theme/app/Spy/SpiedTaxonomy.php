<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Spy;

use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;
use TMSWP\Core\Tests\Support\RecordedComponent;

/**
 * Initializer の呼び出しの記録用。初期化は RecordingInitializer が記録してから TaxonomyInitializer に委ねる。
 */
#[RecordedComponent(AsTaxonomy::class)]
final class SpiedTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('tmswp_spied_tax')
            ->label('Spied', 'Spied')
            ->objectType(SpiedPost::class);
    }
}
