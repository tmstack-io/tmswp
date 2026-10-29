<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;

/**
 * メソッド属性によるフック登録の確認用。生成と呼び出しを観測用アクションの発火回数で記録する。
 */
#[AsHook]
final class ThemeProbeHook extends Hook
{
    public function __construct()
    {
        do_action('tmswp_fixture_theme/probe_constructed');
    }

    #[Action('tmswp_fixture_theme/action')]
    public function handle(): void
    {
        do_action('tmswp_fixture_theme/probe_handled');
    }

    #[Filter('tmswp_fixture_theme/filter')]
    public function filter(string $value): string
    {
        return $value . '-filtered';
    }
}
