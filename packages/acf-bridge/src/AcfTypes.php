<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

/**
 * @phpstan-type AcfImage array{ID: int, id: int, title: string, filename: string, filesize: int, url: string, link: string, alt: string, author: string, description: string, caption: string, name: string, status: string, uploaded_to: int, date: string, modified: string, menu_order: int, mime_type: string, type: string, subtype: string, icon: string, width: int, height: int, sizes: array<string, int|string>}
 * @phpstan-type AcfFile array{ID: int, id: int, title: string, filename: string, filesize: int, url: string, link: string, alt: string, author: string, description: string, caption: string, name: string, status: string, uploaded_to: int, date: string, modified: string, menu_order: int, mime_type: string, type: string, subtype: string, icon: string}
 * @phpstan-type AcfLink array{title: string, url: string, target: string}
 * @phpstan-type AcfChoice array{value: string, label: string}
 * @phpstan-type AcfUserArray array{ID: int, user_firstname: string, user_lastname: string, nickname: string, user_nicename: string, display_name: string, user_email: string, user_url: string, user_registered: string, user_description: string, user_avatar: string}
 * @phpstan-type AcfColorRgba array{red: int, green: int, blue: int, alpha: float}
 */
final class AcfTypes
{
    private function __construct() {}
}
