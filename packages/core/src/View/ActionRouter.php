<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

use LogicException;
use WP_Query;

final class ActionRouter
{
    private const ACTIONS_DIR = 'actions';

    public const PRIORITY = 100;

    /** @var list<string> */
    private static array $actionStack = [];

    public static function notFound(): NotFound
    {
        return new NotFound();
    }

    public function dispatch(mixed $template): mixed
    {
        if (!\is_string($template) || $template === '') {
            return $template;
        }

        $relative = $this->relativeTemplatePath($template);

        if ($relative === null) {
            return $template;
        }

        $action = $this->locateAction($relative);

        if ($action === null) {
            return $template;
        }

        $wpQuery = $GLOBALS['wp_query'] ?? null;

        if (!$wpQuery instanceof WP_Query) {
            return $template;
        }

        $result = self::includeAction($action, $wpQuery);

        $this->debugAction($action);

        if (\is_array($result)) {
            $vars = $this->viewVars($result);

            if ($vars === null) {
                $this->debugInvalidResult('actions file returned an array with non-string keys.');

                return $template;
            }

            View::set($vars);

            return $template;
        }

        if ($result instanceof NotFound) {
            return $this->forceNotFound($wpQuery);
        }

        if ($result === null || $result === 1) {
            return $template;
        }

        $this->debugInvalidResult('actions file must return array, ActionRouter::notFound(), or null.');

        return $template;
    }

    /**
     * @param array<mixed, mixed> $result
     *
     * @return array<string, mixed>|null
     */
    private function viewVars(array $result): ?array
    {
        $vars = [];

        foreach ($result as $key => $value) {
            if (!\is_string($key)) {
                return null;
            }

            $vars[$key] = $value;
        }

        return $vars;
    }

    private function relativeTemplatePath(string $template): ?string
    {
        $templatePath = $this->normalizePath($template);

        foreach ($this->themeRoots() as $root) {
            $rootPath = $this->normalizePath($root);
            $prefix = rtrim($rootPath, '/') . '/';

            if (str_starts_with($templatePath, $prefix)) {
                return substr($templatePath, \strlen($prefix));
            }
        }

        return null;
    }

    private function locateAction(string $relative): ?string
    {
        foreach ($this->themeRoots() as $root) {
            $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . self::ACTIONS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function themeRoots(): array
    {
        return array_values(array_unique([
            rtrim(get_stylesheet_directory(), '/\\'),
            rtrim(get_template_directory(), '/\\'),
        ]));
    }

    private function forceNotFound(WP_Query $wpQuery): string
    {
        $wpQuery->set_404();

        if (!headers_sent()) {
            status_header(404);
            nocache_headers();
        } elseif (\defined('WP_DEBUG') && WP_DEBUG === true && \function_exists('wp_trigger_error')) {
            wp_trigger_error(__METHOD__, '出力開始後のため 404 ステータス行を上書きできません。先行プラグインの出力タイミングを確認してください。', E_USER_WARNING);
        }

        $template404 = get_404_template();

        return $template404 !== '' ? $template404 : get_index_template();
    }

    private function debugAction(string $action): void
    {
        if (!\defined('WP_DEBUG') || WP_DEBUG !== true || is_admin()) {
            return;
        }

        add_action('wp_footer', static function () use ($action): void {
            $relative = \defined('ABSPATH') ? str_replace(ABSPATH, '', $action) : $action;
            printf("\n<!-- tms:action %s -->\n", esc_html($relative));
        }, 999, 0);
    }

    private function debugInvalidResult(string $message): void
    {
        if (\defined('WP_DEBUG') && WP_DEBUG === true && \function_exists('wp_trigger_error')) {
            wp_trigger_error(self::class . '::dispatch', $message, E_USER_WARNING);
        }
    }

    private static function includeAction(string $action, WP_Query $wpQuery): mixed
    {
        self::$actionStack[] = $action;

        try {
            return (static function (WP_Query $wp_query): mixed {
                return include self::currentActionFile();
            })($wpQuery);
        } finally {
            array_pop(self::$actionStack);
        }
    }

    private static function currentActionFile(): string
    {
        $key = array_key_last(self::$actionStack);

        if ($key === null) {
            throw new LogicException('no current actions file is being included');
        }

        return self::$actionStack[$key];
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
