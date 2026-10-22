<?php

declare(strict_types=1);

namespace App\Page;

use App\Form\ContactForm;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormResult;

/** page-contact.phpの前処理。お問い合わせフォームを駆動し、テンプレートが使うフォームと結果を返す */
final class ContactPage
{
    private function __construct(
        public readonly Form $form,
        public readonly FormResult $result,
    ) {}

    public static function handle(): self
    {
        $form = ContactForm::create();

        return new self($form, $form->handle());
    }
}
