<?php

declare(strict_types=1);

namespace TmswpFixturePlugin\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;
use TMSWP\Core\Tests\Support\RecordedComponent;

/**
 * Initializer の呼び出しの記録用。register() を持つ Hook のコンポーネントで、初期化は RecordingInitializer が
 * 記録してから HookInitializer に委ねる。
 */
#[RecordedComponent(AsHook::class)]
final class SpiedHook extends Hook
{
    public function register(): void
    {
        add_filter('tmswp_fixture_plugin/spied_hook_registered', '__return_true');
    }
}
