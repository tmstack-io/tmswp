<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;

/**
 * フックメソッドが WordPress と同じ弱い型変換で呼ばれることの確認用。引数を int で受け取る。
 */
#[AsHook]
final class CoercionProbeHook extends Hook
{
    #[Filter('tmswp_fixture_theme/int_filter')]
    public function addOne(int $value): int
    {
        return $value + 1;
    }
}
