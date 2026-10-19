<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class PHPStanProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0)]
    public const META_PRICE = 'price';

    #[MetaSchema(type: MetaValueType::String)]
    public const META_CODE = 'code';

    #[MetaSchema(type: MetaValueType::Integer, single: false)]
    public const META_RELATED_IDS = 'related_id';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('phpstan_product')->label('PHPStan Product');
    }
}

#[AsPostType]
final class PHPStanOtherPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_OTHER = 'other';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('phpstan_other')->label('PHPStan Other');
    }
}

#[AsOptionGroup(prefix: 'phpstan_')]
enum PHPStanSiteOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: 'info@example.com')]
    case ContactEmail = 'contact_email';

    #[OptionSchema(type: MetaValueType::Boolean, default: false)]
    case Maintenance = 'maintenance';
}
