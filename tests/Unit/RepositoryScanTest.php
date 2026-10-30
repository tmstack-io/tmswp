<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit;

use FilesystemIterator;
use Patchwork\CodeManipulation\Stream as PatchworkStream;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\SettingsPage\SettingsPages;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Thumbnail\Thumbnail;

/**
 * 「〜が無い」という形の受け入れ条件(手作業のgrepと目視で確かめていたもの)を、リポジトリのファイルの走査で確かめる。
 * 後の変更で条件が崩れたとき、CIのユニットテストで検出するためである。
 *
 * - PHPのソースは、コメントや文字列に反応しないよう、PhpToken::tokenize()のトークンで調べる(actions/規約の痕跡の検査を除く)。
 * - 文書・設定は行単位の正規表現で調べる。
 * - 調べるファイルとディレクトリ(`packages/*\/src`等)の列挙は、gitの追跡するファイル(`git ls-files`)のうち作業ツリーに
 *   あるものから選び(globは使わない)、内容は作業ツリーのものを読む。.DS_Store・作業の道具の状態のファイル・キャッシュ等の
 *   追跡外のファイルで、手元とCIの結果とアサーションの数が変わらないようにするためである。新しく作ったファイルと
 *   ディレクトリは、`git add`で追跡した時点で対象になる。
 * - actions/規約のディレクトリの有無の検査だけは、ディレクトリをvendor/・node_modules/・.git/を除いて走査する
 *   (gitはディレクトリを追跡しない)。
 * - このファイル自身は禁止の語を文字列で持つため、走査の対象から外す。
 * - 追跡するファイルの一覧とディレクトリごとの絞り込みの結果はクラスの中でキャッシュし、テストの間で同じ走査を繰り返さない。
 *   走査とファイルの読み込みは、Patchworkのstream wrapperを外して行う(withoutPatchworkStreamWrapper())。
 */
