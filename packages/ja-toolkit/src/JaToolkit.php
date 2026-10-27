<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit;

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;

/**
 * ja-toolkitの入口。`KernelConfig::theme()` / `plugin()`の`extensions`に渡し、Kernelごとに登録する。
 *
 * ```php
 * return KernelConfig::theme(__DIR__ . '/app', 'App', extensions: [
 *     new JaToolkit(new JapaneseSlug(), new FullwidthSearchNormalizer(), new DisableTexturize()),
 * ]);
 * ```
 *
 * 登録したKernelのbootで、ja訳の読み込み(init 1)を予約し、コンストラクタに渡したモジュールだけを有効にする。
 * KanaTextFieldの訳も、この登録で読み込む(モジュールを1つも渡さない`new JaToolkit()`でよい)。1つのインスタンスは
 * 1つのKernelにだけ登録できる。
 */
final class JaToolkit implements ExtensionInterface
{
    private ?Kernel $kernel = null;

    /** @var list<ModuleInterface> */
    private readonly array $modules;

    /**
     * WordPressの関数は呼ばない(tmswp-kernel.phpの中で、WordPressを起動しない経路からも生成されるため)。
     *
     * @param ModuleInterface ...$modules 有効にするモジュール。同じクラスは1つまで
     */
    public function __construct(ModuleInterface ...$modules)
    {
        $this->modules = array_values($modules);
    }

    /**
     * 登録したKernelのboot中に1回呼ばれる。検査をすべて済ませてから、訳の読み込み(init 1)の予約と、各モジュールの
     * register()を行う。検査で例外になったときは、何も登録しない。
     *
     * @throws LogicException このインスタンスを登録済みの場合、同じクラスのモジュールが2つ以上ある場合
     */
    public function register(Kernel $kernel): void
    {
        if ($this->kernel instanceof Kernel) {
            throw new LogicException("this JaToolkit instance is already registered to the kernel '{$this->kernel->config()->appNamespace}'; create one JaToolkit per kernel");
        }

        $classes = [];

        foreach ($this->modules as $module) {
            $class = $module::class;

            if (\array_key_exists($class, $classes)) {
                throw new LogicException("ja-toolkit module {$class} is passed more than once to the JaToolkit of the kernel '{$kernel->config()->appNamespace}'");
            }

            $classes[$class] = true;
        }

        $this->kernel = $kernel;
        $kernel->defer('init', 1, static function (): void {
            load_textdomain('tmswp', \dirname(__DIR__) . '/languages/tmswp-' . determine_locale() . '.mo');
        });

        foreach ($this->modules as $module) {
            $module->register();
        }
    }
}
