<?php

declare(strict_types=1);

namespace TmswpStarterPlugin\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class PluginSetupHook extends Hook
{
    public function register(): void {}
}