final class RepositoryScanTest extends UnitTestCase
{
    /** ディレクトリの走査で下りないディレクトリの名前 */
    private const array SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '.git'];

    /**
     * trackedFiles()の結果。gitの追跡するファイルのうち作業ツリーにあるもの(ルートからの相対パス。昇順。このファイル自身を除く)。
     *
     * @var list<string>|null
     */
    private static ?array $trackedFiles = null;

    /**
     * filesUnder()の走査の結果。ルートからの相対のディレクトリ => その下の追跡するファイルの絶対パス(昇順。このファイル自身を除く)。
     *
     * @var array<string, list<string>>
     */
    private static array $filesUnderCache = [];

    /** 「使わない」旨の注記の行。npx等を、禁止の対象として名前に挙げている行で、呼び出しではない */
    private const string NEGATION_LINE = '/使いません|使わない|使用しない|解決しない/u';

    /** YAMLのステップ名の行(例: `- name: Show wp-env logs on failure`)。コマンドではない */
    private const string YAML_NAME_LINE = '/^\s*(?:-\s+)?name:\s/';

    /** シェルのechoとprintfの文言の行(例: `echo 'acf-pro-integration: wp-env stop failed' >&2`)。コマンドではない */
    private const string ECHO_LINE = '/^\s*(?:echo|printf)\s/';

    /**
     * コマンド名`wp-env`を名前解決する呼び出し。(a)npx(オプションを挟んでもよい)の後にwp-envか@wordpress/env、
     * (b)npm exec・npm xの後にwp-envか@wordpress/env、(c)コマンドの位置(行頭・空白・;・&・|・(・バッククォートの直後)の
     * `wp-env <サブコマンド>`。`node node_modules/@wordpress/env/bin/wp-env start`のようにパスで呼ぶものは、wp-envの直前が
     * `/`のため(c)に当たらない。
     */
    private const array WP_ENV_BY_NAME = [
        '/(?<![\w-])npx(?:\s+-\S+(?:\s+[^\s-]\S*)?)*?\s+["\'`]?(?:wp-env|@wordpress\/env)(?![\w-])/',
        '/(?<![\w-])npm\s+(?:exec|x)(?:\s+-\S+(?:\s+[^\s-]\S*)?)*?\s+["\'`]?(?:wp-env|@wordpress\/env)(?![\w-])/',
        '/(?:^|[\s;&|(`])wp-env\s+(?:start|stop|run|clean|destroy|logs|install-path|reset)(?![\w-])/',
    ];

    /**
     * JavaScript(E2Eの補助等)で、子プロセスのコマンドの名前として`npx`・`wp-env`・`@wordpress/env`を文字列で渡す呼び出し
     * (`execFileSync('npx', ['wp-env', ...])`・`spawn('wp-env', ...)`等)と、`npm`に`exec`・`x`を渡す呼び出し。
     * パス(`node_modules/@wordpress/env/bin/wp-env`)の文字列は、引用符の直後がパスの先頭のため当たらない。
     */
    private const array WP_ENV_BY_NAME_IN_JAVASCRIPT = [
        '/["\'`](?:npx|wp-env|@wordpress\/env)["\'`]/',
        '/["\'`]npm["\'`]\s*,\s*\[\s*["\'`](?:exec|x)["\'`]/',
    ];

    /** v0.5.0で削除したactions/規約の名前(docs/12のv0.5.0の受け入れ条件3) */
    private const array ACTIONS_CONVENTION_TERMS = ['ActionRouter', 'tms_var', 'tms-check', 'NoOutputRule', 'NoSet404Rule', 'phpstan-actions'];

    /**
     * v0.7.0で削除した、拡張の起動の静的なAPIの名前(CHANGELOGのv0.7.0の削除の節、docs/acceptance/v0.7.0.mdの検索式、
     * docs/12のv0.7.0の受け入れ条件1)。小文字で比べる。
     */
    private const array OLD_STATIC_API_NAMES = ['register', 'enable', 'disable', 'boot', 'reset', 'isenabled', 'isregistered', 'registerpage', 'registermanagedkeys', 'config', 'init', 'setup'];

    /** 4つの拡張の入口のクラスと、旧APIを持っていたacf-bridgeの構成のクラスの短い名前 */
    private const array EXTENSION_CLASS_NAMES = ['AcfBridge', 'BridgeConfig', 'JaToolkit', 'SettingsPages', 'Thumbnail'];

    /** ファイルを書く関数(小文字) */
    private const array FILE_WRITING_FUNCTIONS = [
        'file_put_contents', 'fopen', 'fwrite', 'fputs', 'fputcsv', 'ftruncate', 'rename', 'mkdir', 'touch', 'copy', 'unlink',
        'rmdir', 'tempnam', 'tmpfile', 'symlink', 'link', 'chmod', 'move_uploaded_file', 'wp_mkdir_p', 'wp_tempnam', 'wp_upload_bits',
        'wp_filesystem',
    ];

    /** ファイルを書けるクラス(小文字) */
    private const array FILE_WRITING_CLASSES = ['splfileobject', 'spltempfileobject'];

    /** ディレクトリを列挙する関数(小文字) */
    private const array DIRECTORY_LISTING_FUNCTIONS = ['glob', 'scandir', 'opendir', 'readdir', 'dir', 'list_files'];

    /** ディレクトリを列挙するクラス(小文字) */
    private const array DIRECTORY_LISTING_CLASSES = ['directoryiterator', 'filesystemiterator', 'recursivedirectoryiterator', 'globiterator'];

    /**
     * 設計文書(docs/)と決定・規則の番号への参照。節の記号(`§`)、`docs/`、`NN-name.md`(設計文書のファイル名)、
     * 拡張子の無い設計文書の名前(`06-model`等)、`文書NN`、`D-NN`・`DR-NN`(決定の番号)、`RN`(設計文書の規則の番号)。
     * `-alpha-12345-src`・`BSD-3-Clause`・`PSR-3`のように、英数字か`-`の直後に続くものは当てない。
     */
    private const array DESIGN_REFERENCE_PATTERNS = [
        '/§/u',
        '#(?<![\w/.-])docs/#',
        '#tmstack-io/tmswp/(?:blob|tree)/#',
        '/(?<![\w.-])\d{2}-[a-z]+(?:-[a-z]+)*\.md\b/',
        '/(?<![\w-])\d{2}-(?:overview|architecture|kernel|entities|meta-options|form|model|support|extensions|acf-bridge|quality|ai-docs|implementation-plan)\b/',
        '/文書\s?\d{2}/u',
        '/(?<![\w-])DR?-\d+\b/',
        '/(?<![\w-])R\d{1,2}\b/',
    ];

    /**
     * rules/・README.mdの、設計文書へのMarkdownのリンク。リンクの文字列は、URLのタグの後のパス(`docs/…`)と同じにする。
     * 1つ目のグループはタグ(`vX.Y.Z`)。
     */
    private const string DESIGN_DOCUMENT_LINK = '#\[(docs/[^\]]*)\]\(https://github\.com/tmstack-io/tmswp/(?:blob|tree)/([^/)\s]+)/\1\)#';

    /**
     * docs/12のv0.1.0の受け入れ条件2: コマンド名`wp-env`をnpx等で名前解決する呼び出しが、composerスクリプト・CI・文書に
     * 残っていない。
     *
     * 対象は、ルート・examples/sample-site・starters/*のcomposer.jsonとpackage.jsonのscripts、.github/workflows/*.yml、
     * docs/*.md、README.md・AGENTS.md、packages/*のREADME.mdとrules/の.md、starters/*とexamples/sample-siteの直下の.md、
     * tools/のシェルスクリプト(.shと、shのシバンで始まるファイル)、E2EのJavaScript(tests/E2E/の.mjs・.jsと
     * playwright.config.mjs。E2Eの補助がwp-envを子プロセスで呼ぶため)である。JavaScriptは、コマンドの名前を文字列で
     * 渡す形(WP_ENV_BY_NAME_IN_JAVASCRIPT)も禁止する。
     * docs/12(実装計画)は手順の指示を含む文書のため対象にする。docs/acceptance/は、受け入れの確認の記録で、確かめた検索の
     * 式(`git grep -n "npx wp-env"`等)を証跡としてそのまま載せるため、対象にしない。
     * 許す行: 「使わない」旨の否定(使いません・使わない・使用しない・解決しない)を含む行、YAMLの`name:`の行、
     * シェルのecho・printfの行。
     */
    public function testWpEnvIsNotResolvedByCommandNameInScriptsCiOrDocuments(): void
    {
        $violations = [];

        foreach (self::wpEnvScanTargets() as $location => $lines) {
            foreach ($lines as $number => $line) {
                if (self::callsWpEnvByName($line)) {
                    $violations[] = "{$location}:{$number} {$line}";
                }
            }
        }

        $javaScripts = self::filesUnder('tests/E2E', static fn(string $path): bool => preg_match('/\.m?js\z/', $path) === 1);
        $javaScripts[] = self::root() . '/playwright.config.mjs';
        // E2Eの補助は、ルートのnode_modulesの@wordpress/envをパスで呼ぶ(この検査が空振りしていないことの確かめ)
        self::assertContains(self::root() . '/tests/E2E/support/wp-env.mjs', $javaScripts);

        foreach ($javaScripts as $file) {
            foreach (self::lines(self::read($file)) as $index => $line) {
                if (self::callsWpEnvByName($line) || (preg_match(self::NEGATION_LINE, $line) !== 1 && self::matchesAny(self::WP_ENV_BY_NAME_IN_JAVASCRIPT, $line))) {
                    $violations[] = self::relative($file) . ':' . ($index + 1) . " {$line}";
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * docs/12のv0.5.0の受け入れ条件3: actions/規約の痕跡(コード・設定・テスト・文書)が無い。
     *
     * 痕跡には文書とコメントでの言及を含むため、PHPのファイルもトークンではなく、ファイルの文字列で調べる。
     * 対象は、packages・starters・examples・tests・tools・bin・.githubの下のすべての追跡するテキストのファイル、ルートの設定
     * (composer.json・package.json・*.neon・*.dist・*.dist.php・monorepo-builder.php・playwright.config.mjs・.wp-env.json・
     * .gitattributes・.gitignore・.editorconfig)、docs/の.md(docs/12を除く)、README.md・AGENTS.mdである。
     * CHANGELOG.md・docs/12・docs/acceptance/は、削除したことと、その経緯の記録として名前を残す文書のため、対象にしない。
     */
    public function testNoTraceOfActionsConventionRemains(): void
    {
        $violations = [];

        foreach (self::actionsScanTargets() as $file) {
            $contents = self::read($file);

            if (str_contains($contents, "\0")) {
                // 画像・.mo等のバイナリのファイル
                continue;
            }

            foreach (self::lines($contents) as $index => $line) {
                foreach (self::ACTIONS_CONVENTION_TERMS as $term) {
                    if (str_contains($line, $term)) {
                        $violations[] = self::relative($file) . ':' . ($index + 1) . " {$term}";
                    }
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * docs/12のv0.5.0の受け入れ条件3: actions/規約のディレクトリ(`actions`という名前のディレクトリ)と、
     * packages/core/rules/actions.mdが無い。.githubは、GitHubの複合アクションの置き場(.github/actions/)が
     * actions/規約とは無関係のため対象にしない。
     */
    public function testNoActionsDirectoryOrRulesFileExists(): void
    {
        $found = [];

        foreach (['packages', 'starters', 'examples', 'tests', 'tools', 'bin', 'docs'] as $directory) {
            $path = self::root() . '/' . $directory;
            self::assertDirectoryExists($path);
            $directories = self::withoutPatchworkStreamWrapper(static function () use ($path): array {
                $directories = [];

                foreach (new RecursiveIteratorIterator(self::directoryIterator($path), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                    if ($entry instanceof SplFileInfo && $entry->isDir()) {
                        $directories[] = $entry->getPathname();
                    }
                }

                return $directories;
            });

            foreach ($directories as $entry) {
                if (strtolower(basename($entry)) === 'actions') {
                    $found[] = self::relative($entry);
                }
            }
        }

        self::assertSame([], $found);
        self::assertFileDoesNotExist(self::root() . '/packages/core/rules/actions.md');
    }

    /**
     * docs/12のv0.6.0の受け入れ条件5: 偽のACFを使うテストが無い(ACFのAPIの名前の関数と、ACF・acf_*という名前の
     * クラスを、テストのコードが定義しない)。
     *
     * 対象は、packages/*\/tests・tests・starters/*\/tests・examples/sample-site/testsのPHP(fixtureを含む)である。
     * クラスのメソッド(WpdbTestDouble::get_row()等、ACFと無関係のテストダブルのメソッド)は関数の定義に数えない。
     */
    public function testNoTestDefinesFakeAcfFunctionsOrClasses(): void
    {
        $violations = [];

        foreach (self::testPhpFiles() as $file) {
            $tokens = self::significantTokens($file);
            $classBodyDepths = [];
            $depth = 0;
            $pendingClassBody = false;

            foreach ($tokens as $index => $token) {
                $previous = $tokens[$index - 1] ?? null;
                $next = $tokens[$index + 1] ?? null;

                if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && !($previous?->is(T_DOUBLE_COLON) ?? false)) {
                    $pendingClassBody = true;

                    if ($next !== null && $next->is(T_STRING) && preg_match('/\Aacf(?:_\w+)?\z/i', $next->text) === 1) {
                        $violations[] = self::relative($file) . ":{$next->line} defines class {$next->text}";
                    }
                }

                if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    ++$depth;

                    if ($pendingClassBody && $token->text === '{') {
                        $classBodyDepths[] = $depth;
                        $pendingClassBody = false;
                    }
                } elseif ($token->text === '}') {
                    if ($classBodyDepths !== [] && end($classBodyDepths) === $depth) {
                        array_pop($classBodyDepths);
                    }

                    --$depth;
                }

                // クラスの本体の直下の関数はメソッド、`use function`は関数の取り込みで、どちらも関数の定義ではない
                if (!$token->is(T_FUNCTION) || ($previous?->is(T_USE) ?? false) || ($classBodyDepths !== [] && end($classBodyDepths) === $depth)) {
                    continue;
                }

                $name = $next !== null && $next->text === '&' ? ($tokens[$index + 2] ?? null) : $next;

                if ($name !== null && $name->is(T_STRING) && self::isAcfApiName($name->text)) {
                    $violations[] = self::relative($file) . ":{$name->line} defines function {$name->text}()";
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * docs/12のv0.6.0の受け入れ条件5: 偽のACFを使うテストが無い(Brain MonkeyのFunctions\when()・Functions\expect()・
     * Functions\stubs()で、ACFのAPIの名前の関数を差し替えない)。対象は上のテストと同じ。呼び出しの括弧の中の文字列の
     * リテラルのすべて(stubs()の配列のキーと値を含む)を調べる。
     */
    public function testNoTestStubsAcfFunctionsWithBrainMonkey(): void
    {
        $violations = [];

        foreach (self::testPhpFiles() as $file) {
            $tokens = self::significantTokens($file);

            foreach ($tokens as $index => $token) {
                if (
                    !$token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                    || preg_match('/(?:\A|\\\\)Functions\\\\(?:when|expect|stubs)\z/i', $token->text) !== 1
                    || ($tokens[$index + 1] ?? null)?->text !== '('
                ) {
                    continue;
                }

                $parentheses = 0;

                for ($i = $index + 1, $count = \count($tokens); $i < $count; ++$i) {
                    $argument = $tokens[$i];
                    $parentheses += match ($argument->text) {
                        '(' => 1,
                        ')' => -1,
                        default => 0,
                    };

                    if ($argument->is(T_CONSTANT_ENCAPSED_STRING) && self::isAcfApiName(substr($argument->text, 1, -1))) {
                        $violations[] = self::relative($file) . ":{$argument->line} {$token->text}({$argument->text})";
                    }

                    if ($parentheses === 0) {
                        break;
                    }
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * docs/12のv0.7.0の受け入れ条件1: 4つの拡張の入口のクラスが、ExtensionInterfaceを実装する(KernelConfigの
     * extensionsで渡し、Kernelがregister(Kernel)を呼ぶ)。
     *
     * @param class-string $class
     */
    #[DataProvider('extensionEntryClasses')]
    public function testExtensionEntryClassImplementsExtensionInterface(string $class): void
    {
        self::assertTrue((new ReflectionClass($class))->implementsInterface(ExtensionInterface::class));
    }

    /** @return iterable<string, array{class-string}> */
    public static function extensionEntryClasses(): iterable
    {
        yield 'acf-bridge' => [AcfBridge::class];
        yield 'settings-page' => [SettingsPages::class];
        yield 'thumbnail' => [Thumbnail::class];
        yield 'ja-toolkit' => [JaToolkit::class];
    }

    /**
     * docs/12のv0.7.0の受け入れ条件1: 4つの拡張のsrcのどのクラスにも、旧APIの名前(register・enable・disable・boot・
     * reset・isEnabled・isRegistered・registerPage・registerManagedKeys・config・init・setup)のpublic staticの
     * メソッドが無い。可視性を書かないstaticのメソッドもpublicとして数える。
     */
    public function testExtensionSourcesDeclareNoOldStaticApi(): void
    {
        $violations = [];

        foreach (['acf-bridge', 'settings-page', 'thumbnail', 'ja-toolkit'] as $package) {
            foreach (self::phpFilesUnder('packages/' . $package . '/src') as $file) {
                $tokens = self::significantTokens($file);

                foreach ($tokens as $index => $token) {
                    $name = $tokens[$index + 1] ?? null;

                    if (!$token->is(T_FUNCTION) || $name === null || !$name->is(T_STRING) || !\in_array(strtolower($name->text), self::OLD_STATIC_API_NAMES, true)) {
                        continue;
                    }

                    $static = false;
                    $public = true;

                    for ($i = $index - 1; $i >= 0 && $tokens[$i]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL]); --$i) {
                        $static = $static || $tokens[$i]->is(T_STATIC);
                        $public = $public && !$tokens[$i]->is([T_PROTECTED, T_PRIVATE]);
                    }

                    if ($static && $public) {
                        $violations[] = self::relative($file) . ":{$name->line} public static function {$name->text}()";
                    }
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * docs/12のv0.7.0の受け入れ条件1: packages・starters・examples/sample-site・testsのPHPに、旧APIの静的な呼び出し
     * (`SettingsPages::register(`・`JaToolkit::enable(`・`AcfBridge::boot(`・`Thumbnail::register(`等)が無い。
     *
     * 対象は、クラスの名前の最後の部分がAcfBridge・BridgeConfig・JaToolkit・SettingsPages・Thumbnailで、メソッドの名前が
     * 旧APIの名前のものである。今のsrcにあるこれらのクラスの正当なpublic staticのメソッド(forKernel()・current()・
     * assertSupportedAcfVersion()・markManagedKey()・normalizeOptions()等)は旧APIの名前とぶつからない(ぶつかる名前の
     * public staticのメソッドが無いことは、上のtestExtensionSourcesDeclareNoOldStaticApiが確かめる)ため、名前で判定できる。
     */
    public function testNoOldStaticApiIsCalled(): void
    {
        $violations = [];

        foreach (['packages', 'starters', 'examples/sample-site', 'tests'] as $directory) {
            foreach (self::phpFilesUnder($directory) as $file) {
                $tokens = self::significantTokens($file);

                foreach ($tokens as $index => $token) {
                    $method = $tokens[$index + 2] ?? null;

                    if (
                        $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                        && \in_array(self::lastSegment($token->text), self::EXTENSION_CLASS_NAMES, true)
                        && ($tokens[$index + 1] ?? null)?->is(T_DOUBLE_COLON) === true
                        && $method !== null && $method->is(T_STRING)
                        && \in_array(strtolower($method->text), self::OLD_STATIC_API_NAMES, true)
                        && ($tokens[$index + 3] ?? null)?->text === '('
                    ) {
                        $violations[] = self::relative($file) . ":{$token->line} {$token->text}::{$method->text}()";
                    }
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * docs/09の受け入れ基準: 実行時にWP_CONTENT_DIR配下へのManifestキャッシュの書き込みが存在しない
     * (acf-bridgeのsrcのどのファイルもWP_CONTENT_DIRを参照しない。定数としての参照と、\constant()・\defined()に渡す
     * 文字列のリテラルを数える)。
     */
    public function testAcfBridgeSourceDoesNotReferenceWpContentDir(): void
    {
        $references = [];

        foreach (self::phpFilesUnder('packages/acf-bridge/src') as $file) {
            foreach (self::significantTokens($file) as $token) {
                $text = $token->is(T_CONSTANT_ENCAPSED_STRING) ? substr($token->text, 1, -1) : ltrim($token->text, '\\');

                if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING]) && $text === 'WP_CONTENT_DIR') {
                    $references[] = self::relative($file) . ":{$token->line}";
                }
            }
        }

        self::assertSame([], $references);
    }

    /**
     * docs/09の受け入れ基準: 実行時にManifestキャッシュの書き込みが存在しない。acf-bridgeのsrcで、ファイルを書く関数・
     * クラスを使うのは、sync(生成物の書き込み)を行うCli/の下のファイルだけである。
     */
    public function testAcfBridgeWritesFilesOnlyFromCli(): void
    {
        $outside = [];
        $inCli = [];

        foreach (self::acfBridgeFileSystemUses(self::FILE_WRITING_FUNCTIONS, self::FILE_WRITING_CLASSES) as [$relative, $line, $use]) {
            if (str_starts_with($relative, 'Cli/')) {
                $inCli[] = $use;
            } else {
                $outside[] = "{$relative}:{$line} {$use}";
            }
        }

        self::assertSame([], $outside);
        // 検出が空振りしていないこと(syncはCli/SyncRunnerでfile_put_contents()を使う)
        self::assertContains('file_put_contents', $inCli);
    }

    /**
     * docs/09の受け入れ基準: 実行時にacf-jsonディレクトリの走査が存在しない。acf-bridgeのsrcで、ディレクトリを列挙する
     * 関数・クラスを使うのは、sync・checkがacf-jsonを読むJson/AcfJsonRepository.phpと、Cli/の下のファイルだけである。
     */
    public function testAcfBridgeListsDirectoriesOnlyFromJsonRepositoryAndCli(): void
    {
        $outside = [];
        $allowed = [];

        foreach (self::acfBridgeFileSystemUses(self::DIRECTORY_LISTING_FUNCTIONS, self::DIRECTORY_LISTING_CLASSES) as [$relative, $line, $use]) {
            if ($relative === 'Json/AcfJsonRepository.php' || str_starts_with($relative, 'Cli/')) {
                $allowed[] = $use;
            } else {
                $outside[] = "{$relative}:{$line} {$use}";
            }
        }

        self::assertSame([], $outside);
        // 検出が空振りしていないこと(AcfJsonRepositoryはglob()でacf-jsonの.jsonを列挙する)
        self::assertContains('glob', $allowed);
    }

    /**
     * docs/09の受け入れ基準: 実行時にacf-jsonの走査とManifestキャッシュの書き込みが存在しない。実行時のクラス
     * (src/の直下のAcfBridge・BridgeConfig・AcfFieldAccess・AcfTypes・AcfLocalTime・AcfMetaStorageと、生成物の
     * AcfManifestが返すManifest/ManagedKey。docs/09 §1.4)が、sync・checkの側のコード(Json\・Generate\・Mapping\・
     * Manifest\ManagedKeyCollector・Cli\のAcfCommand以外)を参照しない。
     *
     * AcfBridgeがCli\AcfCommandを参照するのは許す。WP-CLIの環境(定数WP_CLIが真)でだけ`wp tmswp acf`のコマンドとして
     * 登録するもので、AcfCommandは構築で何も読み書きせず、走査はコマンドの実行のときだけ起きるためである。
     */
    public function testAcfBridgeRuntimeClassesDoNotDependOnSyncOrCheckCode(): void
    {
        $runtimeFiles = self::trackedPaths('packages/acf-bridge/src/*.php');
        $runtimeFiles[] = self::root() . '/packages/acf-bridge/src/Manifest/ManagedKey.php';
        self::assertContains(self::root() . '/packages/acf-bridge/src/AcfBridge.php', $runtimeFiles);

        $violations = [];

        foreach ($runtimeFiles as $file) {
            $tokens = self::significantTokens($file);
            $namespace = '';

            foreach ($tokens as $index => $token) {
                $next = $tokens[$index + 1] ?? null;

                if ($token->is(T_NAMESPACE) && $next !== null && $next->is([T_NAME_QUALIFIED, T_STRING])) {
                    $namespace = $next->text;
                }

                if (!$token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    continue;
                }

                $name = ltrim($token->text, '\\');

                // 名前空間からの相対の名前(Json\AcfJsonRepository等)を、ファイルの名前空間で完全な名前にする
                if ($token->is(T_NAME_QUALIFIED) && !str_starts_with($name, 'TMSWP\\')) {
                    $name = $namespace . '\\' . $name;
                }

                if (
                    preg_match('/\ATMSWP\\\\AcfBridge\\\\(?:Json|Generate|Mapping|Cli)\\\\|\ATMSWP\\\\AcfBridge\\\\Manifest\\\\ManagedKeyCollector\z/', $name) === 1
                    && $name !== 'TMSWP\AcfBridge\Cli\AcfCommand'
                ) {
                    $violations[] = self::relative($file) . ":{$token->line} {$name}";
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * docs/07の受け入れ基準「Log/LoggerRegistry/ScopedLog/SimpleLoggerに__call/__callStaticが存在しない」と、docs/08の
     * 受け入れ基準「全クラスが…マジックメソッド不使用・extract()不使用」(AGENTS.mdのコーディング規約の禁止事項)。
     * 6パッケージのsrcのどのクラスも、マジックメソッド(__call・__callStatic・__get・__set・__isset・__unset)を宣言せず、
     * extract()を呼ばない。
     */
    public function testPackageSourcesDeclareNoMagicMethodAndCallNoExtract(): void
    {
        $magicMethods = ['__call', '__callstatic', '__get', '__set', '__isset', '__unset'];
        $violations = [];
        $files = [];

        foreach (self::trackedDirectories('packages/*/src') as $directory) {
            $files = array_merge($files, self::phpFilesUnder(self::relative($directory)));
        }

        self::assertContains(self::root() . '/packages/core/src/Log/SimpleLogger.php', $files);

        foreach ($files as $file) {
            $tokens = self::significantTokens($file);

            foreach ($tokens as $index => $token) {
                $next = $tokens[$index + 1] ?? null;

                if ($token->is(T_FUNCTION) && $next !== null && \in_array(strtolower($next->text), $magicMethods, true)) {
                    $violations[] = self::relative($file) . ":{$next->line} declares {$next->text}()";
                }

                if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && strtolower(ltrim($token->text, '\\')) === 'extract' && self::isFunctionCall($tokens, $index)) {
                    $violations[] = self::relative($file) . ":{$token->line} calls extract()";
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * docs/01の受け入れ基準「手で書いた全PHPファイルが`declare(strict_types=1)`を持つ」。gitの追跡する.phpのファイルを
     * 調べる。生成物の、wp-scriptsが`build/`に書く`*.asset.php`と、
     * `composer i18n:build`(`wp i18n make-php`)が`languages/`に書く`*.l10n.php`は対象にしない。
     */
    public function testEveryHandWrittenPhpFileDeclaresStrictTypes(): void
    {
        $generated = static fn(string $path): bool => preg_match('#/build/.+\.asset\.php\z|/languages/[^/]+\.l10n\.php\z#', $path) === 1;
        $files = array_merge(self::trackedPaths('*.php'), self::trackedPaths('.*.php'));

        foreach (['bin', 'examples', 'packages', 'starters', 'tests', 'tools'] as $directory) {
            $files = array_merge($files, self::filesUnder($directory, static fn(string $path): bool => str_ends_with($path, '.php') && !$generated($path)));
        }

        self::assertContains(self::root() . '/packages/core/src/Kernel/Kernel.php', $files);
        self::assertContains(self::root() . '/.php-cs-fixer.dist.php', $files);
        $missing = [];

        foreach ($files as $file) {
            // 先頭(シバンの行・<?php・コメントの後)のdeclare文。ファイル全体は字句に分けない(fixtureの大きなファイルがあるため)
            if (preg_match('#\A(?:\#![^\n]*\n)?<\?php\s+(?:/\*.*?\*/\s*|//[^\n]*\n\s*)*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;#s', self::read($file)) !== 1) {
                $missing[] = self::relative($file);
            }
        }

        self::assertSame([], $missing);
    }

    /**
     * docs/09の受け入れ基準「`tmswp/core`のソースコードに`acf`という文字列(識別子・フック名・コメント)が存在しない
     * (`grep -ri acf packages/core/src`が0件)」。packages/core/srcのすべてのファイルを、大文字と小文字を区別せずに調べる。
     */
    public function testCoreSourceContainsNoAcfString(): void
    {
        $found = [];
        // .DS_Store等の追跡しないドットファイルは対象にしない
        $files = self::filesUnder('packages/core/src', static fn(string $path): bool => !str_starts_with(basename($path), '.'));
        self::assertContains(self::root() . '/packages/core/src/Kernel/Kernel.php', $files);

        foreach ($files as $file) {
            foreach (explode("\n", self::read($file)) as $index => $line) {
                if (stripos($line, 'acf') !== false) {
                    $found[] = self::relative($file) . ':' . ($index + 1) . " {$line}";
                }
            }
        }

        self::assertSame([], $found);
    }

    /**
     * docs/10の受け入れ基準「全ユニットテストが`TMSWP\Testing\UnitTestCase`を継承している(PHPStanの規則・型推論の
     * テスト(`RuleTestCase`・`TypeInferenceTestCase`が基底)を除く)」。FWのユニットテスト(packages/*\/tests/Unitと、
     * ルートのtests/Unit)の、ファイルの名前と同じ名前のテストのクラスを調べる。PHPStanのテストは、PHPStanの基底が要るため、
     * 下の一覧のクラスだけを例外として許し、その基底がRuleTestCaseかTypeInferenceTestCaseであることも確かめる。
     * スターター(starters/*\/tests)は利用側のプロジェクトの雛形で、FWのテストではないため対象にしない。
     * ルートのtests/Unitの名前空間(`TMSWP\Tests\`)はautoload-devの対象外で、PHPUnitがファイルを直接読み込むため、
     * ファイルを読み込んでから調べる(このテストだけを走らせても、全体で走らせても同じ結果にするため)。packages/*\/tests/Unitは
     * autoload-devのPSR-4で読み込めることを確かめる。
     */
    public function testEveryUnitTestExtendsUnitTestCaseExceptThePhpStanTests(): void
    {
        $phpStanTests = [
            'TMSWP\\Core\\Tests\\Unit\\PHPStan\\KernelResetMethodCallableOutsideTestRuleTest' => 'PHPStan\\Testing\\RuleTestCase',
            'TMSWP\\Core\\Tests\\Unit\\PHPStan\\KernelResetOutsideTestRuleTest' => 'PHPStan\\Testing\\RuleTestCase',
            'TMSWP\\Core\\Tests\\Unit\\PHPStan\\KernelResetStaticCallableOutsideTestRuleTest' => 'PHPStan\\Testing\\RuleTestCase',
            'TMSWP\\Core\\Tests\\Unit\\PHPStan\\MetaKeyOwnershipRuleTest' => 'PHPStan\\Testing\\RuleTestCase',
            'TMSWP\\Core\\Tests\\Unit\\PHPStan\\ModelTypeInferenceTest' => 'PHPStan\\Testing\\TypeInferenceTestCase',
            'TMSWP\\Core\\Tests\\Unit\\PHPStan\\TypeInferenceTest' => 'PHPStan\\Testing\\TypeInferenceTestCase',
        ];
        $directories = array_merge(self::trackedDirectories('packages/*/tests/Unit'), [self::root() . '/tests/Unit']);
        $others = [];
        $exceptions = [];

        foreach ($directories as $directory) {
            foreach (self::filesUnder(self::relative($directory), static fn(string $path): bool => str_ends_with($path, 'Test.php')) as $file) {
                $class = self::declaredClass($file, basename($file, '.php'));

                if ($directory === self::root() . '/tests/Unit') {
                    require_once $file;
                    self::assertTrue(class_exists($class, false), "{$class} must be declared in " . self::relative($file));
                } else {
                    self::assertTrue(class_exists($class), "{$class} must be autoloadable from " . self::relative($file));
                }

                if (\array_key_exists($class, $phpStanTests)) {
                    $exceptions[$class] = (new ReflectionClass($class))->isSubclassOf($phpStanTests[$class]);
                } elseif (!is_subclass_of($class, UnitTestCase::class)) {
                    $others[] = $class;
                }
            }
        }

        self::assertSame([], $others);
        ksort($exceptions);
        self::assertSame(array_fill_keys(array_keys($phpStanTests), true), $exceptions);
    }

    /**
     * docs/01の受け入れ基準「グローバル関数はすべてtms_接頭辞+§6.2の予約表掲載のもののみで、function_existsガードで
     * 囲んでいない」。6パッケージのsrcがグローバル(クラスの外)に宣言する関数は、予約表のtms_log(tmswp/core)と
     * tms_thumb(tmswp/thumbnail)だけで、それを宣言するファイルはfunction_exists()を呼ばない。
     */
    public function testGlobalFunctionsAreOnlyTheReservedHelpersWithoutFunctionExistsGuard(): void
    {
        $declared = [];
        $guarded = [];

        foreach (self::trackedDirectories('packages/*/src') as $directory) {
            foreach (self::phpFilesUnder(self::relative($directory)) as $file) {
                $tokens = self::significantTokens($file);
                $depth = 0;
                $names = [];

                foreach ($tokens as $index => $token) {
                    if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                        ++$depth;
                    } elseif ($token->text === '}') {
                        --$depth;
                    }

                    $next = $tokens[$index + 1] ?? null;

                    // 深さ0のfunctionの直後が名前なら、グローバル(名前空間の直下)の関数の宣言(クロージャは`(`が続く)
                    if ($depth === 0 && $token->is(T_FUNCTION) && $next !== null && $next->is(T_STRING)) {
                        $names[] = $next->text;
                    }
                }

                if ($names === []) {
                    continue;
                }

                $relative = self::relative($file);
                $declared[$relative] = $names;

                foreach ($tokens as $index => $token) {
                    if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && strtolower(ltrim($token->text, '\\')) === 'function_exists' && self::isFunctionCall($tokens, $index)) {
                        $guarded[] = "{$relative}:{$token->line}";
                    }
                }
            }
        }

        self::assertSame(['packages/core/src/functions.php' => ['tms_log'], 'packages/thumbnail/src/functions.php' => ['tms_thumb']], $declared);
        self::assertSame([], $guarded);
    }

    /**
     * docs/12のv0.9.1-T4: 配布物のソース(packages/*\/src・packages/*\/phpstan・packages/*\/bin)に、設計文書と決定の番号への
     * 参照が無い。利用者がComposerで受け取るパッケージにはdocs/が入らず、決定の番号は公開していないファイルの番号のため、
     * 利用者が辿れないからである。コメントだけでなく、利用者に見えるメッセージの文字列も対象にするため、トークンではなく
     * ファイルの行で調べる。packages/*\/testsは配布物に入らないため対象にしない。
     */
    public function testDistributedSourcesDoNotReferenceDesignDocumentsOrDecisionNumbers(): void
    {
        $files = [];

        foreach (['packages/*/src', 'packages/*/phpstan', 'packages/*/bin'] as $pattern) {
            foreach (self::trackedDirectories($pattern) as $directory) {
                $files = array_merge($files, self::filesUnder(self::relative($directory), static fn(string $path): bool => true));
            }
        }

        // 3種類のディレクトリを走査していること(この検査が空振りしていないことの確かめ)
        self::assertContains(self::root() . '/packages/core/src/Kernel/Kernel.php', $files);
        self::assertContains(self::root() . '/packages/core/phpstan/src/KernelResetOutsideTestRule.php', $files);
        self::assertContains(self::root() . '/packages/acf-bridge/bin/tms-acf', $files);
        $violations = [];

        foreach ($files as $file) {
            $contents = self::read($file);

            if (str_contains($contents, "\0")) {
                // バイナリのファイル
                continue;
            }

            foreach (self::lines($contents) as $index => $line) {
                if (self::matchesAny(self::DESIGN_REFERENCE_PATTERNS, $line)) {
                    $violations[] = self::relative($file) . ':' . ($index + 1) . " {$line}";
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * docs/12のv0.9.1-T4: packages/*\/rules・packages/*\/README.mdの設計文書への参照は、その版のタグのmonorepoのURLへの
     * Markdownのリンク(`[docs/02-kernel.md](https://github.com/tmstack-io/tmswp/blob/vX.Y.Z/docs/02-kernel.md)`)にし、
     * タグは`Kernel::VERSION`の版である(版の準備でリンクを上げ忘れると失敗する)。リンクを除いた本文に、設計文書と
     * 決定の番号への参照と、この形でないmonorepoのblob・treeのURLが無い。
     */
    public function testRulesAndReadmesLinkDesignDocumentsToTheTagOfKernelVersion(): void
    {
        $files = self::trackedPaths('packages/*/README.md');

        foreach (self::trackedDirectories('packages/*/rules') as $directory) {
            $files = array_merge($files, self::filesUnder(self::relative($directory), static fn(string $path): bool => str_ends_with($path, '.md')));
        }

        self::assertContains(self::root() . '/packages/core/README.md', $files);
        self::assertContains(self::root() . '/packages/core/rules/blocks.md', $files);
        $tag = 'v' . Kernel::VERSION;
        $links = 0;
        $violations = [];

        foreach ($files as $file) {
            foreach (self::lines(self::read($file)) as $index => $line) {
                $location = self::relative($file) . ':' . ($index + 1);
                $count = preg_match_all(self::DESIGN_DOCUMENT_LINK, $line, $matches);
                $rest = preg_replace(self::DESIGN_DOCUMENT_LINK, '', $line);

                // 行ごとのアサーションにしない(アサーションの数が文書の行数で変わらないようにする)
                if ($count === false || $rest === null) {
                    self::fail(preg_last_error_msg());
                }

                $links += $count;

                foreach ($matches[2] as $version) {
                    if ($version !== $tag) {
                        $violations[] = "{$location} links to {$version} instead of {$tag}";
                    }
                }

                if (self::matchesAny(self::DESIGN_REFERENCE_PATTERNS, $rest) || str_contains($rest, 'github.com/tmstack-io/tmswp/blob/') || str_contains($rest, 'github.com/tmstack-io/tmswp/tree/')) {
                    $violations[] = "{$location} {$line}";
                }
            }
        }

        // リンクを見つけていること(この検査が空振りしていないことの確かめ)
        self::assertGreaterThan(0, $links);
        self::assertSame([], $violations);
    }

    /** @return iterable<string, array{string}> 設計文書・決定の番号への参照の行 */
    public static function designReferenceLines(): iterable
    {
        yield 'ファイル名と節' => ['スラッグに解決する(03-entities.md §1.5)。'];
        yield 'docs/NNと節' => ['保存名(docs/09 §6.6)'];
        yield '節だけ' => ['キャッチアップ(§3.3)'];
        yield '拡張子の無いファイル名と節' => ['(06-model §5)'];
        yield 'ファイル名だけ' => ['TMSWPの設計文書 docs/05-form.md'];
        yield '決定の番号' => ['WpDatabaseGuard(D-47)'];
        yield '設計判断の番号' => ['マトリクスは DR-12 のとおり'];
        yield 'メッセージの文字列' => ['"build custom blocks as described in docs/12 §1.11 (D-26)"'];
        yield 'docs/だけ' => ['手順は docs/RELEASING.md にある'];
        yield '拡張子の無い設計文書の名前' => ['Kernelの設計(02-kernel)の節'];
        yield '文書の番号' => ['独自ブロックは文書12の方式で作る'];
        yield '規則の番号' => ['値を R3 で正規化し'];
        yield '規則の番号(括弧の中)' => ['ベアテーブル名(R1)'];
    }

    /** 参照の検出の正の対照: 書き換える前のソースにあった形の参照を検出する。 */
    #[DataProvider('designReferenceLines')]
    public function testDesignReferencePatternsDetectReferences(string $line): void
    {
        self::assertTrue(self::matchesAny(self::DESIGN_REFERENCE_PATTERNS, $line));
    }

    /** @return iterable<string, array{string}> 設計文書・決定の番号への参照ではない行 */
    public static function nonDesignReferenceLines(): iterable
    {
        yield '版の接尾辞' => ['`-alpha-12345-src` 等)を除いて比べる'];
        yield 'ライセンス' => ['"license": "BSD-3-Clause"'];
        yield '日付の書式' => ["date('Y-m-d H:i:s')"];
        yield 'sprintfの書式' => ["sprintf('%1\$s-%2\$s', \$a, \$b)"];
        yield 'HTTPのヘッダ' => ['X-WP-Nonce'];
        yield 'レシピのファイル' => ['vendor/tmswp/core/rules/blocks.md'];
        yield 'RFCの番号' => ['RFC 3986の形'];
        yield 'エラーコード' => ['E101・W006'];
        yield 'PSRの番号' => ['PSR-3のロガー・PSR-11のコンテナ'];
        yield '英数字の続く名前' => ['R2D2・ARRAY_A・utf8mb4・sha256'];
        yield '外部の文書のURL' => ['https://developer.mozilla.org/en-US/docs/Web/HTTP'];
        yield '版の番号' => ['PHPUnit 11・WordPress 7.0'];
        yield 'ログの出力先' => ['wp-content/tmswp-logs/'];
        yield '日付' => ['2026-10-30'];
    }

    /** 参照の検出の負の対照: 参照ではない文字列(版・ライセンス・書式・レシピのファイル)を検出しない。 */
    #[DataProvider('nonDesignReferenceLines')]
    public function testDesignReferencePatternsIgnoreNonReferences(string $line): void
    {
        self::assertFalse(self::matchesAny(self::DESIGN_REFERENCE_PATTERNS, $line));
    }

    /**
     * acf-bridgeのsrcで、指定した関数の呼び出しと、指定したクラスへの参照(new・use・定数の参照等)の一覧。
     * 要素は[src/からの相対パス, 行, 小文字の名前]。
     *
     * @param list<string> $functions 小文字の関数の名前
     * @param list<string> $classes 小文字のクラスの名前
     *
     * @return list<array{string, int, string}>
     */
    private static function acfBridgeFileSystemUses(array $functions, array $classes): array
    {
        $src = self::root() . '/packages/acf-bridge/src/';
        $uses = [];

        foreach (self::phpFilesUnder('packages/acf-bridge/src') as $file) {
            $tokens = self::significantTokens($file);
            $relative = substr($file, \strlen($src));

            foreach ($tokens as $index => $token) {
                if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    continue;
                }

                $name = strtolower(self::lastSegment($token->text));

                if (\in_array($name, $classes, true) || (!$token->is(T_NAME_QUALIFIED) && \in_array($name, $functions, true) && self::isFunctionCall($tokens, $index))) {
                    $uses[] = [$relative, $token->line, $name];
                }
            }
        }

        return $uses;
    }

    /**
     * ACFのAPIの名前か。acf()・acf_*()と、テンプレート・値のAPI(get_field・update_field・delete_field・
     * get_sub_field・update_sub_field・delete_sub_field・get_fields・get_field_object・the_field・the_sub_field・
     * have_rows・the_row・get_row・get_row_layout・add_row・update_row・delete_row・add_sub_row等)。
     * acf-bridgeのsrcが呼ぶACFの関数(acf_clone_field・acf_connect_attachment_to_post・acf_decode_post_id・
     * acf_delete_value・acf_determine_internal_post_type・acf_flush_value_cache・acf_format_value・acf_get_attachment・
     * acf_get_field・acf_get_fields・acf_get_local_json_files・acf_get_valid_post_id・acf_get_value・acf_is_field_key・
     * acf_maybe_get_field・acf_update_value・acf・get_field・update_field・delete_field)はすべて含まれる。
     */
    private static function isAcfApiName(string $name): bool
    {
        return preg_match(
            '/\A(?:acf|acf_\w+|(?:get|update|delete)_(?:sub_)?field(?:s|_\w+)?|the_(?:sub_)?field|have_rows|the_row(?:_\w+)?|get_row(?:s|_\w+)?|(?:add|update|delete)_(?:sub_)?rows?)\z/i',
            ltrim($name, '\\'),
        ) === 1;
    }

    /**
     * 行が、コマンド名`wp-env`を名前解決する呼び出しか(許す行を除く)。
     */
    /** @param list<non-empty-string> $patterns */
    private static function matchesAny(array $patterns, string $line): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function callsWpEnvByName(string $line): bool
    {
        if (preg_match(self::NEGATION_LINE, $line) === 1 || preg_match(self::YAML_NAME_LINE, $line) === 1 || preg_match(self::ECHO_LINE, $line) === 1) {
            return false;
        }

        foreach (self::WP_ENV_BY_NAME as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * wp-envの呼び出しを調べる対象。場所(ファイルの相対パス、またはcomposer.json・package.jsonのscriptsの名前)ごとの
     * 行(行番号 => 行)。
     *
     * @return array<string, array<int, string>>
     */
    private static function wpEnvScanTargets(): array
    {
        $root = self::root();
        $targets = [];

        $manifestDirs = array_merge([$root, $root . '/examples/sample-site'], self::trackedDirectories('starters/*'));
        self::assertCount(4, $manifestDirs);

        foreach ($manifestDirs as $dir) {
            self::assertFileExists($dir . '/composer.json');

            foreach (['composer.json', 'package.json'] as $manifest) {
                if (!is_file($dir . '/' . $manifest)) {
                    continue;
                }

                foreach (self::scripts($dir . '/' . $manifest) as $name => $commands) {
                    $lines = [];

                    foreach ($commands as $command) {
                        foreach (self::lines($command) as $line) {
                            $lines[\count($lines) + 1] = $line;
                        }
                    }

                    $targets[self::relative($dir . '/' . $manifest) . "#scripts.{$name}"] = $lines;
                }
            }
        }

        $documents = array_merge(
            self::trackedPaths('.github/workflows/*.yml'),
            self::trackedPaths('docs/*.md'),
            [$root . '/README.md', $root . '/AGENTS.md'],
            self::trackedPaths('packages/*/README.md'),
            self::trackedPaths('starters/*/*.md'),
            self::trackedPaths('examples/sample-site/*.md'),
        );

        foreach (self::trackedDirectories('packages/*/rules') as $rules) {
            $documents = array_merge($documents, self::filesUnder(self::relative($rules), static fn(string $path): bool => str_ends_with($path, '.md')));
        }

        $documents = array_merge($documents, self::filesUnder('tools', static fn(string $path): bool => str_ends_with($path, '.sh') || preg_match('/\A#![^\n]*\b(?:ba|z)?sh\b/', self::read($path)) === 1));

        foreach ($documents as $document) {
            $lines = [];

            foreach (self::lines(self::read($document)) as $index => $line) {
                $lines[$index + 1] = $line;
            }

            $targets[self::relative($document)] = $lines;
        }

        self::assertArrayHasKey('.github/workflows/qa.yml', $targets);
        self::assertArrayHasKey('docs/10-quality.md', $targets);
        self::assertArrayHasKey('tools/ci/bin/wp', $targets);

        return $targets;
    }

    /**
     * composer.json・package.jsonのscripts(名前 => コマンドの一覧)。
     *
     * @return array<string, list<string>>
     */
    private static function scripts(string $manifest): array
    {
        $json = json_decode(self::read($manifest), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        $scripts = $json['scripts'] ?? [];
        self::assertIsArray($scripts);
        $result = [];

        foreach ($scripts as $name => $commands) {
            $list = [];

            foreach (\is_array($commands) ? $commands : [$commands] as $command) {
                self::assertIsString($command);
                $list[] = $command;
            }

            $result[(string) $name] = $list;
        }

        return $result;
    }

    /**
     * actions/規約の痕跡を調べるファイル。
     *
     * @return list<string>
     */
    private static function actionsScanTargets(): array
    {
        $root = self::root();
        $files = [];

        foreach (['packages', 'starters', 'examples', 'tests', 'tools', 'bin', '.github'] as $directory) {
            $files = array_merge($files, self::filesUnder($directory, static fn(string $path): bool => true));
        }

        $configs = array_merge(
            [
                $root . '/composer.json', $root . '/package.json', $root . '/monorepo-builder.php', $root . '/playwright.config.mjs',
                $root . '/.php-cs-fixer.dist.php', $root . '/.wp-env.json', $root . '/.gitattributes', $root . '/.gitignore',
                $root . '/.editorconfig', $root . '/README.md', $root . '/AGENTS.md',
            ],
            self::trackedPaths('*.neon'),
            self::trackedPaths('*.dist'),
            array_filter(self::trackedPaths('docs/*.md'), static fn(string $path): bool => basename($path) !== '12-implementation-plan.md'),
        );

        foreach ($configs as $config) {
            self::assertFileExists($config);
            $files[] = $config;
        }

        self::assertContains($root . '/phpstan.neon.dist', $files);
        self::assertContains($root . '/docs/08-extensions.md', $files);

        return $files;
    }

    /**
     * テストのPHPのファイル(packages/*\/tests・tests・starters/*\/tests・examples/sample-site/tests。fixtureを含む)。
     *
     * @return list<string>
     */
    private static function testPhpFiles(): array
    {
        $root = self::root();
        $directories = array_merge(self::trackedDirectories('packages/*/tests'), [$root . '/tests'], self::trackedDirectories('starters/*/tests'), [$root . '/examples/sample-site/tests']);
        $files = [];

        foreach ($directories as $directory) {
            self::assertDirectoryExists($directory);
            $files = array_merge($files, self::phpFilesUnder(self::relative($directory)));
        }

        return $files;
    }

    /**
     * ルートからの相対のディレクトリの下のPHPのファイル(.php)。
     *
     * @return list<string>
     */
    private static function phpFilesUnder(string $directory): array
    {
        $files = self::filesUnder($directory, static fn(string $path): bool => str_ends_with($path, '.php'));
        self::assertNotSame([], $files, $directory);

        return $files;
    }

    /**
     * ルートからの相対のディレクトリの下の追跡するファイルのうち、$acceptが真を返すものの絶対パス(昇順)。このファイル自身を
     * 除く。絞り込みは、ディレクトリごとに1回だけ行う(テストの実行の間にリポジトリのファイルは変わらない)。
     *
     * @param callable(string): bool $accept
     *
     * @return list<string>
     */
    private static function filesUnder(string $directory, callable $accept): array
    {
        self::$filesUnderCache[$directory] ??= self::scanFilesUnder($directory);

        // .DS_Store等の追跡外のファイルでアサーションの数が変わらないよう、絞り込みはアサーションにしない
        return array_values(array_filter(self::$filesUnderCache[$directory], $accept));
    }

    /**
     * ルートからの相対のディレクトリの下の追跡するファイルの絶対パス(昇順)。このファイル自身を除く。
     *
     * @return list<string>
     */
    private static function scanFilesUnder(string $directory): array
    {
        $path = self::root() . '/' . $directory;
        self::assertDirectoryExists($path);
        $files = [];

        foreach (self::trackedFiles() as $relative) {
            if (str_starts_with($relative, $directory . '/')) {
                $files[] = self::root() . '/' . $relative;
            }
        }

        return $files;
    }

    /**
     * gitの追跡するファイルのうち作業ツリーにあるもの(ルートからの相対パス。昇順。このファイル自身を除く)。
     * `git ls-files`は1回だけ実行する。作業ツリーで消してまだコミットしていないファイルは、読めないため除く。
     *
     * @return list<string>
     */
    private static function trackedFiles(): array
    {
        if (self::$trackedFiles !== null) {
            return self::$trackedFiles;
        }

        $root = self::root();
        $self = self::relative(__FILE__);
        self::$trackedFiles = self::withoutPatchworkStreamWrapper(static function () use ($root, $self): array {
            $process = proc_open(['git', 'ls-files', '-z'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);

            if (!\is_resource($process)) {
                self::fail('cannot run git ls-files');
            }

            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            if (proc_close($process) !== 0 || !\is_string($output)) {
                self::fail('git ls-files failed: ' . (\is_string($error) ? $error : ''));
            }

            $files = [];

            // マージの競合の間は、同じパスがステージごとに出るため、重複を除く
            foreach (array_unique(explode("\0", $output)) as $relative) {
                // 空の要素は、出力の末尾のNULの後ろである
                if ($relative !== '' && $relative !== $self && is_file($root . '/' . $relative)) {
                    $files[] = $relative;
                }
            }

            // 一覧が空・別のリポジトリのものなら、走査の対象が無いまま成功しないよう止める
            if (!\in_array('composer.json', $files, true)) {
                self::fail("git ls-files did not list composer.json in {$root}");
            }

            return $files;
        });

        return self::$trackedFiles;
    }

    /**
     * vendor/・node_modules/・.git/に下りない、ディレクトリの再帰の反復子。作った時点でディレクトリを開くため、
     * withoutPatchworkStreamWrapper()の中で作る(Patchworkのwrapperで開いたディレクトリを読むと、Patchworkが
     * wrapperを付け直す)。
     *
     * @return RecursiveCallbackFilterIterator<mixed, mixed, RecursiveDirectoryIterator>
     */
    private static function directoryIterator(string $directory): RecursiveCallbackFilterIterator
    {
        return new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $entry): bool => !($entry->isDir() && \in_array($entry->getFilename(), self::SKIPPED_DIRECTORIES, true)),
        );
    }

    /**
     * $fileが宣言する名前空間の中の、クラス$shortNameの完全な名前(ファイルの名前空間の宣言で決める)。
     *
     * @return class-string|string
     */
    private static function declaredClass(string $file, string $shortName): string
    {
        $tokens = self::significantTokens($file);

        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;

            if ($token->is(T_NAMESPACE) && $next !== null && $next->is([T_NAME_QUALIFIED, T_STRING])) {
                return $next->text . '\\' . $shortName;
            }
        }

        return $shortName;
    }

    /**
     * $tokens[$index]の名前が、関数の呼び出しの名前か(直後が`(`で、メソッド・静的メソッドの呼び出し・関数の宣言・newでない)。
     *
     * @param list<PhpToken> $tokens
     */
    private static function isFunctionCall(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;

        return ($tokens[$index + 1] ?? null)?->text === '('
            && !($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST]));
    }

    /** 名前空間で修飾した名前の最後の部分 */
    private static function lastSegment(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /** @return list<PhpToken> 空白・コメントを除いたトークン */
    private static function significantTokens(string $file): array
    {
        return array_values(array_filter(
            PhpToken::tokenize(self::read($file)),
            static fn(PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
    }

    /**
     * 追跡するファイルのうち、ルートからの相対パスが$patternに一致するものの絶対パス(昇順)。$patternの`*`は`/`を越えず、
     * glob()と同じく、`*`で始まる部分はドットで始まる名前に一致しない(例: `docs/*.md`、`.*.php`)。
     *
     * @return list<string>
     */
    private static function trackedPaths(string $pattern): array
    {
        $regex = '#\A' . self::patternRegex($pattern) . '\z#';
        $paths = [];

        foreach (self::trackedFiles() as $relative) {
            if (preg_match($regex, $relative) === 1) {
                $paths[] = self::root() . '/' . $relative;
            }
        }

        return $paths;
    }

    /**
     * 追跡するファイルを含むディレクトリのうち、ルートからの相対パスが$patternに一致するものの絶対パス(昇順。重複なし)。
     * $patternの書き方はtrackedPaths()と同じ(例: `packages/*\/src`)。
     *
     * @return list<string>
     */
    private static function trackedDirectories(string $pattern): array
    {
        $regex = '#\A(' . self::patternRegex($pattern) . ')/#';
        $directories = [];

        foreach (self::trackedFiles() as $relative) {
            if (preg_match($regex, $relative, $match) === 1) {
                $directories[] = self::root() . '/' . $match[1];
            }
        }

        $directories = array_values(array_unique($directories));
        sort($directories);

        return $directories;
    }

    /** trackedPaths()・trackedDirectories()の$patternを、正規表現の断片(区切りの`#`をエスケープ済み)にする */
    private static function patternRegex(string $pattern): string
    {
        $segments = [];

        foreach (explode('/', $pattern) as $segment) {
            $regex = str_replace('\\*', '[^/]*', preg_quote($segment, '#'));
            $segments[] = str_starts_with($segment, '*') ? '(?!\.)' . $regex : $regex;
        }

        return implode('/', $segments);
    }

    /**
     * 文字列の行(改行で分けたもの)。
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = preg_split('/\R/u', $text);
        self::assertIsArray($lines);

        return $lines;
    }

    private static function read(string $file): string
    {
        $contents = self::withoutPatchworkStreamWrapper(static fn(): string|false => file_get_contents($file));
        self::assertIsString($contents, $file);

        return $contents;
    }

    /**
     * $actionを、Patchworkのstream wrapperを外した標準のファイルのstream wrapperで実行する。
     *
     * Patchwork(Brain Monkeyが読み込む)のstream wrapperは、ファイル操作のたびにstream factoryのresourceを
     * プロセスの終わりまで残す(tmswp/testingのUsesTempDirectories::deleteDirectory()を参照)。多くのファイルを
     * 走査して読むこのテストではそれが積み上がるため、その間だけ、resourceを解放する手順で標準のwrapperに戻す。
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

    /** ルートからの相対パス */
    private static function relative(string $path): string
    {
        return substr($path, \strlen(self::root()) + 1);
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
