<?php

declare(strict_types=1);

namespace SampleSite\Mail;

use LogicException;
use SampleSite\Form\ContactForm;
use SampleSite\Option\SiteOption;
use TMSWP\Core\Log\Log;
use UnexpectedValueException;

/**
 * お問い合わせフォームの完了処理。サイトの通知先へ、問い合わせの内容をメールで送る(vendor/tmswp/core/rules/form-mail.md)。
 * 通知先は設定ページ(SiteSettingsPage)で決め、未設定ならサイトの管理者のメールアドレスにする。
 */
final class ContactMailer
{
    /**
     * @param array<string, mixed> $values onCompleteが受け取った値(検証済み)
     *
     * @throws MailSendException メールを送れなかった場合
     */
    public function send(array $values): void
    {
        $to = SiteOption::contactEmail() !== '' ? SiteOption::contactEmail() : get_bloginfo('admin_email');
        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        /* translators: 1: site name, 2: sender name */
        $subject = sprintf(__('[%1$s] Inquiry from %2$s', 'sample-site'), $siteName, self::string($values, 'full_name'));
        $lines = [];

        foreach (['full_name', 'kana', 'email', 'message'] as $name) {
            $lines[] = ContactForm::label($name) . ': ' . self::string($values, $name);
        }

        // 利用者の入力をヘッダに入れるときは、改行を取り除き(件名)、アドレスの形を確かめる(Reply-To)。
        // 改行が残ると、Bcc等のヘッダを差し込まれる(ヘッダインジェクション)。Fromは変えない
        $sent = wp_mail(
            self::address($to),
            self::headerText($subject),
            implode("\n", $lines),
            ['Reply-To: ' . self::address(self::string($values, 'email'))],
        );

        if (!$sent) {
            // 宛先・件名・本文は記録しない
            Log::scope('mail')->error('mail of form {form} could not be sent', ['form' => 'contact']);

            throw new MailSendException('failed to send the contact form mail');
        }
    }

    /** ヘッダに入れる文字列から、改行とそのほかの制御文字を取り除く */
    public static function headerText(string $value): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        if ($text === null) {
            throw new UnexpectedValueException('a mail header value must be valid UTF-8');
        }

        return trim($text);
    }

    /**
     * ヘッダに入れるメールアドレスを確かめて返す。改行・空白・表示名を含む値は通らない。
     *
     * @throws UnexpectedValueException メールアドレスの形でない場合(フォームのEmailルールを通った値は通る)
     */
    public static function address(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new UnexpectedValueException('a mail header address must be a valid email address');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @throws LogicException 値が文字列でない場合(フォームの定義の誤り)
     */
    private static function string(array $values, string $name): string
    {
        $value = $values[$name] ?? null;

        if (!\is_string($value)) {
            throw new LogicException("contact form value '{$name}' must be a string");
        }

        return $value;
    }
}
