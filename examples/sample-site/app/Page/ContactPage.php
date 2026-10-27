<?php

declare(strict_types=1);

namespace SampleSite\Page;

use SampleSite\Form\ContactForm;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\FormStep;

/** page-contact.phpの前処理。お問い合わせフォームを駆動し、完了したら完了画面へリダイレクトする(PRG) */
final class ContactPage
{
    /** 完了画面(固定ページcontactの子ページthanks)のパス */
    public const string THANKS_PATH = '/contact/thanks/';

    private function __construct(
        public readonly Form $form,
        public readonly FormResult $result,
    ) {}

    public static function handle(): self
    {
        $form = ContactForm::create();
        // CSRF・送信トークン・値の検証・状態遷移・完了処理。ページキャッシュからも外す
        $result = $form->handle();

        if ($result->step === FormStep::Complete) {
            wp_safe_redirect(home_url(self::THANKS_PATH), 303);
            // PRGのリダイレクトの直後だけはexitを使う(FWの設計原則1のexit禁止の例外)
            exit;
        }

        return new self($form, $result);
    }
}
