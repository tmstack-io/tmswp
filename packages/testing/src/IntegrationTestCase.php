<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use LogicException;
use ReflectionProperty;
use TMSWP\Core\Kernel\Kernel;
use WP_UnitTestCase;
use wpdb;

/**
 * 統合層の共通基底。
 *
 * fixture のテーマ(TmswpFixtureTheme)とプラグイン(TmswpFixturePlugin)は、tests/fixtures/_boot.php が
 * 有効化し、WordPress の読み込み時に init より前に boot 済みである。テストはその Kernel と、
 * fixture の app/ のクラスを使う。fixture のクラスは PHPStan の解析対象外のため、テストからは
 * FQCN の文字列で参照し、fixtureClass() で型を確かめてから使う。
 */
abstract class IntegrationTestCase extends WP_UnitTestCase
{
    protected const THEME_NAMESPACE = 'TmswpFixtureTheme';

    protected const PLUGIN_NAMESPACE = 'TmswpFixturePlugin';

    /**
     * 最初のテストの開始の時点の Kernel のレジストリ(fixture のテーマとプラグインの Kernel)。
     *
     * @var array<string, Kernel>|null
     */
    private static ?array $fixtureKernels = null;

    /**
     * このテストの開始の時点の Kernel のレジストリ。tear_down でこの状態に戻す。
     *
     * @var array<string, Kernel>
     */
    private array $kernelsAtSetUp = [];

    /**
     * WP のテストライブラリは各テストの tear_down で登録済みメタキーをすべて解除する。boot した Kernel が
     * init で登録したメタキーを各テストで使えるよう、tests/fixtures/_boot.php が wp_loaded で控えた登録を戻す。
     *
     * Kernel のレジストリは、前のテストが boot した Kernel が残っていない(fixture の Kernel だけである)ことを確かめて
     * から控える。
     */
    public function set_up(): void
    {
        parent::set_up();

        if (!\array_key_exists('tmswp_tests_registered_meta_keys', $GLOBALS)) {
            throw new LogicException('tests/fixtures/_boot.php did not record the registered meta keys at wp_loaded');
        }

        $GLOBALS['wp_meta_keys'] = $GLOBALS['tmswp_tests_registered_meta_keys'];

        self::$fixtureKernels ??= Kernel::all();
        self::assertSame(array_keys(self::$fixtureKernels), array_keys(Kernel::all()), 'a previous test left booted kernels in the registry');
        $this->kernelsAtSetUp = Kernel::all();
    }

    /**
     * テストが boot した Kernel(一時ディレクトリの app/ を持ち、テストの後始末で消える)をレジストリから外し、
     * fixture の Kernel だけの状態に戻す。Kernel のフックの登録は WP のテストライブラリが各テストの後に戻す。
     */
    public function tear_down(): void
    {
        self::replaceKernelRegistry($this->kernelsAtSetUp);
        parent::tear_down();
    }

    protected static function themeKernel(): Kernel
    {
        return Kernel::get(self::THEME_NAMESPACE);
    }

    protected static function pluginKernel(): Kernel
    {
        return Kernel::get(self::PLUGIN_NAMESPACE);
    }

    /**
     * fixture のクラス名を、期待する親クラスの class-string として返す。
     *
     * @template T of object
     *
     * @param string $class fixture のクラスの FQCN
     * @param class-string<T> $parent 期待する親クラス・インターフェース
     *
     * @return class-string<T>
     */
    protected static function fixtureClass(string $class, string $parent): string
    {
        if (!is_subclass_of($class, $parent)) {
            self::fail("fixture class {$class} must extend {$parent}");
        }

        return $class;
    }

    /** 指定した投稿タイプの公開済み投稿を作り、その ID を返す。 */
    protected static function createPost(string $postType): int
    {
        $postId = wp_insert_post([
            'post_type' => $postType,
            'post_status' => 'publish',
            'post_title' => "Integration {$postType}",
        ]);

        self::assertIsInt($postId);
        self::assertGreaterThan(0, $postId);

        return $postId;
    }

    /**
     * Kernel のレジストリを置き換える。Kernel は登録した Kernel を個別に外す API を持たず、Kernel::reset() は fixture の
     * Kernel も外すため、テストの後始末に限り、レジストリの静的プロパティを直接書き戻す。
     *
     * @param array<string, Kernel> $kernels
     */
    private static function replaceKernelRegistry(array $kernels): void
    {
        (new ReflectionProperty(Kernel::class, 'kernels'))->setValue(null, $kernels);
    }

    protected static function wpdb(): wpdb
    {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            self::fail('wpdb is not available.');
        }

        return $wpdb;
    }
}
