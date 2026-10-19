<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Tests\Unit;

use Brain\Monkey\Functions;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\JaToolkit\Form\KanaTextField;
use TMSWP\JaToolkit\Form\KanaType;

final class KanaTextFieldTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->alias(static fn(string $message): string => $message);
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_salt')->justReturn('secret');
    }

    public function testKatakanaFieldConvertsBeforeValidation(): void
    {
        $definition = FormDefinition::create('kana')
            ->add(KanaTextField::create('kana_name', 'カナ', KanaType::Katakana, maxLength: 20));
        $form = new Form($definition);

        $result = $form->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'kana',
            Form::FIELD_NONCE => 'nonce',
            Form::FIELD_STEP => 'submit',
            'kana_name' => ' ﾀﾛｳ ',
        ]));

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame('タロウ', $result->values['kana_name']);
    }

    public function testHiraganaFieldConvertsBeforeValidation(): void
    {
        $definition = FormDefinition::create('kana')
            ->add(KanaTextField::create('kana_name', 'かな', KanaType::Hiragana, maxLength: 20));
        $form = new Form($definition);

        $result = $form->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'kana',
            Form::FIELD_NONCE => 'nonce',
            Form::FIELD_STEP => 'submit',
            'kana_name' => ' アイウ ',
        ]));

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame('あいう', $result->values['kana_name']);
    }
}
