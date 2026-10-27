<?php

declare(strict_types=1);

namespace TMSWP\Tests\Integration;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use TMSWP\Testing\IntegrationTestCase;

/**
 * スターター(starters/theme・starters/plugin)を、wp-envの開発用サイトで動かして確かめる。
 *
 * スターターの写しを、リポジトリのvar/starter-site/の下(プラグインの置き場wp-content/plugins/tmswpの中)に作る。
 * WordPressは別のプロセス(WP-CLIとPHPの組み込みサーバ)で動かし、fixtures/starter-site/site.phpが、その
 * プロセスの中だけ写しのテーマとプラグインを有効にする。このテストのプロセス(fixtureのテーマとプラグインが起動済み)
 * では、写しのKernelをbootしない。開発用サイトの有効なテーマ・プラグインの設定も変えない。
 *
 * 写しの依存は、create-projectと同じくComposerで写しのvendor/に入れる。ネットワークに依存しないよう、写しの
 * composer.jsonに限って、Packagistを使わず、tmswp/coreをpackages/coreから、psr/log・psr/containerをリポジトリの
 * vendor/から入れ(pathリポジトリ)、require-devを外す(開発用の依存は起動に要らない)。
 */
final class StarterProjectsIntegrationTest extends IntegrationTestCase
{
    private const string WORKSPACE_DIR = 'var/starter-site';

    /** @var list<string> */
    private array $workspaces = [];

    public function tear_down(): void
    {
        foreach ($this->workspaces as $workspace) {
            self::deleteDirectory($workspace);
        }

        $this->workspaces = [];
        parent::tear_down();
    }

    /**
     * コミット済みのスターターの探索結果ファイルが、スターターのapp/とbuild/blocks/から作るものと一致する。
     * 食い違う場合は、作り直したファイルをvar/starter-site/に残し、写す先を失敗のメッセージで示す。
     */
    public function testCommittedStarterDiscoveryFilesAreUpToDate(): void
    {
        $workspace = $this->createWorkspace();
        $theme = $workspace . '/themes/tmswp-starter-theme';
        $plugin = $workspace . '/plugins/tmswp-starter-plugin';
        self::copyStarter('theme', $theme);
        self::copyStarter('plugin', $plugin);
        self::installDependencies($theme);
        self::installDependencies($plugin);
        self::assertSame('TmswpStarterTheme', self::autoloadedAppNamespace($theme));
        self::assertSame('TmswpStarterPlugin', self::autoloadedAppNamespace($plugin));
        $site = self::site($workspace . '/themes', 'tmswp-starter-theme', [$plugin . '/plugin.php']);

        $result = self::wp($site, ['tmswp', 'discover']);

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('discovery files are up to date for 2 kernel(s)', $result['stdout']);

        $stale = [];

        foreach (['theme' => $theme, 'plugin' => $plugin] as $kind => $copy) {
            $committed = self::repositoryRoot() . "/starters/{$kind}/tmswp-discovery.php";
            $generated = (string) file_get_contents($copy . '/tmswp-discovery.php');

            if (!is_file($committed) || file_get_contents($committed) !== $generated) {
                $regenerated = self::WORKSPACE_DIR . "/tmswp-discovery.{$kind}.php";
                file_put_contents(self::repositoryRoot() . '/' . $regenerated, $generated);
                $stale[] = "starters/{$kind}/tmswp-discovery.php is out of date; copy {$regenerated} to it";
            }
        }

        self::assertSame([], $stale);
    }

