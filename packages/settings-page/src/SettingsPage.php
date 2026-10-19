<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormResult;

abstract class SettingsPage
{
    abstract public static function definition(): SettingsPageDefinition;

    protected function createForm(): ?Form
    {
        return null;
    }

    protected function onSubmit(FormResult $result): void {}

    abstract protected function render(?Form $form): void;

    /** @param array<string, scalar|null> $queryArgs */
    final public function url(array $queryArgs = []): string
    {
        if (!\function_exists('menu_page_url')) {
            throw new LogicException('menu_page_url() is not available');
        }

        $url = menu_page_url(static::definition()->slug, false);

        if (!\is_string($url) || $url === '') {
            throw new LogicException('menu_page_url() returned an empty URL');
        }

        if ($queryArgs === []) {
            return $url;
        }

        if (!\function_exists('add_query_arg')) {
            throw new LogicException('add_query_arg() is not available');
        }

        $urlWithArgs = add_query_arg($queryArgs, $url);

        if (!\is_string($urlWithArgs) || $urlWithArgs === '') {
            throw new LogicException('add_query_arg() returned an empty URL');
        }

        return $urlWithArgs;
    }

    /**
     * @internal
     */
    final public function frameworkCreateForm(): ?Form
    {
        return $this->createForm();
    }

    /**
     * @internal
     */
    final public function frameworkSubmit(FormResult $result): void
    {
        $this->onSubmit($result);
    }

    /**
     * @internal
     */
    final public function frameworkRender(?Form $form): void
    {
        $this->render($form);
    }
}
