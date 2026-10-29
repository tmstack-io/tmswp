<?php

declare(strict_types=1);

namespace SampleSite\Form;

use LogicException;
use SampleSite\Mail\ContactMailer;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\JaToolkit\Form\KanaTextField;
use TMSWP\JaToolkit\Form\KanaType;

/**
 * お問い合わせフォーム(入力・確認・完了の3画面)。page-contact.phpがSampleSite\Page\ContactPageを通して使う。
 * CSRF・確認画面の値の保持・二重送信の防止・値の検証はFWが行う(vendor/tmswp/core/rules/form.md)。
 */
final class ContactForm
{
    public static function create(): Form
    {
        return new Form(
            FormDefinition::create('contact')
                // 項目の名前に、WordPressの公開のクエリ変数(name・s・p・page・year等)を使わない。WordPressはPOSTの値も
                // クエリ変数として読むため、`name`の項目を送るとその名前の投稿を探して404になる
                ->text('full_name', self::label('full_name'), [new Required(), new MaxLength(50)])
                // ja-toolkitのカナの項目。半角カナ・ひらがなを全角カタカナにそろえてから、文字種を検証する
                ->add(KanaTextField::create('kana', self::label('kana'), KanaType::Katakana, [new Required()], maxLength: 50))
                ->text('email', self::label('email'), [new Required(), new Email(), new MaxLength(254)])
                ->textarea('message', self::label('message'), [new Required(), new MaxLength(2000)])
                ->confirm()
                ->onComplete(
                    /** @param array<string, mixed> $values */
                    static function (array $values): void {
                        // 失敗は例外で知らせる。FWが記録し、利用者が送り直せる状態に戻す
                        (new ContactMailer())->send($values);
                    },
                ),
        );
    }

    /**
     * 項目のラベル。フォームの定義とメールの本文で同じ文言を使う。
     *
     * @throws LogicException 知らない項目の名前の場合
     */
    public static function label(string $name): string
    {
        return match ($name) {
            'full_name' => __('Name', 'sample-site'),
            'kana' => __('Name (katakana)', 'sample-site'),
            'email' => __('Email address', 'sample-site'),
            'message' => __('Message', 'sample-site'),
            default => throw new LogicException("unknown contact form field: {$name}"),
        };
    }
}
