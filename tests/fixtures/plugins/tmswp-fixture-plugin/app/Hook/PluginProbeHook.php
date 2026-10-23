<?php

declare(strict_types=1);

namespace TmswpFixturePlugin\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;

/**
 * メソッド属性によるフック登録の確認用。生成と呼び出しを観測用アクションの発火回数で記録する。
 */
#[AsHook]
final class PluginProbeHook extends Hook
{
    public function __construct()
    {
        do_action('tmswp_fixture_plugin/probe_constructed');
    }

    #[Action('tmswp_fixture_plugin/action')]
    public function handle(): void
    {
        do_action('tmswp_fixture_plugin/probe_handled');
    }

    #[Filter('tmswp_fixture_plugin/filter')]
    public function filter(string $value): string
    {
        return $value . '-filtered';
    }
}
