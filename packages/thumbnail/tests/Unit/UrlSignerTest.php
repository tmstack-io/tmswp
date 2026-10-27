<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Unit;

use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Thumbnail\UrlSigner;

/**
 * URLの署名の対象(区切りのあいまいさが無い)と、鍵の導出。
 */
final class UrlSignerTest extends UnitTestCase
{
    public function testVerifyUsesRawPayloadValues(): void
    {
        $signer = new UrlSigner(UrlSigner::deriveKey('secret'));
        $signature = $signer->sign('id:1', 50, 40, '');

        self::assertTrue($signer->verify('id:1', '50', '40', '', $signature));
        self::assertFalse($signer->verify('id:1', '050', '40', '', $signature));
    }

    /** 値の中の`|`で、別の組み合わせと同じ署名の対象にならない(旧来の`{src}|{w}|{h}|{o}`では同じになった)。 */
    public function testPayloadIsNotAmbiguous(): void
    {
        $signer = new UrlSigner(UrlSigner::deriveKey('secret'));
        $signature = $signer->sign('a|1', 2, 3, '');

        self::assertTrue($signer->verify('a|1', '2', '3', '', $signature));
        self::assertFalse($signer->verify('a', '1|2', '3', '', $signature));
        self::assertFalse($signer->verify('a', '1', '2', '3|', $signature));
    }

    /** 鍵は秘密の値そのものではなく、用途を入れて導出したもの。同じ秘密の値からは同じ鍵になる。 */
    public function testDeriveKeySeparatesTheKeyFromTheSecret(): void
    {
        $key = UrlSigner::deriveKey('secret');

        self::assertSame($key, UrlSigner::deriveKey('secret'));
        self::assertNotSame('secret', $key);
        self::assertNotSame($key, UrlSigner::deriveKey('other secret'));
        self::assertNotSame(
            (new UrlSigner('secret'))->sign('id:1', 1, 1, ''),
            (new UrlSigner($key))->sign('id:1', 1, 1, ''),
        );
    }
}