    /**
     * create-projectと同じ手順(依存を入れてオートローダを作った後でpost-create-project-cmd)で作ったテーマ1つと
     * プラグイン2本が、同じサイトで別々の名前空間のKernelとして起動し、探索結果ファイルを作り直せて、フロントが
     * 200を返す。
     */
    public function testProjectsCreatedFromStartersBootTogetherOnOneSite(): void
    {
        $workspace = $this->createWorkspace();
        $theme = $workspace . '/themes/client-theme';
        $shopTools = $workspace . '/plugins/shop-tools';
        $memberArea = $workspace . '/plugins/member_area';
        self::copyStarter('theme', $theme);
        self::copyStarter('plugin', $shopTools);
        self::copyStarter('plugin', $memberArea);

        self::createProject($theme, 'client-theme', 'Client Theme');
        self::createProject($shopTools, 'shop-tools', 'Shop Tools');
        self::createProject($memberArea, 'member_area', 'Member Area');

        self::assertSame('ClientTheme', self::autoloadedAppNamespace($theme));
        self::assertSame('ShopTools', self::autoloadedAppNamespace($shopTools));
        self::assertSame('MemberArea', self::autoloadedAppNamespace($memberArea));
        self::assertFileDoesNotExist($theme . '/tmswp-discovery.php');
        $site = self::site($workspace . '/themes', 'client-theme', [$shopTools . '/shop-tools.php', $memberArea . '/member_area.php']);

        $discover = self::wp($site, ['tmswp', 'discover']);

        self::assertSame(0, $discover['code'], $discover['stdout'] . $discover['stderr']);
        self::assertStringContainsString('discovery files are up to date for 3 kernel(s); wrote 3 file(s)', $discover['stdout']);
        self::assertStringContainsString("'appNamespace' => 'ClientTheme'", (string) file_get_contents($theme . '/tmswp-discovery.php'));
        self::assertStringContainsString("'name' => 'client-theme/example'", (string) file_get_contents($theme . '/tmswp-discovery.php'));

        $check = self::wp($site, ['tmswp', 'discover', '--check']);

        self::assertSame(0, $check['code'], $check['stdout'] . $check['stderr']);
        self::assertStringContainsString('discovery files are up to date for 3 kernel(s)', $check['stdout']);

        $response = self::requestFront($site, $workspace);

        self::assertSame(200, $response['status'], $response['body'] . $response['log']);
        self::assertSame('ShopTools,MemberArea,ClientTheme', $response['headers']['x-tmswp-kernels'] ?? null, substr($response['body'], 0, 3000) . $response['log']);
        self::assertContains('client-theme/example', explode(',', $response['headers']['x-tmswp-blocks'] ?? ''));
        self::assertStringContainsString('<header class="site-header">', $response['body']);
        self::assertStringContainsString('/themes/client-theme/assets/css/main.css', $response['body']);
        self::assertDoesNotMatchRegularExpression('/(Fatal error|Warning|Notice|Deprecated)<\/b>:|(Fatal error|Warning|Notice|Deprecated): /', $response['body']);
    }

