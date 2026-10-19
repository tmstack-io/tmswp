<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use LogicException;
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
     * WP のテストライブラリは各テストの tear_down で登録済みメタキーをすべて解除する。boot した Kernel が
     * init で登録したメタキーを各テストで使えるよう、tests/fixtures/_boot.php が wp_loaded で控えた登録を戻す。
     */
    public function set_up(): void
    {
        parent::set_up();

        if (!\array_key_exists('tmswp_tests_registered_meta_keys', $GLOBALS)) {
            throw new LogicException('tests/fixtures/_boot.php did not record the registered meta keys at wp_loaded');
        }

        $GLOBALS['wp_meta_keys'] = $GLOBALS['tmswp_tests_registered_meta_keys'];
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

    protected static function wpdb(): wpdb
    {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            self::fail('wpdb is not available.');
        }

        return $wpdb;
    }
}
