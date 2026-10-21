<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\InquiryModel;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\FileExtension;
use TMSWP\Core\Form\Rule\MaxFileSize;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\StoredFile;
use UnexpectedValueException;

final class ContactForm
{
    public static function create(): Form
    {
        return new Form(
            FormDefinition::create('contact')
                ->text('customer_name', 'お名前', [new Required()])
                ->text('email', 'メールアドレス', [new Required(), new Email()])
                ->textarea('message', 'お問い合わせ内容', [new Required()])
                ->file('attachment', '添付ファイル', [
                    new Required(),
                    new FileExtension(['txt']),
                    MaxFileSize::mb(1),
                ])
                ->confirm()
                ->onComplete(static function (array $values): void {
                    $attachment = $values['attachment'];
                    $attachmentId = 0;
                    $attachmentName = null;

                    if ($attachment instanceof StoredFile) {
                        $attachmentId = $attachment->toAttachment();
                        $attachmentName = $attachment->clientName;
                    }

                    InquiryModel::insert([
                        'customer_name' => self::submittedString($values, 'customer_name'),
                        'email' => self::submittedString($values, 'email'),
                        'message' => self::submittedString($values, 'message'),
                        'attachment_id' => $attachmentId === 0 ? null : $attachmentId,
                        'attachment_name' => $attachmentName,
                        'created_at' => current_time('mysql'),
                    ]);
                })
        );
    }

    /** @param array<string, mixed> $values */
    private static function submittedString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (!\is_string($value)) {
            throw new UnexpectedValueException("submitted field must be string: {$key}");
        }

        return $value;
    }
}
