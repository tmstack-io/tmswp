<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Tests\Support\UsesTempDirectories;
use TMSWP\Core\View\ActionRouter;
use TMSWP\Core\View\Template;
use TMSWP\Core\View\View;
use WP_Query;
use WP_UnitTestCase;

final class KernelBootIntegrationTest extends WP_UnitTestCase
{
    use UsesTempDirectories;

    /** @var list<callable(string): void> */
    private array $autoloaders = [];

    /** @var list<string> */
    private array $optionKeys = [];

    private ?string $originalStylesheet = null;

    /** @var null|list<string> */
    private ?array $originalThemeDirectories = null;

    public function tear_down(): void
    {
        if ($this->originalStylesheet !== null) {
            switch_theme($this->originalStylesheet);
        }

        if ($this->originalThemeDirectories !== null) {
            $GLOBALS['wp_theme_directories'] = $this->originalThemeDirectories;
            wp_clean_themes_cache(false);
        }

        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        foreach ($this->optionKeys as $optionKey) {
            delete_option($optionKey);
        }

        $this->autoloaders = [];
        $this->optionKeys = [];
        $this->originalStylesheet = null;
        $this->originalThemeDirectories = null;
        Kernel::reset();
        Template::setLocator(null);
        View::reset();
        $this->cleanupTempDirectories();

        parent::tear_down();
    }

    public function testThemeBootRunsMethodHooksOnWordPressHooks(): void
    {
        $namespace = $this->runtimeNamespace('ThemeApp');
        $appDir = $this->createTempDirectory('integration-theme-app');
        $cacheDir = $this->createTempDirectory('integration-theme-cache');
        $constructedOption = $this->trackOption('tmswp_m1_theme_constructed');
        $actionOption = $this->trackOption('tmswp_m1_theme_action');

        $this->writeHookClass($namespace, $appDir, $constructedOption, $actionOption, 'tmswp_m1_theme_action', 'tmswp_m1_theme_filter');
        $this->registerAppAutoloader($namespace, $appDir);

        Kernel::boot(KernelConfig::theme($appDir, $namespace, $cacheDir, false, false));

        self::assertFalse(get_option($constructedOption));
        self::assertNotFalse(has_action('tmswp_m1_theme_action'));
        self::assertNotFalse(has_filter('tmswp_m1_theme_filter'));

        do_action('tmswp_m1_theme_action');

        self::assertSame('1', get_option($constructedOption));
        self::assertSame('1', get_option($actionOption));
        self::assertSame('value-filtered', apply_filters('tmswp_m1_theme_filter', 'value'));
    }

    public function testPluginBootRunsMethodHooksOnWordPressHooks(): void
    {
        $namespace = $this->runtimeNamespace('PluginApp');
        $appDir = $this->createTempDirectory('integration-plugin-app');
        $cacheDir = $this->createTempDirectory('integration-plugin-cache');
        $pluginFile = $this->writeFile($this->createTempDirectory('integration-plugin-root') . '/plugin.php', "<?php\n");
        $constructedOption = $this->trackOption('tmswp_m1_plugin_constructed');
        $actionOption = $this->trackOption('tmswp_m1_plugin_action');

        $this->writeHookClass($namespace, $appDir, $constructedOption, $actionOption, 'tmswp_m1_plugin_action', 'tmswp_m1_plugin_filter');
        $this->registerAppAutoloader($namespace, $appDir);

        Kernel::boot(KernelConfig::plugin($pluginFile, $appDir, $namespace, $cacheDir, false));

        self::assertFalse(get_option($constructedOption));
        self::assertNotFalse(has_action('tmswp_m1_plugin_action'));
        self::assertNotFalse(has_filter('tmswp_m1_plugin_filter'));

        do_action('tmswp_m1_plugin_action');

        self::assertSame('1', get_option($constructedOption));
        self::assertSame('1', get_option($actionOption));
        self::assertSame('value-filtered', apply_filters('tmswp_m1_plugin_filter', 'value'));
    }

    public function testPluginBootRegistersTemplateFallbackDirectory(): void
    {
        $namespace = $this->runtimeNamespace('PluginTemplateApp');
        $appDir = $this->createTempDirectory('integration-plugin-template-app');
        $cacheDir = $this->createTempDirectory('integration-plugin-template-cache');
        $pluginRoot = $this->createTempDirectory('integration-plugin-template-root');
        $pluginFile = $this->writeFile($pluginRoot . '/plugin.php', "<?php\n");
        $templateFile = $this->writeFile($pluginRoot . '/templates/plugin/card.php', <<<'PHP'
<?php
echo 'plugin fallback';
PHP);

        Kernel::boot(KernelConfig::plugin($pluginFile, $appDir, $namespace, $cacheDir, false));

        self::assertSame($templateFile, Template::locate('plugin/card'));
        self::assertSame('plugin fallback', Template::render('plugin/card'));
    }

    public function testActionRouterDispatchesThemeActionsInWordPressContext(): void
    {
        $themeRoot = $this->createTempDirectory('integration-action-theme-root');
        $themeSlug = 'tmswp-action-theme-' . uniqid();
        $themeDir = $themeRoot . '/' . $themeSlug;

        $this->writeFile($themeDir . '/style.css', <<<CSS
/*
Theme Name: TMSWP Action Router Test
*/
CSS);
        $this->writeFile($themeDir . '/index.php', "<?php\n");
        $this->writeFile($themeDir . '/actions/index.php', <<<'PHP'
<?php
return ['message' => 'from action'];
PHP);

        $this->switchToTemporaryTheme($themeRoot, $themeSlug);
        $GLOBALS['wp_query'] = new WP_Query();

        $router = new ActionRouter();
        $template = $themeDir . '/index.php';

        self::assertSame($template, $router->dispatch($template));
        self::assertSame('from action', View::get('message'));
    }

