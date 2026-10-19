<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Model;

enum ContactStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
