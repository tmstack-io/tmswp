<?php

declare(strict_types=1);

namespace App\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;

#[AsOptionGroup(prefix: 'sample_')]
enum SiteOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: 'sample@example.com')]
    case ContactEmail = 'contact_email';

    #[OptionSchema(type: MetaValueType::String, default: '公式パッケージを使ったサンプルサイトです。')]
    case LeadText = 'lead_text';

    public static function contactEmail(): string
    {
        return self::ContactEmail->string();
    }

    public static function leadText(): string
    {
        return self::LeadText->string();
    }
}
