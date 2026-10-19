<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Form\ConfirmToken;
use TMSWP\Core\Form\ConfirmTokenStatus;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class ConfirmTokenTest extends UnitTestCase
{
    public function testCanonicalJsonIsStableRegardlessOfInputOrder(): void
    {
        $left = ConfirmToken::canonicalJson([
            'v' => ['name' => 'Taro', 'topics' => ['b', 'a']],
            'f' => 'contact',
            'ver' => 1,
        ]);
        $right = ConfirmToken::canonicalJson([
            'ver' => 1,
            'f' => 'contact',
            'v' => ['topics' => ['b', 'a'], 'name' => 'Taro'],
        ]);

        self::assertSame($left, $right);
    }

    public function testIssueAndVerifyRoundTrip(): void
    {
        $token = ConfirmToken::issue('contact', [
            'name' => 'Taro',
            'topics' => ['news', 'docs'],
            'file' => ['__tmsfile' => '0123456789abcdef0123456789abcdef'],
        ], 'secret', 1000);

        $result = ConfirmToken::verify('contact', $token, 1800, 'secret', 1200);

        self::assertSame(ConfirmTokenStatus::Valid, $result->status);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame(['news', 'docs'], $result->values['topics']);
        self::assertSame(['__tmsfile' => '0123456789abcdef0123456789abcdef'], $result->values['file']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $result->jti);
        self::assertSame(1000, $result->issuedAt);
    }

    public function testVerifyRejectsTamperingTruncationAndFormMismatch(): void
    {
        $token = ConfirmToken::issue('contact', ['name' => 'Taro'], 'secret', 1000);
        [$body, $signature] = explode('.', $token, 2);

        self::assertSame(ConfirmTokenStatus::Invalid, ConfirmToken::verify('contact', $body . '.deadbeef', secret: 'secret')->status);
        self::assertSame(ConfirmTokenStatus::Invalid, ConfirmToken::verify('contact', $body . '.' . substr($signature, 0, 20), secret: 'secret')->status);
        self::assertSame(ConfirmTokenStatus::Invalid, ConfirmToken::verify('other', $token, secret: 'secret')->status);
    }

    public function testExpiredTokenKeepsSignedValuesForBackNavigation(): void
    {
        $token = ConfirmToken::issue('contact', ['name' => 'Taro'], 'secret', 1000);
        $result = ConfirmToken::verify('contact', $token, 10, 'secret', 1011);

        self::assertSame(ConfirmTokenStatus::Expired, $result->status);
        self::assertSame(['name' => 'Taro'], $result->values);
    }

    public function testVerifyRejectsInvalidPayloadValueShape(): void
    {
        $body = $this->signedBody([
            'ver' => ConfirmToken::VERSION,
            'f' => 'contact',
            'j' => str_repeat('a', 32),
            't' => 1000,
            'v' => ['topics' => ['news', 123]],
        ]);
        $token = $body . '.' . hash_hmac('sha256', $body, 'secret');

        self::assertSame(ConfirmTokenStatus::Invalid, ConfirmToken::verify('contact', $token, 1800, 'secret', 1000)->status);
    }

    /** @param array<array-key, mixed> $payload */
    private function signedBody(array $payload): string
    {
        $json = ConfirmToken::canonicalJson($payload);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
