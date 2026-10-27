<?php

declare(strict_types=1);

/*
 * composer create-projectで作ったテーマ・プラグインを、案件のスラッグで初期化する。
 *
 * create-projectのpost-create-project-cmdが1回だけ実行し、最後に自分(bin/setup.php)を消す。Composerは依存を入れて
 * オートローダを作った後でこれを実行するため、post-create-project-cmdは続けて`composer dump-autoload`を実行し、
 * 書き換えた名前空間でオートローダを作り直す(Composerはスクリプトの一覧を読み込み済みのため、ここでcomposer.jsonから
 * post-create-project-cmdを消しても続きは実行される)。
 * テーマとプラグインのスターターで同じ内容で、Composerのvendor/には依存しない(PHPの標準関数だけを使う)。
 *
 * 値は、引数(--slug=・--name=)、環境変数(TMSWP_SETUP_SLUG・TMSWP_SETUP_NAME)、対話の入力の順に探す。
 * 対話の入力は標準入力が端末のときだけ使い、そうでなければ(CI・エージェント)、値が無い時点でエラーで止まる。
 */

const SETUP_SLUG_ENV = 'TMSWP_SETUP_SLUG';
const SETUP_NAME_ENV = 'TMSWP_SETUP_NAME';
/** スターターのブロック名の名前空間。スラッグから作るブロックの名前空間に書き換える */
const SETUP_STARTER_BLOCK_NAMESPACE = 'tmswp-starter';
/**
 * 生成した名前空間に使えないPHPの予約語(PHPのマニュアルのList of Keywordsと、Other reserved words)。
 * スラッグから作れない語(`_`を含む`__halt_compiler`・`include_once`・`require_once`と、2語の`yield from`)は除く
 */
const SETUP_RESERVED_WORDS = [
    'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue',
    'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
    'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach',
    'function', 'global', 'goto', 'if', 'implements', 'include', 'instanceof', 'insteadof', 'interface', 'isset',
    'list', 'match', 'namespace', 'new', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly',
    'require', 'return', 'self', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
    'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never',
    'resource', 'numeric',
];
/** 書き換えの対象から外すディレクトリ(プロジェクトのルートからの相対パス) */
const SETUP_SKIPPED_DIRECTORIES = ['.git', 'node_modules', 'vendor'];

try {
    setupProject(dirname(__DIR__), commandLineArguments());
} catch (InvalidArgumentException | RuntimeException $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}

/**
 * 値を決めて検査し、すべて揃ってからプロジェクトを書き換える。
 *
 * @param list<string> $arguments
 *
 * @throws InvalidArgumentException 引数・入力の値が不正な場合
 * @throws RuntimeException ファイルの読み書きに失敗した場合
 */
