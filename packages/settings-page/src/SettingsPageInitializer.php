<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;

final class SettingsPageInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        if (!SettingsPages::isRegistered()) {
            throw new LogicException('SettingsPages::register() must be called immediately after Kernel::boot()');
        }

        if (!is_subclass_of($component->class, SettingsPage::class)) {
            throw new LogicException("settings page must extend SettingsPage: {$component->class}");
        }

        SettingsPages::registerPage($component->class);
        (new PageRegistrar($component->class))->registerMenu();
    }
}
