<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Testing\UnitTestCase;

/**
 * 配布するパッケージのcomposer.jsonが、設計書の受け入れ基準の依存と構成に従うことを確かめる(docs/08・docs/09)。
 */
final class PackageManifestTest extends UnitTestCase
{
    /**
     * docs/09の受け入れ基準: packages/acf-bridge/composer.jsonのrequireがphpとtmswp/coreだけで、ACFがcomposerの依存に
     * 含まれない。
     */
    public function testAcfBridgeRequiresOnlyPhpAndCore(): void
    {
        $require = array_keys(self::section(self::manifest('packages/acf-bridge'), 'require'));
        sort($require);

        self::assertSame(['php', 'tmswp/core'], $require);
    }

    /**
     * docs/08の受け入れ基準: 拡張のcomposer.jsonが共通の規約に従う。requireのphpが`>=8.3`、tmswp/coreの制約が今の版、
     * PSR-4が`TMSWP\<Pascal>\` → `src/`の1つだけである。
     *
     * tmswp/coreの今の版は、packages/core/composer.jsonのbranch-alias(`X.Y.x-dev`)から求め、`^X.Y`か`^X.Y.Z`を正とする。
     * 制約・branch-alias・Kernel::VERSIONは、版の最初のタスクで1つのコミットで手で上げ、リリースの道具は書き換えない
     * (リリースの後に次の版へ自動で上げない。D-54)。固定の`^0.9`を書くと版を上げるたびにこのテストも直すことになるため、
     * 版はbranch-aliasから求める。制約・branch-alias・Kernel::VERSIONとタグの版との一致は、リリースの前の検査
     * (tools/release/CheckReleaseReadinessReleaseWorker.php)が確かめる。
     */
    #[DataProvider('conventionalExtensions')]
    public function testExtensionManifestFollowsCommonConvention(string $package, string $namespace): void
    {
        $manifest = self::manifest('packages/' . $package);
        $require = self::section($manifest, 'require');
        [$major, $minor] = self::coreDevelopmentVersion();

        self::assertSame('>=8.3', $require['php'] ?? null);
        self::assertIsString($require['tmswp/core'] ?? null);
        self::assertMatchesRegularExpression('/\A\^' . $major . '\.' . $minor . '(?:\.\d+)?\z/', $require['tmswp/core']);
        self::assertSame([$namespace => 'src/'], self::section(self::section($manifest, 'autoload'), 'psr-4'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function conventionalExtensions(): iterable
    {
        yield 'thumbnail' => ['thumbnail', 'TMSWP\\Thumbnail\\'];
        yield 'settings-page' => ['settings-page', 'TMSWP\\SettingsPage\\'];
        yield 'ja-toolkit' => ['ja-toolkit', 'TMSWP\\JaToolkit\\'];
    }

    /**
     * docs/08の受け入れ基準: 固有の依存(ext-gd・ext-mbstring)が正しいパッケージにだけある。配布する8つのcomposer.json
     * (packages/*とstarters/*)のrequireとrequire-devで、ext-gdはthumbnailだけ、ext-mbstringはja-toolkitだけにある。
     *
     * @param non-empty-string $extension
     */
    #[DataProvider('packageSpecificExtensions')]
    public function testPackageSpecificExtensionIsRequiredOnlyByItsPackage(string $extension, string $package): void
    {
        $packages = glob(self::root() . '/packages/*', GLOB_ONLYDIR);
        $starters = glob(self::root() . '/starters/*', GLOB_ONLYDIR);
        self::assertIsArray($packages);
        self::assertIsArray($starters);
        $directories = array_merge($packages, $starters);
        self::assertCount(8, $directories);
        $requiring = [];

        foreach ($directories as $directory) {
            $relative = substr($directory, \strlen(self::root()) + 1);
            $manifest = self::manifest($relative);

            if (\array_key_exists($extension, self::section($manifest, 'require')) || \array_key_exists($extension, self::section($manifest, 'require-dev'))) {
                $requiring[] = $relative;
            }
        }

        self::assertSame([$package], $requiring);
    }

    /** @return iterable<string, array{non-empty-string, string}> */
    public static function packageSpecificExtensions(): iterable
    {
        yield 'ext-gd' => ['ext-gd', 'packages/thumbnail'];
        yield 'ext-mbstring' => ['ext-mbstring', 'packages/ja-toolkit'];
    }

    /**
     * docs/01の受け入れ基準「拡張パッケージがtmswp/core以外のtmswp/*をrequireしていない(拡張間依存禁止)」。
     * packages/*のcore以外の5つのrequireのtmswp/*は、tmswp/coreだけである(tmswp/testingはrequire-devにだけ置く)。
     */
    public function testExtensionPackagesRequireNoTmswpPackageOtherThanCore(): void
    {
        $requires = [];

        foreach (self::glob(self::root() . '/packages/*', GLOB_ONLYDIR) as $directory) {
            $relative = substr($directory, \strlen(self::root()) + 1);

            if ($relative === 'packages/core') {
                continue;
            }

            $requires[$relative] = array_values(array_filter(
                array_keys(self::section(self::manifest($relative), 'require')),
                static fn(int|string $name): bool => str_starts_with((string) $name, 'tmswp/'),
            ));
        }

        self::assertCount(5, $requires);
        self::assertSame(array_fill_keys(array_keys($requires), ['tmswp/core']), $requires);
    }

    /**
     * docs/01の受け入れ基準「全パッケージのライセンスがGPL-2.0-or-laterであり、packages/*のそれぞれにLICENSEがある」。
     * 8つのcomposer.json(packages/*とstarters/*)のlicenseがGPL-2.0-or-laterで、packages/*の6つがLICENSEのファイルを持ち、
     * その本文はルートのLICENSEと同じである。スターターはLICENSEのファイルを置かない(docs/01 §3.1。create-projectで作った
     * プロジェクトはsetup.phpがproprietaryに書き換える)。
     */
    public function testEveryPackageIsGplAndEveryPackageDirectoryHasTheLicenseFile(): void
    {
        $license = file_get_contents(self::root() . '/LICENSE');
        self::assertIsString($license);
        $packages = self::glob(self::root() . '/packages/*', GLOB_ONLYDIR);
        $starters = self::glob(self::root() . '/starters/*', GLOB_ONLYDIR);
        self::assertCount(6, $packages);
        self::assertCount(2, $starters);

        foreach ([...$packages, ...$starters] as $directory) {
            $relative = substr($directory, \strlen(self::root()) + 1);
            self::assertSame('GPL-2.0-or-later', self::manifest($relative)['license'] ?? null, $relative);
        }

        foreach ($packages as $directory) {
            self::assertSame($license, file_get_contents($directory . '/LICENSE'), $directory);
        }

        foreach ($starters as $directory) {
            self::assertFileDoesNotExist($directory . '/LICENSE');
        }
    }

    /**
     * docs/01の受け入れ基準「各パッケージのcomposer.jsonのname/名前空間/runtime依存が§3.1の表と完全一致する」。
     * §3.1の全パッケージ表の行ごとに、composer名のディレクトリ(packages/*・starters/*)のcomposer.jsonと照合する。
     *
     * - name: 表のcomposer名と同じ。
     * - 名前空間: 表の名前空間ルートがautoloadのpsr-4のキーにあり、psr-4のほかのキーはその下の名前空間だけである
     *   (tmswp/coreの`TMSWP\Core\PHPStan\`。docs/01 §2)。
     * - runtime依存: requireからphpを除いたものが、表の依存の列のバッククォートの項目(`<名前> <制約>`)と同じ。表の
     *   `^X.Y`は、packages/core/composer.jsonのbranch-aliasから求めた今の版(`^X.Y`)と読む。
     */
    public function testComposerManifestsMatchThePackageTableOfDocs01(): void
    {
        $markdown = file_get_contents(self::root() . '/docs/01-architecture.md');
        self::assertIsString($markdown);
        $start = strpos($markdown, "### 3.1 全パッケージ表\n");
        self::assertIsInt($start, 'docs/01 has no section 3.1');
        $end = strpos($markdown, "\n### 3.2", $start);
        self::assertIsInt($end);
        [$major, $minor] = self::coreDevelopmentVersion();
        $rows = [];

        foreach (explode("\n", substr($markdown, $start, $end - $start)) as $line) {
            if (preg_match('/\A\| `(tmswp\/[a-z-]+)` \| `([^`]+)`/', $line, $matches) !== 1) {
                continue;
            }

            $cells = explode(' | ', trim($line, '| '));
            preg_match_all('/`([^` ]+) ([^`]+)`/', (string) end($cells), $dependencies, PREG_SET_ORDER);
            $require = [];

            foreach ($dependencies as [, $name, $constraint]) {
                $require[$name] = $constraint === '^X.Y' ? "^{$major}.{$minor}" : $constraint;
            }

            ksort($require);
            $rows[$matches[1]] = ['namespace' => $matches[2], 'require' => $require];
        }

        self::assertCount(8, $rows);

        foreach ($rows as $name => $row) {
            $directory = str_starts_with($name, 'tmswp/starter-') ? 'starters/' . substr($name, \strlen('tmswp/starter-')) : 'packages/' . substr($name, \strlen('tmswp/'));
            $manifest = self::manifest($directory);
            $require = self::section($manifest, 'require');
            unset($require['php']);
            ksort($require);
            $namespaces = array_keys(self::section(self::section($manifest, 'autoload'), 'psr-4'));

            self::assertSame($name, $manifest['name'] ?? null, $directory);
            self::assertContains($row['namespace'], $namespaces, $directory);
            self::assertSame([], array_values(array_filter($namespaces, static fn(int|string $namespace): bool => !str_starts_with((string) $namespace, $row['namespace']))), $directory);
            self::assertSame($row['require'], $require, $directory);
        }
    }

    /**
     * glob()の結果。失敗(false)はアサーションで止める。
     *
     * @return list<string>
     */
    private static function glob(string $pattern, int $flags = 0): array
    {
        $paths = glob($pattern, $flags);
        self::assertIsArray($paths, $pattern);

        return $paths;
    }

    /**
     * packages/core/composer.jsonのbranch-alias(dev-main => X.Y.x-dev)の[X, Y]。
     *
     * @return array{string, string}
     */
    private static function coreDevelopmentVersion(): array
    {
        $alias = self::section(self::section(self::manifest('packages/core'), 'extra'), 'branch-alias')['dev-main'] ?? null;
        self::assertIsString($alias);
        self::assertSame(1, preg_match('/\A(\d+)\.(\d+)\.x-dev\z/', $alias, $matches));

        return [$matches[1], $matches[2]];
    }

    /**
     * ルートからの相対のディレクトリのcomposer.json。
     *
     * @return array<mixed>
     */
    private static function manifest(string $directory): array
    {
        $contents = file_get_contents(self::root() . '/' . $directory . '/composer.json');
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    /**
     * 配列の節(無ければ空の配列)。
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function section(array $data, string $key): array
    {
        $section = $data[$key] ?? [];
        self::assertIsArray($section, $key);

        return $section;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
