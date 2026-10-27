<?php

declare(strict_types=1);

namespace SampleSite\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;

/**
 * サイトの設定値。管理画面の「サイト設定」(SiteSettingsPage)で編集する。
 * オプション名には接頭辞が付く(例: sample_site_contact_email)。
 */
#[AsOptionGroup(prefix: 'sample_site_')]
enum SiteOption: string
{
    use OptionAccess;

    /** お問い合わせの通知の宛先。空ならサイトの管理者のメールアドレスに送る */
    #[OptionSchema(type: MetaValueType::String, default: '')]
    case ContactEmail = 'contact_email';

    /** トップページの見出しの下に出す文 */
    #[OptionSchema(type: MetaValueType::String, default: '')]
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
