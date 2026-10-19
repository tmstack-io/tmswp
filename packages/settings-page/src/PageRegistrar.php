<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormStep;

final class PageRegistrar
{
    private ?SettingsPage $page = null;

    private ?Form $form = null;

    /** @param class-string<SettingsPage> $pageClass */
    public function __construct(private readonly string $pageClass) {}

    public function registerMenu(): void
    {
        $definition = $this->pageClass::definition();

        if ($definition->parent !== null) {
            $hookSuffix = add_submenu_page(
                $definition->parent,
                $definition->pageTitle,
                $definition->menuTitle,
                $definition->capability,
                $definition->slug,
                [$this, 'output'],
                $definition->position,
            );
        } else {
            $hookSuffix = add_menu_page(
                $definition->pageTitle,
                $definition->menuTitle,
                $definition->capability,
                $definition->slug,
                [$this, 'output'],
                $definition->icon ?? '',
                $definition->position,
            );
        }

        if (!\is_string($hookSuffix) || $hookSuffix === '') {
            throw new LogicException("failed to register settings page '{$definition->slug}'");
        }

        add_action("load-{$hookSuffix}", [$this, 'handleRequest'], 10, 0);
    }

    public function handleRequest(): void
    {
        $definition = $this->pageClass::definition();

        if (!current_user_can($definition->capability)) {
            wp_die(__('このページにアクセスする権限がありません。', 'tmswp'));
        }

        $page = $this->page();
        $form = $page->frameworkCreateForm();
        $this->form = $form;

        if (!$form instanceof Form) {
            return;
        }

        $result = $form->handle();

        if ($result->step !== FormStep::Complete) {
            return;
        }

        $page->frameworkSubmit($result);
        wp_safe_redirect($page->url(['updated' => '1']));
        exit;
    }

    public function output(): void
    {
        $definition = $this->pageClass::definition();
        $page = $this->page();

        if (($_GET['updated'] ?? null) === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('設定を保存しました。', 'tmswp') . '</p></div>';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($definition->pageTitle) . '</h1>';
        $page->frameworkRender($this->form);
        echo '</div>';
    }

    private function page(): SettingsPage
    {
        if (!$this->page instanceof SettingsPage) {
            $this->page = new $this->pageClass();
        }

        return $this->page;
    }
}
