<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Module;

use TMSWP\JaToolkit\ModuleInterface;

final class DisableTexturize implements ModuleInterface
{
    public function register(): void
    {
        add_filter('run_wptexturize', '__return_false');
    }
}
