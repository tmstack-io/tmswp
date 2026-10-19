<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Mapping\LocationResolver;
use TMSWP\AcfBridge\Mapping\TargetKind;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class LocationResolverTest extends UnitTestCase
{
    public function testItResolvesSupportedLocationTargets(): void
    {
        $resolver = new LocationResolver();

        self::assertSame(
            [[TargetKind::Post, 'product', 'ProductFields']],
            $this->targetRows($resolver->resolve($this->group('post_type', 'product'), $this->config())),
        );
        self::assertSame(
            [[TargetKind::Post, 'page', 'PageFields']],
            $this->targetRows($resolver->resolve($this->group('page_template', 'page-about.php'), $this->config())),
        );
        self::assertSame(
            [[TargetKind::Term, 'genre', 'GenreTermFields']],
            $this->targetRows($resolver->resolve($this->group('taxonomy', 'genre'), $this->config())),
        );
        self::assertSame(
            [[TargetKind::Term, 'all', 'TermFields']],
            $this->targetRows($resolver->resolve($this->group('taxonomy', 'all'), $this->config())),
        );
        self::assertSame(
            [[TargetKind::User, '', 'UserFields']],
            $this->targetRows($resolver->resolve($this->group('user_role', 'editor'), $this->config())),
        );
        self::assertSame(
            [[TargetKind::Options, 'theme-settings', 'ThemeSettingsOptions']],
            $this->targetRows($resolver->resolve($this->group('options_page', 'theme-settings'), $this->config())),
        );
    }

    public function testItResolvesMultipleOrGroupsAsMultipleTargets(): void
    {
        $group = FieldGroup::fromArray([
            'key' => 'group_shared',
            'title' => '共有',
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'product']],
                [['param' => 'post_type', 'operator' => '==', 'value' => 'news']],
            ],
            'fields' => [],
        ], 'group_shared.json');

        self::assertSame(
            [
                [TargetKind::Post, 'news', 'NewsFields'],
                [TargetKind::Post, 'product', 'ProductFields'],
            ],
            $this->targetRows((new LocationResolver())->resolve($group, $this->config())),
        );
    }

    public function testClassMapOverridesSingleTargetGroupClassName(): void
    {
        $config = new BridgeConfig([], '/tmp/acf', 'App\\Acf', classMap: ['group_test' => 'CustomProductFields']);

        self::assertSame(
            [[TargetKind::Post, 'product', 'CustomProductFields']],
            $this->targetRows((new LocationResolver())->resolve($this->group('post_type', 'product'), $config)),
        );
    }

    public function testItWarnsWhenMultipleTargetKindsRequirePriority(): void
    {
        $warnings = [];
        $group = FieldGroup::fromArray([
            'key' => 'group_test',
            'title' => 'テスト',
            'location' => [[
                ['param' => 'post_type', 'operator' => '==', 'value' => 'product'],
                ['param' => 'taxonomy', 'operator' => '==', 'value' => 'genre'],
            ]],
            'fields' => [],
        ], 'group_test.json');

        self::assertSame(
            [[TargetKind::Post, 'product', 'ProductFields']],
            $this->targetRows((new LocationResolver())->resolveWithWarnings($group, $this->config(), $warnings)),
        );
        self::assertSame(["W004: ACF field group 'group_test' has multiple target location kinds; priority was applied"], $warnings);
    }

    public function testItWarnsForUnresolvedPostIdLocation(): void
    {
        $warnings = [];

        self::assertSame(
            [],
            (new LocationResolver())->resolveWithWarnings($this->group('post', '10'), $this->config(), $warnings),
        );
        self::assertSame(["W003: ACF field group 'group_test' uses a post ID location that cannot be resolved statically"], $warnings);
    }

    public function testItWarnsForUnsupportedLocationParams(): void
    {
        $warnings = [];

        self::assertSame(
            [],
            (new LocationResolver())->resolveWithWarnings($this->group('block', 'acf/hero'), $this->config(), $warnings),
        );
        self::assertSame(["W006: ACF field group 'group_test' uses unsupported location param 'block'"], $warnings);
    }

    public function testItWarnsForUnsatisfiableDuplicateLocationParams(): void
    {
        $warnings = [];
        $group = FieldGroup::fromArray([
            'key' => 'group_test',
            'title' => 'テスト',
            'location' => [[
                ['param' => 'post_type', 'operator' => '==', 'value' => 'product'],
                ['param' => 'post_type', 'operator' => '==', 'value' => 'news'],
            ]],
            'fields' => [],
        ], 'group_test.json');

        self::assertSame([], (new LocationResolver())->resolveWithWarnings($group, $this->config(), $warnings));
        self::assertSame(["W007: ACF field group 'group_test' has an unsatisfiable location condition for 'post_type'"], $warnings);
    }

    private function config(): BridgeConfig
    {
        return new BridgeConfig([], '/tmp/acf', 'App\\Acf');
    }

    private function group(string $param, string $value): FieldGroup
    {
        return FieldGroup::fromArray([
            'key' => 'group_test',
            'title' => 'テスト',
            'location' => [[['param' => $param, 'operator' => '==', 'value' => $value]]],
            'fields' => [],
        ], 'group_test.json');
    }

    /**
     * @param list<\TMSWP\AcfBridge\Mapping\GenerationTarget> $targets
     *
     * @return list<array{TargetKind, string, string}>
     */
    private function targetRows(array $targets): array
    {
        $rows = [];

        foreach ($targets as $target) {
            $rows[] = [$target->kind, $target->subtype, $target->className];
        }

        return $rows;
    }
}
