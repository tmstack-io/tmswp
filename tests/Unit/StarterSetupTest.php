<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit;

use FilesystemIterator;
use Patchwork\CodeManipulation\Stream as PatchworkStream;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use TMSWP\Testing\UnitTestCase;

/**
 * スターターのbin/setup.php(create-projectの直後の初期化)を、スターターの写しでCLIとして実行して確かめる。
 * 標準入力はパイプ(端末ではない)にするため、対話の入力は使わない。
 */
final class StarterSetupTest extends UnitTestCase
{
    public function testThemeSetupRewritesTheProjectWithTheGeneratedNamespace(): void
    {
        $project = $this->copyStarter('theme', 'client-theme');
        // 名前空間は識別子の全体だけを置き換え、別の識別子の一部は変えない
        file_put_contents($project . '/app/identifiers.txt', "TmswpStarterTheme\\Hook TmswpStarterThemeExtra XTmswpStarterTheme\n");

        $result = $this->runSetup($project, ['--slug=client-theme', '--name=Client Theme']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Initialized Client Theme (slug: client-theme, namespace: ClientTheme\)', $result['stdout']);
        self::assertStringContainsString('npm ci && npm run build', $result['stdout']);

        $composer = $this->composerJson($project);
        self::assertSame('local/client-theme', $composer['name']);
        self::assertSame('Client Theme (WordPress theme)', $composer['description']);
        self::assertSame('proprietary', $composer['license']);
        self::assertSame(['psr-4' => ['ClientTheme\\' => 'app/']], $composer['autoload']);
        self::assertSame(['psr-4' => ['ClientTheme\\Tests\\' => 'tests/']], $composer['autoload-dev']);
        self::assertIsArray($composer['scripts']);
        self::assertArrayNotHasKey('post-create-project-cmd', $composer['scripts']);
        self::assertSame(['@lint', '@analyse', '@discover:check', '@test'], $composer['scripts']['qa']);
        self::assertIsArray($composer['config']);
        self::assertSame('ClientTheme', $composer['config']['autoloader-suffix']);

        self::assertStringContainsString("appNamespace: 'ClientTheme'", $this->read($project . '/tmswp-kernel.php'));
        self::assertStringContainsString('namespace ClientTheme\Hook;', $this->read($project . '/app/Hook/ThemeSetupHook.php'));
        self::assertStringContainsString('Theme Name: Client Theme', $this->read($project . '/style.css'));
        self::assertStringContainsString('Text Domain: client-theme', $this->read($project . '/style.css'));
        self::assertStringContainsString('`ClientTheme\`', $this->read($project . '/AGENTS.md'));

        foreach (['blocks/example/block.json', 'build/blocks/example/block.json'] as $blockJson) {
            $block = json_decode($this->read($project . '/' . $blockJson), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($block);
            self::assertSame('client-theme/example', $block['name']);
            self::assertSame('client-theme', $block['textdomain']);
        }

        self::assertStringContainsString('"client-theme/example"', $this->read($project . '/build/blocks/example/index.js'));
        self::assertFileDoesNotExist($project . '/tmswp-discovery.php');
        self::assertDirectoryDoesNotExist($project . '/bin');
        // composer.jsonの字下げ(2つの空白)を保つ
        self::assertStringStartsWith("{\n  \"name\": \"local/client-theme\",\n", $this->read($project . '/composer.json'));
        // 作ったプロジェクトのREADME.mdに、スターターの作り方とbin/setup.phpの手順を残さない
        $readme = $this->read($project . '/README.md');
        self::assertStringNotContainsString('bin/setup.php', $readme);
        self::assertStringNotContainsString('tmswp-starter-setup', $readme);
        self::assertStringStartsWith("# Client Theme\n\nTMStack WordPress Framework を使用したWordPressテーマ。\n\n## セットアップの後\n", $readme);
        self::assertSame("ClientTheme\\Hook TmswpStarterThemeExtra XTmswpStarterTheme\n", $this->read($project . '/app/identifiers.txt'));
        unlink($project . '/app/identifiers.txt');
        self::assertSame([], $this->filesContaining($project, ['{{', 'TmswpStarterTheme', 'tmswp-starter/']));
        $this->assertPhpFilesAreValid($project);
    }

    /**
     * npmのパッケージの名前を、スラッグにする(npmの名前に使える`_`を含むスラッグでも同じ)。package.jsonには先頭に
     * nameを足し、package-lock.jsonは先頭のnameとルートのパッケージ(packages[""])のnameを変える。npmが同じ
     * package.jsonから書くのと同じ形で、ほかの内容と字下げは変えない。
     */
    public function testThemeSetupRenamesTheNpmPackageToTheSlug(): void
    {
        $starter = \dirname(__DIR__, 2) . '/starters/theme';
        $project = $this->copyStarter('theme', 'shop_theme');

        $result = $this->runSetup($project, ['--slug=shop_theme', '--name=Shop Theme']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(
            preg_replace('/\A\{\n/', "{\n  \"name\": \"shop_theme\",\n", $this->read($starter . '/package.json'), 1, $added),
            $this->read($project . '/package.json'),
        );
        self::assertSame(1, $added);

        $lock = $this->read($starter . '/package-lock.json');
        self::assertStringStartsWith("{\n  \"name\": \"theme\",\n", $lock);
        self::assertStringContainsString("\n    \"\": {\n      \"devDependencies\": {\n", $lock);
        self::assertSame(
            strtr($lock, [
                "{\n  \"name\": \"theme\",\n" => "{\n  \"name\": \"shop_theme\",\n",
                "\n    \"\": {\n      \"devDependencies\": {\n" => "\n    \"\": {\n      \"name\": \"shop_theme\",\n      \"devDependencies\": {\n",
            ]),
            $this->read($project . '/package-lock.json'),
        );
    }

    /**
     * ルートのパッケージ(packages[""])の無いpackage-lock.json(npm 6以前の形式)は、名前を書き換えられないため、
     * プロジェクトを書き換えずに止まる。
     */
    public function testPackageLockWithoutTheRootPackageStopsWithoutChangingTheProject(): void
    {
        $project = $this->copyStarter('theme', 'old-lock');
        file_put_contents($project . '/package-lock.json', "{\n  \"name\": \"theme\",\n  \"lockfileVersion\": 1\n}\n");
        $before = $this->snapshot($project);

        $result = $this->runSetup($project, ['--slug=old-lock', '--name=Old Lock']);

        self::assertSame(1, $result['code']);
        self::assertStringStartsWith('ERROR: ', $result['stderr']);
        self::assertStringEndsWith("/old-lock/package-lock.json has no root package (packages[\"\"]); regenerate it with npm 7 or later\n", $result['stderr']);
        self::assertSame($before, $this->snapshot($project));
    }

    public function testPluginSetupRenamesTheMainFileAndAcceptsEnvironmentVariables(): void
    {
        $project = $this->copyStarter('plugin', 'member_area');

        $result = $this->runSetup($project, [], ['TMSWP_SETUP_SLUG' => 'member_area', 'TMSWP_SETUP_NAME' => 'Member Area']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('composer discover', $result['stdout']);
        self::assertFileDoesNotExist($project . '/plugin.php');

        $main = $this->read($project . '/member_area.php');
        self::assertStringContainsString('Plugin Name: Member Area', $main);
        self::assertStringContainsString('Text Domain: member_area', $main);

        $kernel = $this->read($project . '/tmswp-kernel.php');
        self::assertStringContainsString("file: __DIR__ . '/member_area.php'", $kernel);
        self::assertStringContainsString("appNamespace: 'MemberArea'", $kernel);
        self::assertStringContainsString("__DIR__ . '/member_area.php'", $this->read($project . '/.php-cs-fixer.dist.php'));
        self::assertStringContainsString('namespace MemberArea\Hook;', $this->read($project . '/app/Hook/PluginSetupHook.php'));
        self::assertSame(['psr-4' => ['MemberArea\\' => 'app/']], $this->composerJson($project)['autoload']);
        self::assertSame('Member Area (WordPress plugin)', $this->composerJson($project)['description']);
        // npmを使わないプラグインには、package.json・package-lock.jsonを作らない
        self::assertFileDoesNotExist($project . '/package.json');
        self::assertFileDoesNotExist($project . '/package-lock.json');
        self::assertSame([], $this->filesContaining($project, ['{{', 'TmswpStarterPlugin', "'/plugin.php'"]));
        $this->assertPhpFilesAreValid($project);
    }

    /**
     * 標準入力が端末のときは、スラッグと表示名を尋ね、空の入力は示した既定値にする。
     *
     * setup.phpが端末かを判定するのは標準入力(stream_isatty(STDIN))だけなので、端末(pty)にするのは標準入力だけにし、
     * 標準出力・標準エラーはパイプで受け取る。Linuxでは子プロセスの終了後にptyのmaster側を読むとEIOになり、
     * freadがE_NOTICEを出すため、出力をptyから読まない。
     */
    public function testTerminalAsksForTheValuesAndAcceptsTheDefaults(): void
    {
        $project = $this->copyStarter('plugin', 'acme-tools');
        $process = proc_open([PHP_BINARY, $project . '/bin/setup.php'], [0 => ['pty'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project, $this->environment([]));

        if (!\is_resource($process)) {
            self::fail('setup.phpを端末で起動できません。');
        }

        // スラッグは既定値(ディレクトリ名)、表示名は入力した値にする
        fwrite($pipes[0], "\nAcme Toolkit\n");
        $output = (string) stream_get_contents($pipes[1]);
        $errorOutput = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, $output . $errorOutput);
        self::assertStringContainsString('Project slug [acme-tools]: ', $output);
        self::assertStringContainsString('Project name [Acme Tools]: ', $output);
        self::assertStringContainsString('Plugin Name: Acme Toolkit', $this->read($project . '/acme-tools.php'));
        self::assertStringContainsString("appNamespace: 'AcmeTools'", $this->read($project . '/tmswp-kernel.php'));
    }

    /**
     * create-projectが作ったcomposer.lockがあれば、Composer(COMPOSER_BINARY)で書き換えたcomposer.jsonのハッシュを
     * 記録し直す。
     */
    public function testUpdatesTheHashInComposerLockWithComposer(): void
    {
        $project = $this->copyStarter('plugin', 'shop-tools');
        file_put_contents($project . '/composer.lock', "{}\n");
        $record = \dirname($project) . '/composer-arguments.json';
        $composer = $this->writeFakeComposer(\dirname($project), $record, 0);

        $result = $this->runSetup($project, ['--slug=shop-tools', '--name=Shop Tools'], ['COMPOSER_BINARY' => $composer]);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(['update', '--lock', '--no-install', '--no-interaction', '--working-dir=' . realpath($project)], json_decode($this->read($record), true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('composer update --lock', $result['stdout']);
    }

    public function testStopsWhenComposerCannotUpdateTheLockFile(): void
    {
        $project = $this->copyStarter('plugin', 'shop-tools');
        file_put_contents($project . '/composer.lock', "{}\n");
        $composer = $this->writeFakeComposer(\dirname($project), \dirname($project) . '/composer-arguments.json', 1);

        $result = $this->runSetup($project, ['--slug=shop-tools', '--name=Shop Tools'], ['COMPOSER_BINARY' => $composer]);

        self::assertSame(1, $result['code']);
        self::assertSame("ERROR: cannot update the hash in composer.lock; run `composer update --lock`\n", $result['stderr']);
    }

    public function testAsksToUpdateTheLockFileWhenNotRunByComposer(): void
    {
        $project = $this->copyStarter('plugin', 'shop-tools');
        file_put_contents($project . '/composer.lock', "{}\n");

        $result = $this->runSetup($project, ['--slug=shop-tools', '--name=Shop Tools']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Then run `composer update --lock`', $result['stdout']);
    }

    /**
     * 名前空間を書き換えた後に、プロジェクトのphp-cs-fixerでuseの並びを整える。スターターの名前空間(T)より前に並ぶ
     * 名前空間(ClientTheme)では、例のテストのuseの並びが変わる。
     */
    public function testSortsTheImportsWithThePhpCsFixerOfTheProject(): void
    {
        $project = $this->copyStarter('theme', 'client-theme');
        mkdir($project . '/vendor/bin', 0o777, true);
        $fixer = \dirname(__DIR__, 2) . '/vendor/friendsofphp/php-cs-fixer/php-cs-fixer';
        file_put_contents($project . '/vendor/bin/php-cs-fixer', "<?php\n\nrequire " . var_export($fixer, true) . ";\n");

        $result = $this->runSetup($project, ['--slug=client-theme', '--name=Client Theme']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString(
            "use Brain\\Monkey\\Functions;\nuse ClientTheme\\Hook\\ThemeSetupHook;\nuse Mockery\\Adapter\\Phpunit\\MockeryPHPUnitIntegration;\nuse PHPUnit\\Framework\\TestCase;\n",
            $this->read($project . '/tests/Unit/Hook/ThemeSetupHookTest.php'),
        );
        self::assertStringNotContainsString('composer fix', $result['stdout']);
        self::assertFileDoesNotExist($project . '/.php-cs-fixer.cache');
    }

    /**
     * php-cs-fixerは、ほかのファイルを書き換えた後、composer.jsonを書く前に、プロジェクトの設定で実行する。
     */
    public function testRunsPhpCsFixerBeforeWritingComposerJson(): void
    {
        $project = $this->copyStarter('plugin', 'shop-tools');
        $record = \dirname($project) . '/fixer.json';
        $this->writeFakeFixer($project, $record, 0);

        $result = $this->runSetup($project, ['--slug=shop-tools', '--name=Shop Tools']);

        self::assertSame(0, $result['code'], $result['stderr']);
        $root = realpath($project);
        self::assertIsString($root);
        self::assertSame(
            [
                'arguments' => ['fix', '--config=' . $root . '/.php-cs-fixer.dist.php', '--using-cache=no'],
                'cwd' => $root,
                'rewritten' => true,
                'composerRewritten' => false,
            ],
            json_decode($this->read($record), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * php-cs-fixerが失敗したら止まる。composer.jsonとbin/setup.phpは残るため、直した後でもう一度実行できる。
     */
    public function testStopsWhenPhpCsFixerFailsAndCanRunAgain(): void
    {
        $project = $this->copyStarter('plugin', 'shop-tools');
        $this->writeFakeFixer($project, \dirname($project) . '/fixer.json', 1);

        $result = $this->runSetup($project, ['--slug=shop-tools', '--name=Shop Tools']);

        self::assertSame(1, $result['code']);
        self::assertSame("ERROR: php-cs-fixer failed to format the rewritten PHP files; fix the error and run bin/setup.php again\n", $result['stderr']);
        self::assertSame(['psr-4' => ['TmswpStarterPlugin\\' => 'app/']], $this->composerJson($project)['autoload']);
        self::assertFileExists($project . '/bin/setup.php');

        $this->writeFakeFixer($project, \dirname($project) . '/fixer.json', 0);
        $result = $this->runSetup($project, ['--slug=shop-tools', '--name=Shop Tools']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(['psr-4' => ['ShopTools\\' => 'app/']], $this->composerJson($project)['autoload']);
        self::assertSame([], $this->filesContaining($project, ['{{', 'TmswpStarterPlugin', "'/plugin.php'"]));
    }

    /**
     * 依存を入れていない(create-projectの--no-install)ためにphp-cs-fixerが無ければ、`composer fix`の実行を案内する。
     */
    public function testAsksToRunTheFixerWhenPhpCsFixerIsNotInstalled(): void
    {
        $project = $this->copyStarter('plugin', 'shop-tools');

        $result = $this->runSetup($project, ['--slug=shop-tools', '--name=Shop Tools']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Then run `composer fix`', $result['stdout']);
    }

    public function testArgumentsTakePrecedenceOverEnvironmentVariables(): void
    {
        $project = $this->copyStarter('plugin', 'shop-tools');

        $result = $this->runSetup($project, ['--slug=shop-tools', '--name=Shop Tools'], ['TMSWP_SETUP_SLUG' => 'other-tools', 'TMSWP_SETUP_NAME' => 'Other Tools']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileExists($project . '/shop-tools.php');
        self::assertStringContainsString('Plugin Name: Shop Tools', $this->read($project . '/shop-tools.php'));
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputStopsWithoutChangingTheProject(array $arguments, array $environment, string $message): void
    {
        $project = $this->copyStarter('plugin', 'invalid-input');
        $before = $this->snapshot($project);

        $result = $this->runSetup($project, $arguments, $environment);

        self::assertSame(1, $result['code']);
        self::assertSame('', $result['stdout']);
        self::assertStringStartsWith('ERROR: ' . $message, $result['stderr']);
        self::assertSame($before, $this->snapshot($project));
    }

    /**
     * @return iterable<string, array{list<string>, array<string, string>, string}>
     */
    public static function invalidInputs(): iterable
    {
        $missing = 'must be given without a terminal (bin/setup.php does not ask and has no default): '
            . 'pass --slug=<slug> --name=<name>, or set TMSWP_SETUP_SLUG and TMSWP_SETUP_NAME';

        yield 'no slug and no name' => [[], [], "the project slug and name {$missing}"];
        yield 'no slug' => [['--name=Shop'], [], "the project slug {$missing}"];
        yield 'no name' => [[], ['TMSWP_SETUP_SLUG' => 'shop'], "the project name {$missing}"];
        yield 'uppercase slug' => [['--slug=Shop', '--name=Shop'], [], "invalid slug 'Shop'"];
        yield 'slug starting with a digit' => [['--slug=2shop', '--name=Shop'], [], "invalid slug '2shop'"];
        yield 'slug starting with a hyphen' => [['--slug=-shop', '--name=Shop'], [], "invalid slug '-shop'"];
        yield 'slug with a dot' => [['--slug=shop.tools', '--name=Shop'], [], "invalid slug 'shop.tools'"];
        yield 'slug with a space' => [['--slug=shop tools', '--name=Shop'], [], "invalid slug 'shop tools'"];
        yield 'slug with a trailing newline' => [["--slug=shop\n", '--name=Shop'], [], "invalid slug 'shop\n'"];
        yield 'empty slug' => [['--slug=', '--name=Shop'], [], "invalid slug ''"];
        yield 'slug ending with an underscore' => [['--slug=shop_', '--name=Shop'], [], "invalid slug 'shop_'"];
        yield 'slug ending with a hyphen' => [['--slug=shop-', '--name=Shop'], [], "invalid slug 'shop-'"];
        yield 'slug with two underscores in a row' => [['--slug=shop__tools', '--name=Shop'], [], "invalid slug 'shop__tools'"];
        yield 'slug with a hyphen and an underscore in a row' => [['--slug=shop-_tools', '--name=Shop'], [], "invalid slug 'shop-_tools'"];
        yield 'reserved word' => [['--slug=list', '--name=List'], [], "slug 'list' makes the namespace 'List', which is a reserved word in PHP"];
        yield 'reserved word in two parts' => [['--slug=fore_ach', '--name=Shop'], [], "slug 'fore_ach' makes the namespace 'ForeAch', which is a reserved word in PHP"];
        yield 'self' => [['--slug=self', '--name=Shop'], [], "slug 'self' makes the namespace 'Self', which is a reserved word in PHP"];
        yield 'parent' => [['--slug=parent', '--name=Shop'], [], "slug 'parent' makes the namespace 'Parent', which is a reserved word in PHP"];
        yield 'reserved type name' => [['--slug=string', '--name=Shop'], [], "slug 'string' makes the namespace 'String', which is a reserved word in PHP"];
        yield 'namespace of TMSWP' => [['--slug=tmswp', '--name=Shop'], [], "slug 'tmswp' makes the namespace 'Tmswp', which is the namespace of TMSWP"];
        yield 'name closing a comment' => [['--slug=shop', '--name=Shop */ Tools'], [], "invalid project name 'Shop */ Tools'"];
        yield 'name with a newline' => [['--slug=shop', "--name=Shop\nTools"], [], "invalid project name 'Shop\nTools'"];
        yield 'blank name' => [['--slug=shop', '--name= '], [], "invalid project name ' '"];
        yield 'argument given twice' => [['--slug=shop', '--name=Shop', '--slug=tools'], [], '--slug is given more than once'];
        yield 'unknown argument' => [['--slug=shop', '--name=Shop', '--namespace=Shop'], [], 'unknown argument: --namespace=Shop'];
    }

    public function testRefusesToOverwriteAnExistingMainFile(): void
    {
        $project = $this->copyStarter('plugin', 'shop');
        file_put_contents($project . '/shop.php', "<?php\n");
        $before = $this->snapshot($project);

        $result = $this->runSetup($project, ['--slug=shop', '--name=Shop']);

        self::assertSame(1, $result['code']);
        self::assertStringStartsWith('ERROR: cannot rename plugin.php: ', $result['stderr']);
        self::assertStringEndsWith("/shop/shop.php already exists\n", $result['stderr']);
        self::assertSame($before, $this->snapshot($project));
    }

    /**
     * create-projectを--prefer-sourceで実行すると、読み取り専用リポジトリのclose-pull-request.yml(monorepoのsplit.ymlが
     * 同期の前に置く)が作ったプロジェクトに入る。setup.phpはそのファイルだけを消し、.github/のほかのファイルは残す。
     */
    #[DataProvider('starterKinds')]
    public function testRemovesOnlyTheWorkflowThatClosesPullRequests(string $kind): void
    {
        $project = $this->copyStarter($kind, 'client-' . $kind);
        $workflows = $project . '/.github/workflows';
        $written = self::withoutPatchworkStreamWrapper(static fn(): bool => mkdir($workflows, 0775, true)
            && file_put_contents($workflows . '/close-pull-request.yml', "name: Close Pull Request\n") !== false
            && file_put_contents($workflows . '/ci.yml', "name: CI\n") !== false
            && file_put_contents($project . '/.github/dependabot.yml', "version: 2\n") !== false);
        self::assertTrue($written);

        $result = $this->runSetup($project, ['--slug=client-' . $kind, '--name=Client']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileDoesNotExist($workflows . '/close-pull-request.yml');
        self::assertSame("name: CI\n", $this->read($workflows . '/ci.yml'));
        self::assertSame("version: 2\n", $this->read($project . '/.github/dependabot.yml'));
    }

    /**
     * スターター(monorepo)と、--prefer-distで作ったプロジェクトには.github/が無い。setup.phpは失敗せず、.github/を作らない。
     */
    #[DataProvider('starterKinds')]
    public function testSucceedsWithoutTheWorkflowThatClosesPullRequests(string $kind): void
    {
        $project = $this->copyStarter($kind, 'client-' . $kind);
        self::assertDirectoryDoesNotExist($project . '/.github');

        $result = $this->runSetup($project, ['--slug=client-' . $kind, '--name=Client']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertDirectoryDoesNotExist($project . '/.github');
    }

    /**
     * Composerは依存を入れてオートローダを作った後でpost-create-project-cmdを実行するため、setup.phpの後で
     * 書き換えた名前空間のオートローダを作り直す。
     */
    #[DataProvider('starterKinds')]
    public function testCreateProjectRebuildsTheAutoloaderAfterSetup(string $kind): void
    {
        $composer = json_decode($this->read(\dirname(__DIR__, 2) . "/starters/{$kind}/composer.json"), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);
        self::assertIsArray($composer['scripts'] ?? null);
        self::assertSame(['@php bin/setup.php', '@composer dump-autoload'], $composer['scripts']['post-create-project-cmd'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function starterKinds(): iterable
    {
        yield 'theme' => ['theme'];
        yield 'plugin' => ['plugin'];
    }

    public function testSetupScriptsOfBothStartersAndTheDocumentAreTheSame(): void
    {
        $root = \dirname(__DIR__, 2);
        $theme = $this->read($root . '/starters/theme/bin/setup.php');
        $document = $this->read($root . '/docs/11-ai-docs.md');

        self::assertSame($theme, $this->read($root . '/starters/plugin/bin/setup.php'));
        self::assertMatchesRegularExpression('/^### 10\.2 bin\/setup\.php\(全文\)\n\n```php\n/m', $document);
        self::assertSame(1, preg_match('/^### 10\.2 bin\/setup\.php\(全文\)\n\n```php\n(.*?)^```$/ms', $document, $matches));
        self::assertSame($theme, $matches[1]);
    }

    /**
     * スターターを一時ディレクトリの`<name>/`に写す(vendor/・node_modules/は除く)。
     */
    private function copyStarter(string $kind, string $name): string
    {
        $source = \dirname(__DIR__, 2) . '/starters/' . $kind;
        $destination = $this->createTempDirectory('starter-setup') . '/' . $name;

        self::withoutPatchworkStreamWrapper(static function () use ($source, $destination): void {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                    static fn(SplFileInfo $current): bool => !\in_array($current->getFilename(), ['vendor', 'node_modules'], true),
                ),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            mkdir($destination);

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo) {
                    continue;
                }

                $target = $destination . substr($file->getPathname(), \strlen($source));

                if ($file->isDir()) {
                    mkdir($target);
                } elseif (!copy($file->getPathname(), $target)) {
                    throw new RuntimeException("cannot copy {$file->getPathname()}");
                }
            }
        });

        return $destination;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runSetup(string $project, array $arguments, array $environment = []): array
    {
        $process = proc_open(
            [PHP_BINARY, $project . '/bin/setup.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $project,
            $this->environment($environment),
        );

        if (!\is_resource($process)) {
            self::fail('setup.phpを起動できません。');
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * setup.phpの入力(TMSWP_SETUP_*)とComposerから実行されたことの印(COMPOSER_BINARY)は、テストが渡すものだけにする。
     *
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    private function environment(array $environment): array
    {
        $inherited = array_filter(
            getenv(),
            static fn(string $name): bool => !str_starts_with($name, 'TMSWP_SETUP_') && $name !== 'COMPOSER_BINARY',
            ARRAY_FILTER_USE_KEY,
        );

        return [...$inherited, ...$environment];
    }

    /**
     * 受け取った引数を$recordにJSONで書き、$exitCodeで終わるComposerの代わりのスクリプトを作る。
     */
    private function writeFakeComposer(string $dir, string $record, int $exitCode): string
    {
        $path = $dir . '/fake-composer.php';
        file_put_contents($path, implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'file_put_contents(' . var_export($record, true) . ', json_encode(array_slice($argv, 1)));',
            "exit({$exitCode});",
            '',
        ]));

        return $path;
    }

    /**
     * プロジェクトのvendor/bin/php-cs-fixerに、$exitCodeで終わる代わりのスクリプトを置く。受け取った引数・作業ディレクトリと、
     * 実行の時点で、ほかのファイル(app/)とcomposer.jsonが書き換え済みかを、$recordにJSONで書く。
     */
    private function writeFakeFixer(string $project, string $record, int $exitCode): void
    {
        if (!is_dir($project . '/vendor/bin')) {
            mkdir($project . '/vendor/bin', 0o777, true);
        }

        file_put_contents($project . '/vendor/bin/php-cs-fixer', implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'file_put_contents(' . var_export($record, true) . ', json_encode([',
            "    'arguments' => array_slice(\$argv, 1),",
            "    'cwd' => getcwd(),",
            "    'rewritten' => str_contains((string) file_get_contents('app/Hook/PluginSetupHook.php'), 'namespace ShopTools\\\\Hook;'),",
            "    'composerRewritten' => str_contains((string) file_get_contents('composer.json'), 'ShopTools'),",
            ']));',
            "exit({$exitCode});",
            '',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function composerJson(string $project): array
    {
        $composer = json_decode($this->read($project . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        $result = [];

        foreach ($composer as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * @return array<string, string> 相対パス => 内容のハッシュ
     */
    private function snapshot(string $project): array
    {
        $paths = $this->files($project);

        return self::withoutPatchworkStreamWrapper(static fn(): array => array_map(
            static fn(string $path): string => (string) hash_file('sha256', $path),
            $paths,
        ));
    }

    /**
     * @param list<string> $needles
     *
     * @return list<string> いずれかを含むファイルの相対パス
     */
    private function filesContaining(string $project, array $needles): array
    {
        $found = [];

        foreach ($this->files($project) as $relativePath => $path) {
            $contents = $this->read($path);

            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    $found[] = "{$relativePath} ({$needle})";
                }
            }
        }

        return $found;
    }

    private function assertPhpFilesAreValid(string $project): void
    {
        foreach ($this->files($project) as $relativePath => $path) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }

            $output = [];
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
            self::assertSame(0, $code, $relativePath . ': ' . implode("\n", $output));
        }
    }

    /**
     * @return array<string, string> 相対パス => 絶対パス(名前順)
     */
    private function files(string $project): array
    {
        $files = self::withoutPatchworkStreamWrapper(static function () use ($project): array {
            $files = [];

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $files[substr($file->getPathname(), \strlen($project) + 1)] = $file->getPathname();
                }
            }

            return $files;
        });

        ksort($files);

        return $files;
    }

    private function read(string $path): string
    {
        $contents = self::withoutPatchworkStreamWrapper(static fn(): string|false => file_get_contents($path));

        if ($contents === false) {
            throw new RuntimeException("cannot read {$path}");
        }

        return $contents;
    }

    /**
     * $actionを、Patchworkのstream wrapperを外した標準のファイルのstream wrapperで実行する。
     *
     * Patchwork(Brain Monkeyが読み込む)のstream wrapperは、ファイル操作のたびにstream factoryのresourceを
     * プロセスの終わりまで残す(tmswp/testingのUsesTempDirectories::deleteDirectory()を参照)。スターターを
     * 複製して読むこのテストではそれが積み上がるため、その間だけ、resourceを解放する手順で標準のwrapperに戻す。
     * $actionの中ではPHPのファイルを読み込まない(読み込んだファイルはPatchworkの書き換えを受けない)。ファイル・
     * ディレクトリのハンドルは$actionの中で開く(外で開いたハンドルを読むと、Patchworkがwrapperを付け直す)。
     *
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    private static function withoutPatchworkStreamWrapper(callable $action): mixed
    {
        // PatchworkはUnitTestCaseのsetUp()(Brain Monkey)が読み込む
        if (!class_exists(PatchworkStream::class, false)) {
            self::fail('Patchwork is not loaded');
        }

        try {
            if (!stream_wrapper_unregister('file') || !stream_wrapper_restore('file')) {
                self::fail('cannot remove the stream wrapper of Patchwork');
            }

            return $action();
        } finally {
            PatchworkStream::wrap();
        }
    }
}