function setupProject(string $root, array $arguments): void
{
    $options = parseArguments($arguments);
    $slug = $options['slug'] ?? environmentValue(SETUP_SLUG_ENV);
    $name = $options['name'] ?? environmentValue(SETUP_NAME_ENV);
    $interactive = stream_isatty(STDIN);

    if (!$interactive && ($slug === null || $name === null)) {
        $missing = implode(' and ', array_keys(array_filter(['slug' => $slug === null, 'name' => $name === null])));

        throw new InvalidArgumentException("the project {$missing} must be given without a terminal (bin/setup.php does not ask and has no default): "
            . 'pass --slug=<slug> --name=<name>, or set ' . SETUP_SLUG_ENV . ' and ' . SETUP_NAME_ENV);
    }

    $slug ??= prompt('Project slug', isSlug(basename($root)) ? basename($root) : null);
    assertSlug($slug);
    $namespace = namespaceFromSlug($slug);
    $name ??= prompt('Project name', ucwords(str_replace(['-', '_'], ' ', $slug)));
    assertName($name);

    $composerFile = $root . '/composer.json';
    $composer = readComposerJson($composerFile);
    $starterNamespace = starterNamespace($composer);
    $pluginFile = $root . '/plugin.php';
    $renamedPluginFile = $root . '/' . $slug . '.php';

    if (is_file($pluginFile) && $pluginFile !== $renamedPluginFile && file_exists($renamedPluginFile)) {
        throw new RuntimeException("cannot rename plugin.php: {$renamedPluginFile} already exists");
    }

    $replacements = [
        '{{project_name}}' => $name,
        '{{project_slug}}' => $slug,
        "'/plugin.php'" => "'/{$slug}.php'",
    ];
    $patterns = [
        // 名前空間は識別子の全体だけを置き換える(別の識別子の一部は変えない)
        '/(?<![A-Za-z0-9_])' . $starterNamespace . '(?![A-Za-z0-9_])/' => $namespace,
        '#(?<![a-z0-9-])' . SETUP_STARTER_BLOCK_NAMESPACE . '/#' => str_replace('_', '-', $slug) . '/',
        // README.mdのスターターの作り方とセットアップの節は、作ったプロジェクトには残さない
        '#<!-- tmswp-starter-setup -->.*?<!-- /tmswp-starter-setup -->\n*#s' => '',
    ];

    // 書き換えた内容をすべて作ってから書き込む。composer.jsonは最後に書くため、途中で失敗しても、
    // composer.jsonがスターターの名前空間のまま残り、もう一度実行すれば残りを書き換えられる
    $rewrites = [];

    foreach (projectFiles($root) as $file) {
        $contents = readTextFile($file);
        $updated = rewriteContents($file, $contents, $replacements, $patterns);

        if ($updated !== $contents) {
            $rewrites[$file] = $updated;
        }
    }

    // npmのパッケージの名前をスラッグにする(package.jsonには無いため足す)。package-lock.jsonの名前は、スターターを
    // 作ったディレクトリの名前のまま残っているため
    foreach (['package.json', 'package-lock.json'] as $npmFileName) {
        $npmFile = $root . '/' . $npmFileName;

        if (is_file($npmFile)) {
            $npmSource = $rewrites[$npmFile] ?? readTextFile($npmFile);
            $rewrites[$npmFile] = encodeJson($npmFile, withNpmName($npmFile, decodeJsonObject($npmFile, $npmSource), $slug), $npmSource);
        }
    }

    $kind = is_file($root . '/style.css') ? 'theme' : 'plugin';
    $composerSource = $rewrites[$composerFile] ?? readTextFile($composerFile);
    $composer = decodeJsonObject($composerFile, $composerSource);
    $composer->name = 'local/' . $slug;
    // スターターの説明(Starter theme for ...)は、作ったプロジェクトには当てはまらない
    $composer->description = "{$name} (WordPress {$kind})";
    $composer->license = 'proprietary';
    // Composerのオートローダのクラス名(ComposerAutoloaderInit<suffix>)の接尾辞を、名前空間にする。既定の接尾辞は、
    // create-projectが依存を入れた時点のcomposer.lockのハッシュ(同じ版のスターターから作ったプロジェクトどうしで同じ)で、
    // 同じスターターから作ったプラグインを同じサイトで有効にすると、クラスの重複で止まるため
    if (!isset($composer->config) || !$composer->config instanceof stdClass) {
        $composer->config = new stdClass();
    }

    $composer->config->{'autoloader-suffix'} = $namespace;

    if (isset($composer->scripts) && $composer->scripts instanceof stdClass) {
        unset($composer->scripts->{'post-create-project-cmd'});
    }

    $composerJson = encodeJson($composerFile, $composer, $composerSource);
    unset($rewrites[$composerFile]);

    foreach ($rewrites as $file => $contents) {
        writeTextFile($file, $contents);
    }

    if (is_file($pluginFile) && $pluginFile !== $renamedPluginFile && !rename($pluginFile, $renamedPluginFile)) {
        throw new RuntimeException("cannot rename {$pluginFile} to {$renamedPluginFile}");
    }

    // スターターの探索結果ファイルは、書き換える前の名前空間とブロック名で作ったもので、もう使えない
    $discoveryFile = $root . '/tmswp-discovery.php';

    if (is_file($discoveryFile) && !unlink($discoveryFile)) {
        throw new RuntimeException("cannot delete {$discoveryFile}");
    }

    writeTextFile($composerFile, $composerJson);

    if (!unlink(__FILE__)) {
        throw new RuntimeException('cannot delete ' . __FILE__);
    }

    if (scandir(__DIR__) === ['.', '..'] && !rmdir(__DIR__)) {
        throw new RuntimeException('cannot delete ' . __DIR__);
    }

    $lockStep = refreshLockFile($root)
        ? ''
        : "\n               Then run `composer update --lock`: composer.lock records the hash of the old composer.json.";
    $build = $kind === 'theme' ? 'npm ci && npm run build' : 'composer discover';
    fwrite(STDOUT, <<<TEXT

          Initialized {$name} (slug: {$slug}, namespace: {$namespace}\\).

          Next steps:
            1. Run `composer dump-autoload` so that the autoloader uses the new namespace
               (`composer install` if the dependencies are not installed yet).
               composer create-project runs `composer dump-autoload` right after this script.{$lockStep}
            2. Work on a WordPress site with WP_DEBUG enabled (a development environment): tmswp-discovery.php
               does not exist yet, and without WP_DEBUG the kernel fails to boot until it exists.
               Activate this {$kind}, then run `{$build}` where WP-CLI can reach the site.
               It runs `wp tmswp discover`, which writes tmswp-discovery.php. Commit that file.
            3. Run `composer qa`.
            4. Read AGENTS.md before you add code.

        TEXT);
}

