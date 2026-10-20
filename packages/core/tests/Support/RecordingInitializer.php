<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use LogicException;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;
use WP_Hook;

/**
 * RecordedComponent を付けたコンポーネントの初期化を記録し、包んだ属性の Initializer に委ねるスパイ。
 *
 * Kernel は Initializer を Container から取り出して呼ぶため、記録は Container に依らない静的なプロパティに残す。
 * fixture のコンポーネントは WordPress の読み込み(1リクエスト)の中で初期化されるため、統合テストはその記録を読む。
 */
final class RecordingInitializer implements ComponentInitializerInterface
{
    /** @var list<array{kernel: string, component: string, hook: string|false, priority: int|false}> */
    public static array $calls = [];

    /**
     * @throws LogicException コンポーネントの属性が RecordedComponent でない場合
     */
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        $attribute = $component->attribute();

        if (!$attribute instanceof RecordedComponent) {
            throw new LogicException("component {$component->class} is not marked with " . RecordedComponent::class);
        }

        // 初期化を実行しているフックと、その priority(WP_Hook が実行中のもの)を記録する
        $hook = current_filter();
        $filters = $GLOBALS['wp_filter'] ?? null;
        $wpHook = $hook !== false && \is_array($filters) ? ($filters[$hook] ?? null) : null;
        self::$calls[] = [
            'kernel' => $kernel->config()->appNamespace,
            'component' => $component->class,
            'hook' => $hook,
            'priority' => $wpHook instanceof WP_Hook ? $wpHook->current_priority() : false,
        ];

        $kernel->container()->get($attribute->inner()->initializerClass())->initialize($component, $kernel);
    }
}
