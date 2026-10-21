<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Tests\Unit;

use Brain\Monkey\Functions;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\Tests\Support\WpdbTestDouble;
use TMSWP\JaToolkit\Form\KanaTextField;
use TMSWP\JaToolkit\Form\KanaType;

final class KanaTextFieldTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_salt')->justReturn('secret');
        Functions\when('nocache_headers')->justReturn();
        $GLOBALS['wpdb'] = WpdbTestDouble::create();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);

        parent::tearDown();
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
            Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('kana'),
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
            Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('kana'),
            'kana_name' => ' アイウ ',
        ]));

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame('あいう', $result->values['kana_name']);
    }
}
