<?php

declare(strict_types=1);

namespace SampleSite\Tests\Unit;

use SampleSite\Mail\ContactMailer;
use TMSWP\Testing\UnitTestCase;
use UnexpectedValueException;

/**
 * 利用者の入力をメールのヘッダに入れる前の検査(ヘッダインジェクションの対策)。
 * UnitTestCase(tmswp/testing)は、Brain MonkeyでWordPressの関数を差し替え、翻訳関数をソースの文字列を返すスタブにする。
 */
final class ContactMailerTest extends UnitTestCase
{
    public function testHeaderTextReplacesLineBreaksWithSpaces(): void
    {
        self::assertSame(
            '[Sample] Inquiry from Taro Bcc: victim@example.com',
            ContactMailer::headerText("[Sample] Inquiry from Taro\r\nBcc: victim@example.com"),
        );
    }

    public function testAddressAcceptsPlainEmailAddress(): void
    {
        self::assertSame('taro@example.com', ContactMailer::address('taro@example.com'));
    }

    public function testAddressRejectsLineBreak(): void
    {
        $this->expectException(UnexpectedValueException::class);

        ContactMailer::address("taro@example.com\r\nBcc: victim@example.com");
    }
}
