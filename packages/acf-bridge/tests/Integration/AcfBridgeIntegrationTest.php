<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Integration;

use ACF;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Tests\Support\IntegrationTestCase;

/**
 * 有効化した無料版 ACF(.wp-env.json で導入)の実物に対する最小の確認。acf-bridge の網羅は v0.6.0 で行う。
 */
final class AcfBridgeIntegrationTest extends IntegrationTestCase
{
    public function testFreeAcfIsActive(): void
    {
        self::assertInstanceOf(ACF::class, acf());
        self::assertFalse(acf_is_pro());
    }

    public function testUseMetaStorageEntityReadsAndWritesThroughAcf(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Acf\\AcfStoredPost', PostType::class);
        self::assertTrue(post_type_exists('tmswp_acf_post'), 'fixture post type が登録されていません: tmswp_acf_post');
        $postId = self::createPost('tmswp_acf_post');
        $bag = $postClass::meta($postId);

        self::assertFalse($bag->has('price'));
        self::assertTrue($bag->set('price', '1200'));
        self::assertTrue($bag->has('price'));
        self::assertSame('1200', $bag->string('price'));
        // ACF の保存規約(update_field)で投稿メタに書かれ、ACF の読み出し(get_field)でも同じ値になる
        self::assertSame('1200', get_post_meta($postId, 'price', true));
        self::assertSame('1200', get_field('price', $postId, false));
    }
}