/**
 * @throws RuntimeException コマンドラインから実行していない場合
 *
 * @return list<string>
 */
function commandLineArguments(): array
{
    $argv = $_SERVER['argv'] ?? null;

    if (!is_array($argv)) {
        throw new RuntimeException('run bin/setup.php from the command line: php bin/setup.php');
    }

    $arguments = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!is_string($argument)) {
            throw new RuntimeException('command line arguments must be strings');
        }

        $arguments[] = $argument;
    }

    return $arguments;
}

/**
 * @param list<string> $arguments
 *
 * @throws InvalidArgumentException 知らない引数がある場合
 *
 * @return array{slug: string|null, name: string|null}
 */
function parseArguments(array $arguments): array
{
    $options = ['slug' => null, 'name' => null];

    foreach ($arguments as $argument) {
        if (preg_match('/^--(slug|name)=(.*)$/s', $argument, $matches) !== 1) {
            throw new InvalidArgumentException("unknown argument: {$argument}. Usage: php bin/setup.php [--slug=<slug>] [--name=<name>]");
        }

        if ($options[$matches[1]] !== null) {
            throw new InvalidArgumentException("--{$matches[1]} is given more than once");
        }

        $options[$matches[1]] = $matches[2];
    }

    return $options;
}

/**
 * 環境変数の値を返す。設定されていなければnullを返す。
 */
function environmentValue(string $name): ?string
{
    $value = getenv($name);

    return $value === false ? null : $value;
}

/**
 * 端末で値を尋ねる。空の入力は既定値(無ければ空の文字列)にする。
 *
 * @throws RuntimeException 端末から読めない場合
 */
function prompt(string $label, ?string $default): string
{
    fwrite(STDOUT, $default === null ? "{$label}: " : "{$label} [{$default}]: ");
    $line = fgets(STDIN);

    if ($line === false) {
        throw new RuntimeException("cannot read the {$label} from the terminal");
    }

    $input = trim($line);

    return $input === '' ? (string) $default : $input;
}

/**
 * スラッグの形(英小文字・数字で、英字で始まり、語を1つの'-'か'_'でつなぐ)かを返す。
 * composer.jsonのname(local/<slug>)がComposerの名前の規則に合う形に限る。
 */
function isSlug(string $slug): bool
{
    return preg_match('/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/D', $slug) === 1;
}

/**
 * @throws InvalidArgumentException スラッグの形が違う場合
 */
function assertSlug(string $slug): void
{
    if (!isSlug($slug)) {
        throw new InvalidArgumentException("invalid slug '{$slug}': use lowercase letters and digits starting with a letter, joining words with a single '-' or '_'");
    }
}

/**
 * スラッグを'-'と'_'で区切り、各語の先頭を大文字にして連結する(例: my-shop-plugin → MyShopPlugin)。
 *
 * @throws InvalidArgumentException 名前空間がPHPの予約語か、TMSWPと同じ場合
 */
