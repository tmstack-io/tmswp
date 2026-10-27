<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use TMSWP\Core\Form\ConfirmToken;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\Core\Form\TokenSigner;
use TMSWP\Core\Form\TokenStatus;
use TMSWP\Testing\UnitTestCase;

final class ConfirmTokenTest extends UnitTestCase
{
    private const string JTI = '0123456789abcdef0123456789abcdef';

    public function testCanonicalJsonIsStableRegardlessOfInputOrder(): void
    {
        $left = TokenSigner::canonicalJson([
            'v' => ['name' => 'Taro', 'topics' => ['b', 'a']],
            'f' => 'contact',
            'ver' => 1,
        ]);
        $right = TokenSigner::canonicalJson([
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

        self::assertSame(TokenStatus::Valid, $result->status);
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
        $tamperedBody = $this->base64Url(str_replace('Taro', 'Jiro', $this->decodeBody($body)));

        self::assertSame(TokenStatus::Invalid, ConfirmToken::verify('contact', $body . '.deadbeef', secret: 'secret')->status);
        self::assertSame(TokenStatus::Invalid, ConfirmToken::verify('contact', $body . '.' . substr($signature, 0, 20), secret: 'secret')->status);
        self::assertSame(TokenStatus::Invalid, ConfirmToken::verify('contact', $tamperedBody . '.' . $signature, secret: 'secret')->status);
        self::assertSame(TokenStatus::Invalid, ConfirmToken::verify('other', $token, secret: 'secret')->status);
        self::assertSame(TokenStatus::Invalid, ConfirmToken::verify('contact', $token, secret: 'another secret')->status);
    }

    public function testExpiredTokenKeepsSignedValuesForBackNavigation(): void
    {
        $token = ConfirmToken::issue('contact', ['name' => 'Taro'], 'secret', 1000);
        $result = ConfirmToken::verify('contact', $token, 10, 'secret', 1011);

        self::assertSame(TokenStatus::Expired, $result->status);
        self::assertSame(['name' => 'Taro'], $result->values);
    }

    public function testVerifyRejectsInvalidPayloadValueShapeAndJti(): void
    {
        $payloads = [
            ['ver' => ConfirmToken::VERSION, 'f' => 'contact', 'j' => self::JTI, 't' => 1000, 'v' => ['topics' => ['news', 123]]],
            ['ver' => ConfirmToken::VERSION, 'f' => 'contact', 'j' => '../not-a-jti', 't' => 1000, 'v' => []],
        ];

        foreach ($payloads as $payload) {
            $token = $this->sign($payload, 'confirm-token');

            self::assertSame(TokenStatus::Invalid, ConfirmToken::verify('contact', $token, 1800, 'secret', 1000)->status);
        }

        // 同じ形の正しいペイロードは受け付ける(上の拒否が署名ではなく内容によることの対照)
        $valid = $this->sign(['ver' => ConfirmToken::VERSION, 'f' => 'contact', 'j' => self::JTI, 't' => 1000, 'v' => ['topics' => ['news']]], 'confirm-token');
        self::assertSame(TokenStatus::Valid, ConfirmToken::verify('contact', $valid, 1800, 'secret', 1000)->status);
    }

    public function testSignatureUsesAKeyDerivedPerPurposeInsteadOfTheRawSecret(): void
    {
        $payload = ['ver' => ConfirmToken::VERSION, 'f' => 'contact', 'j' => self::JTI, 't' => 1000, 'v' => []];
        $body = $this->base64Url(TokenSigner::canonicalJson($payload));

        // wp_salt('auth') をそのまま鍵にした署名は受け付けない
        self::assertSame(TokenStatus::Invalid, ConfirmToken::verify('contact', $body . '.' . hash_hmac('sha256', $body, 'secret'), 1800, 'secret', 1000)->status);
        // HKDF-SHA256(secret, 'tmswp/form/confirm-token') で派生させた鍵の署名を受け付ける
        $derivedKey = hash_hkdf('sha256', 'secret', 32, 'tmswp/form/confirm-token');
        self::assertSame(TokenStatus::Valid, ConfirmToken::verify('contact', $body . '.' . hash_hmac('sha256', $body, $derivedKey), 1800, 'secret', 1000)->status);
    }

    public function testDefaultSecretIsTheAuthSaltOfWordPress(): void
    {
        Functions\expect('wp_salt')->with('auth')->andReturn('salt from wordpress');

        $token = ConfirmToken::issue('contact', ['name' => 'Taro'], now: 1000);

        self::assertSame(TokenStatus::Valid, ConfirmToken::verify('contact', $token, 1800, 'salt from wordpress', 1000)->status);
    }

    public function testSubmitTokenRoundTripExpiryAndRejection(): void
    {
        $token = SubmitToken::issue('contact', 'secret', 1000);
        $result = SubmitToken::verify('contact', $token, 'secret', 1000 + SubmitToken::TTL);

        self::assertSame(TokenStatus::Valid, $result->status);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $result->jti);
        self::assertSame(1000, $result->issuedAt);
        self::assertNotSame($result->jti, SubmitToken::verify('contact', SubmitToken::issue('contact', 'secret', 1000), 'secret', 1000)->jti);
        self::assertSame(TokenStatus::Expired, SubmitToken::verify('contact', $token, 'secret', 1001 + SubmitToken::TTL)->status);
        self::assertSame(TokenStatus::Invalid, SubmitToken::verify('other', $token, 'secret', 1000)->status);
        self::assertSame(TokenStatus::Invalid, SubmitToken::verify('contact', '', 'secret', 1000)->status);
    }

    public function testTokensOfOnePurposeAreNotAcceptedAsTheOther(): void
    {
        $confirm = ConfirmToken::issue('contact', [], 'secret', 1000);
        $submit = SubmitToken::issue('contact', 'secret', 1000);

        self::assertSame(TokenStatus::Invalid, SubmitToken::verify('contact', $confirm, 'secret', 1000)->status);
        self::assertSame(TokenStatus::Invalid, ConfirmToken::verify('contact', $submit, 1800, 'secret', 1000)->status);
    }

    /** @param array<array-key, mixed> $payload */
    private function sign(array $payload, string $purpose): string
    {
        $body = $this->base64Url(TokenSigner::canonicalJson($payload));

        return $body . '.' . hash_hmac('sha256', $body, hash_hkdf('sha256', 'secret', 32, 'tmswp/form/' . $purpose));
    }

    private function base64Url(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function decodeBody(string $body): string
    {
        $decoded = base64_decode(strtr($body, '-_', '+/'), true);
        self::assertIsString($decoded);

        return $decoded;
    }
}