    private function createWorkspace(): string
    {
        $workspace = self::repositoryRoot() . '/' . self::WORKSPACE_DIR . '/' . uniqid('site-', true);

        foreach ([$workspace . '/themes', $workspace . '/plugins'] as $dir) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("cannot create {$dir}");
            }
        }

        $this->workspaces[] = $workspace;

        return $workspace;
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * composer create-projectと同じく、スターターのディレクトリを写す(vendor/・node_modules/は除く)。
     */
    private static function copyStarter(string $kind, string $destination): void
    {
        $source = self::repositoryRoot() . '/starters/' . $kind;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                static fn(SplFileInfo $current): bool => !\in_array($current->getFilename(), ['vendor', 'node_modules'], true),
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new RuntimeException("cannot create {$destination}");
        }

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            $target = $destination . substr($file->getPathname(), \strlen($source));

            if ($file->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true)) {
                    throw new RuntimeException("cannot create {$target}");
                }
            } elseif (!copy($file->getPathname(), $target)) {
                throw new RuntimeException("cannot copy {$file->getPathname()} to {$target}");
            }
        }
    }

    /**
     * composer create-projectと同じ順に、依存を入れてオートローダを作ってからpost-create-project-cmdを実行する。
     * post-create-project-cmdは、端末の無い状態(標準入力はパイプ)で、スラッグと表示名を環境変数で受け取る。
     */
    private static function createProject(string $project, string $slug, string $name): void
    {
        self::installDependencies($project);
        $result = self::runProcess(
            ['composer', 'run-script', 'post-create-project-cmd', '--no-interaction', '--working-dir=' . $project],
            ['TMSWP_SETUP_SLUG' => $slug, 'TMSWP_SETUP_NAME' => $name],
            $project,
        );

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertDirectoryDoesNotExist($project . '/bin');

        // setup.phpがcomposer.lockのハッシュを記録し直すため、composer.jsonを書き換えた後もlockは古くならない
        $validate = self::runProcess(['composer', 'validate', '--no-check-publish', '--no-interaction', '--working-dir=' . $project], [], $project);

        self::assertSame(0, $validate['code'], $validate['stdout'] . $validate['stderr']);
    }

    /**
     * 写しの依存を、ネットワークを使わずにComposerで写しのvendor/に入れ、composer.lockとオートローダを作る。
     */
    private static function installDependencies(string $project): void
    {
        $root = self::repositoryRoot();
        $composer = json_decode((string) file_get_contents($project . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $installed = json_decode((string) file_get_contents($root . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $core = json_decode((string) file_get_contents($root . '/packages/core/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($installed);
        self::assertIsArray($installed['packages'] ?? null);
        self::assertIsArray($core);
        // packages/coreの版は、mainの開発版の別名(branch-alias)にする。スターターのtmswp/coreの制約はこれを満たす
        $extra = $core['extra'] ?? null;
        self::assertIsArray($extra);
        self::assertIsArray($extra['branch-alias'] ?? null);
        $coreVersion = $extra['branch-alias']['dev-main'] ?? null;
        self::assertIsString($coreVersion);
        $repositories = [
            ['packagist.org' => false],
            ['type' => 'path', 'url' => $root . '/packages/core', 'options' => ['versions' => ['tmswp/core' => $coreVersion]]],
        ];

        foreach ($installed['packages'] as $package) {
            if (\is_array($package) && \in_array($package['name'] ?? null, ['psr/log', 'psr/container'], true) && \is_string($package['version'] ?? null)) {
                $repositories[] = ['type' => 'path', 'url' => $root . '/vendor/' . $package['name'], 'options' => ['versions' => [$package['name'] => $package['version']]]];
            }
        }

        self::assertCount(4, $repositories);
        unset($composer['require-dev']);
        $composer['repositories'] = $repositories;
        $composer['minimum-stability'] = 'dev';
        $composer['prefer-stable'] = true;
        file_put_contents($project . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $result = self::runProcess(['composer', 'install', '--no-interaction', '--no-progress', '--working-dir=' . $project], [], $project);

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
    }

    /**
     * Composerが作ったオートローダ(vendor/composer/autoload_psr4.php)でapp/に割り当てた名前空間を返す。
     */
    private static function autoloadedAppNamespace(string $project): string
    {
        $psr4 = require $project . '/vendor/composer/autoload_psr4.php';

        self::assertIsArray($psr4);
        $namespaces = [];

        foreach ($psr4 as $prefix => $paths) {
            if (\is_string($prefix) && $paths === [$project . '/app']) {
                $namespaces[] = rtrim($prefix, '\\');
            }
        }

        self::assertCount(1, $namespaces, var_export($psr4, true));

        return $namespaces[0];
    }

    /**
     * @param list<string> $plugins プラグインの本体ファイルの絶対パス
     *
     * @return array<string, string>
     */
    private static function site(string $themeRoot, string $theme, array $plugins): array
    {
        return ['TMSWP_STARTER_SITE' => json_encode(['themeRoot' => $themeRoot, 'theme' => $theme, 'plugins' => $plugins], JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, string> $site
     * @param list<string> $arguments
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private static function wp(array $site, array $arguments): array
    {
        $require = __DIR__ . '/fixtures/starter-site/wp-cli.php';

        return self::runProcess(['wp', '--path=' . ABSPATH, '--require=' . $require, ...$arguments], $site, self::repositoryRoot());
    }

    /**
     * PHPの組み込みサーバでWordPressのフロントを動かし、トップページを1回取得する。
     *
     * @param array<string, string> $site
     *
     * @return array{status: int, headers: array<string, string>, body: string, log: string}
     */
    private static function requestFront(array $site, string $workspace): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('cannot find a free port for the PHP built-in server');
        }

        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $log = $workspace . '/server.log';
        $server = proc_open(
            [PHP_BINARY, '-S', $address, '-t', ABSPATH, __DIR__ . '/fixtures/starter-site/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            self::repositoryRoot(),
            [...self::environment(), ...$site],
        );

        if (!\is_resource($server)) {
            throw new RuntimeException('cannot start the PHP built-in server');
        }

        try {
            self::waitForServer($server, $log);
            $stream = fopen("http://{$address}/", 'r', false, stream_context_create([
                'http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60],
            ]));

            if ($stream === false) {
                throw new RuntimeException("cannot request http://{$address}/:\n" . file_get_contents($log));
            }

            $body = (string) stream_get_contents($stream);
            $wrapperData = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            fclose($stream);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        return [...self::parseResponse(\is_array($wrapperData) ? $wrapperData : [], $body), 'log' => (string) file_get_contents($log)];
    }

    /**
     * @param resource $server
     */
    private static function waitForServer($server, string $log): void
    {
        for ($i = 0; $i < 100; ++$i) {
            if (str_contains((string) file_get_contents($log), 'started')) {
                return;
            }

            if (!proc_get_status($server)['running']) {
                break;
            }

            usleep(100_000);
        }

        throw new RuntimeException("the PHP built-in server did not start:\n" . file_get_contents($log));
    }

    /**
     * @param array<mixed> $wrapperData
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function parseResponse(array $wrapperData, string $body): array
    {
        $status = 0;
        $headers = [];

        foreach ($wrapperData as $line) {
            if (!\is_string($line)) {
                continue;
            }

            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment このテストのプロセスの環境変数に足す値
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private static function runProcess(array $command, array $environment, string $cwd): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, [...self::environment(), ...$environment]);

        if (!\is_resource($process)) {
            throw new RuntimeException('cannot start ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * このテストのプロセスの環境変数。setup.phpの入力(TMSWP_SETUP_*)は、テストが渡すものだけにする。
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        return array_filter(
            getenv(),
            static fn(string $name): bool => !str_starts_with($name, 'TMSWP_SETUP_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