    public function testThemeActionCanCreateAndRenderForm(): void
    {
        $themeRoot = $this->createTempDirectory('integration-form-theme-root');
        $themeSlug = 'tmswp-form-theme-' . uniqid();
        $themeDir = $themeRoot . '/' . $themeSlug;

        $this->writeFile($themeDir . '/style.css', <<<CSS
/*
Theme Name: TMSWP Form Theme Test
*/
CSS);
        $template = $this->writeFile($themeDir . '/contact.php', "<?php\n");
        $this->writeFile($themeDir . '/actions/contact.php', <<<'PHP'
<?php

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Required;

$form = new Form(FormDefinition::create('contact')->text('name', 'お名前', [new Required()]));

return ['contactForm' => $form];
PHP);
        $this->writeFile($themeDir . '/parts/contact-form.php', <<<'PHP'
<?php

$form = $args['form'];
if (!$form instanceof \TMSWP\Core\Form\Form) {
    throw new RuntimeException('Form instance is required.');
}

echo $form->open();
echo $form->field('name')->input();
echo $form->close();
PHP);

        $this->switchToTemporaryTheme($themeRoot, $themeSlug);
        $GLOBALS['wp_query'] = new WP_Query();

        self::assertSame($template, (new ActionRouter())->dispatch($template));
        self::assertStringContainsString(
            'name="name"',
            Template::render('parts/contact-form', ['form' => View::get('contactForm')]),
        );
    }

    public function testPluginTemplateFallbackCanRenderFormControl(): void
    {
        $namespace = $this->runtimeNamespace('PluginFormTemplateApp');
        $appDir = $this->createTempDirectory('integration-plugin-form-app');
        $cacheDir = $this->createTempDirectory('integration-plugin-form-cache');
        $pluginRoot = $this->createTempDirectory('integration-plugin-form-root');
        $pluginFile = $this->writeFile($pluginRoot . '/plugin.php', "<?php\n");

        $this->writeFile($pluginRoot . '/templates/plugin/form.php', <<<'PHP'
<?php

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;

$form = new Form(FormDefinition::create('plugin_contact')->text('name', 'お名前'));
echo $form->field('name')->input(['class' => 'plugin-form-name']);
PHP);

        Kernel::boot(KernelConfig::plugin($pluginFile, $appDir, $namespace, $cacheDir, false));

        $html = Template::render('plugin/form');

        self::assertStringContainsString('class="plugin-form-name"', $html);
        self::assertStringContainsString('name="name"', $html);
    }

    private function runtimeNamespace(string $prefix): string
    {
        return 'Example\\Tests\\Integration\\' . $prefix . uniqid();
    }

    private function trackOption(string $prefix): string
    {
        $option = $prefix . '_' . uniqid();
        $this->optionKeys[] = $option;

        return $option;
    }

    private function switchToTemporaryTheme(string $themeRoot, string $themeSlug): void
    {
        if ($this->originalStylesheet === null) {
            $this->originalStylesheet = get_stylesheet();
        }

        if ($this->originalThemeDirectories === null) {
            $this->originalThemeDirectories = $this->themeDirectories();
        }

        register_theme_directory($themeRoot);
        wp_clean_themes_cache(false);
        switch_theme($themeSlug);
    }

    /** @return list<string> */
    private function themeDirectories(): array
    {
        $directories = $GLOBALS['wp_theme_directories'] ?? [];

        if (!\is_array($directories)) {
            return [];
        }

        $strings = [];

        foreach ($directories as $directory) {
            if (\is_string($directory)) {
                $strings[] = $directory;
            }
        }

        return $strings;
    }

    private function registerAppAutoloader(string $namespace, string $appDir): void
    {
        $prefix = $namespace . '\\';
        $loader = static function (string $class) use ($appDir, $prefix): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, \strlen($prefix))) . '.php';
            $file = $appDir . DIRECTORY_SEPARATOR . $relative;

            if (!is_file($file)) {
                return;
            }

            require $file;
        };

        spl_autoload_register($loader);
        $this->autoloaders[] = $loader;
    }

    private function writeHookClass(
        string $namespace,
        string $appDir,
        string $constructedOption,
        string $actionOption,
        string $actionHook,
        string $filterHook,
    ): void {
        $this->writeFile($appDir . '/Hooks/SampleHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class SampleHook extends Hook
{
    public function __construct()
    {
        update_option('{$constructedOption}', '1');
    }

    #[Action('{$actionHook}')]
    public function handle(): void
    {
        update_option('{$actionOption}', '1');
    }

    #[Filter('{$filterHook}')]
    public function filter(string \$value): string
    {
        return \$value . '-filtered';
    }
}
PHP);
    }

    private function writeFile(string $file, string $contents): string
    {
        $dir = \dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("fixture ディレクトリを作成できません: {$dir}");
        }

        $bytes = file_put_contents($file, $contents);

        if ($bytes === false) {
            self::fail("fixture ファイルを書き込めません: {$file}");
        }

        return $file;
    }
}