function namespaceFromSlug(string $slug): string
{
    $namespace = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
    $lower = strtolower($namespace);

    if (in_array($lower, SETUP_RESERVED_WORDS, true)) {
        throw new InvalidArgumentException("slug '{$slug}' makes the namespace '{$namespace}', which is a reserved word in PHP; choose another slug");
    }

    // PHPの名前空間は大文字と小文字を区別しないため、TmswpもTMSWPの名前空間と同じになる
    if ($lower === 'tmswp') {
        throw new InvalidArgumentException("slug '{$slug}' makes the namespace '{$namespace}', which is the namespace of TMSWP; choose another slug");
    }

    return $namespace;
}

/**
 * 名前はstyle.cssとプラグインのヘッダのコメントに入るため、コメントを閉じる'*\/'と制御文字を拒む。
 *
 * @throws InvalidArgumentException 名前が不正な場合
 */
function assertName(string $name): void
{
    if (trim($name) === '' || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || str_contains($name, '*/')) {
        throw new InvalidArgumentException("invalid project name '{$name}': it must not be empty or contain control characters or '*/'");
    }
}

/**
 * @throws RuntimeException 読めない・JSONのオブジェクトでない場合
 */
function readComposerJson(string $file): stdClass
{
    return decodeJsonObject($file, readTextFile($file));
}

/**
 * @throws RuntimeException JSONのオブジェクトでない場合
 */
function decodeJsonObject(string $file, string $json): stdClass
{
    try {
        $composer = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("cannot parse {$file}: {$exception->getMessage()}", 0, $exception);
    }

    if (!$composer instanceof stdClass) {
        throw new RuntimeException("{$file} is not a JSON object");
    }

    return $composer;
}

/**
 * composer.jsonのautoloadのpsr-4でapp/に割り当てた名前空間(スターターの名前空間)を返す。
 *
 * @throws RuntimeException 見つからない場合
 */
function starterNamespace(stdClass $composer): string
{
    $psr4 = isset($composer->autoload) && $composer->autoload instanceof stdClass ? ($composer->autoload->{'psr-4'} ?? null) : null;
    $namespaces = [];

    if ($psr4 instanceof stdClass) {
        foreach (get_object_vars($psr4) as $prefix => $path) {
            if ($path === 'app/' && preg_match('/^([A-Za-z][A-Za-z0-9]*)\\\\$/', (string) $prefix, $matches) === 1) {
                $namespaces[] = $matches[1];
            }
        }
    }

    if (count($namespaces) !== 1) {
        throw new RuntimeException('composer.json must map exactly one namespace to app/ in autoload.psr-4; bin/setup.php has already run or composer.json was changed');
    }

    return $namespaces[0];
}

/**
 * 書き換えの対象のファイル(vendor/・node_modules/・.git/と、このファイルを除く)を返す。
 *
 * @return list<string>
 */
function projectFiles(string $root): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $current): bool => !$current->isDir()
                || !in_array(substr($current->getPathname(), strlen($root) + 1), SETUP_SKIPPED_DIRECTORIES, true),
        ),
    );
    $files = [];

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getPathname() !== __FILE__) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * テキストのファイルの内容の文字列を置き換えた結果を返す。NULを含むファイル(バイナリ)はそのまま返す。
 *
 * @param array<string, string> $replacements 文字列 => 置き換え後
 * @param array<string, string> $patterns 正規表現 => 置き換え後
 *
 * @throws RuntimeException 置き換えに失敗した場合
 */
function rewriteContents(string $file, string $contents, array $replacements, array $patterns): string
{
    if (str_contains($contents, "\0")) {
        return $contents;
    }

    $updated = preg_replace(array_keys($patterns), array_values($patterns), strtr($contents, $replacements));

    if ($updated === null) {
        throw new RuntimeException("cannot rewrite {$file}: " . preg_last_error_msg());
    }

    return $updated;
}

