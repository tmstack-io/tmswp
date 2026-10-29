<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit;

/**
 * ja-toolkitのモジュール。JaToolkitのコンストラクタに渡したものだけが有効になる。
 */
interface ModuleInterface
{
    /**
     * WordPressのフックに登録する。JaToolkitを登録したKernelのbootの中で1回呼ばれる。副作用を即時に実行しない。
     */
    public function register(): void;
}