/**
 * composer create-projectが作ったcomposer.lockに、書き換えたcomposer.jsonのハッシュを記録し直す(パッケージの版は
 * 変えない)。Composerから実行されたとき(COMPOSER_BINARYがある)だけ行い、行ったかどうかを返す。
 * composer.lockが無い(create-projectの--no-install)場合は、記録し直すものが無いためtrueを返す。
 *
 * @throws RuntimeException Composerが失敗した場合
 */
function refreshLockFile(string $root): bool
{
    if (!is_file($root . '/composer.lock')) {
        return true;
    }

    $composerBinary = getenv('COMPOSER_BINARY');

    if ($composerBinary === false || $composerBinary === '') {
        return false;
    }

    $process = proc_open(
        [PHP_BINARY, $composerBinary, 'update', '--lock', '--no-install', '--no-interaction', '--working-dir=' . $root],
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
    );

    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('cannot update the hash in composer.lock; run `composer update --lock`');
    }

    return true;
}

/**
 * npmのパッケージの名前を$nameにしたものを返す。nameは先頭に置く(npmが書く順と同じ)。package-lock.jsonは、先頭の
 * nameと、ルートのパッケージ(packages[""])のnameの両方を変える(npmは、package.jsonのnameを両方に書く)。
 *
 * @throws RuntimeException package-lock.jsonにルートのパッケージが無い場合
 */
function withNpmName(string $file, stdClass $package, string $name): stdClass
{
    $renamed = withFirstProperty($package, 'name', $name);

    if (basename($file) !== 'package-lock.json') {
        return $renamed;
    }

    $packages = isset($renamed->packages) && $renamed->packages instanceof stdClass ? get_object_vars($renamed->packages) : [];
    $rootPackage = $packages[''] ?? null;

    if (!$rootPackage instanceof stdClass) {
        throw new RuntimeException("{$file} has no root package (packages[\"\"]); regenerate it with npm 7 or later");
    }

    $packages[''] = withFirstProperty($rootPackage, 'name', $name);
    $renamed->packages = (object) $packages;

    return $renamed;
}

/**
 * $keyを$valueにして、先頭に置いたオブジェクトを返す(ほかのプロパティの順は保つ)。
 */
function withFirstProperty(stdClass $object, string $key, string $value): stdClass
{
    $properties = get_object_vars($object);
    unset($properties[$key]);

    return (object) ([$key => $value] + $properties);
}

/**
 * JSONのオブジェクトを、元のファイルの内容($source)と同じ字下げで書いたJSON(末尾に改行)を返す。
 *
 * @throws RuntimeException JSONにできない場合
 */
function encodeJson(string $file, stdClass $data, string $source): string
{
    try {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("cannot encode {$file}: {$exception->getMessage()}", 0, $exception);
    }

    return reindentJson($file, $json, jsonIndent($source)) . "\n";
}

/**
 * JSONの1段の字下げ(最初に字下げした行の空白)を返す。字下げが無ければ4つの空白を返す。
 */
function jsonIndent(string $json): string
{
    return preg_match('/^([ \t]+)\S/m', $json, $matches) === 1 ? $matches[1] : '    ';
}

/**
 * json_encode()のJSON_PRETTY_PRINT(4つの空白)の字下げを、$indentに変える。
 *
 * @throws RuntimeException 置き換えに失敗した場合
 */
function reindentJson(string $file, string $json, string $indent): string
{
    $reindented = preg_replace_callback(
        '/^(?: {4})+/m',
        static fn(array $matches): string => str_repeat($indent, intdiv(strlen($matches[0]), 4)),
        $json,
    );

    if ($reindented === null) {
        throw new RuntimeException("cannot indent {$file}: " . preg_last_error_msg());
    }

    return $reindented;
}

/**
 * @throws RuntimeException 読めない場合
 */
function readTextFile(string $file): string
{
    $contents = file_get_contents($file);

    if ($contents === false) {
        throw new RuntimeException("cannot read {$file}");
    }

    return $contents;
}

/**
 * @throws RuntimeException 書けない場合
 */
function writeTextFile(string $file, string $contents): void
{
    if (file_put_contents($file, $contents) === false) {
        throw new RuntimeException("cannot write {$file}");
    }
}
