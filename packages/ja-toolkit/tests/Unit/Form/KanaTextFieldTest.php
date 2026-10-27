<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Tests\Unit\Form;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\JaToolkit\Form\KanaTextField;
use TMSWP\JaToolkit\Form\KanaType;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;
use WP;

/**
 * KanaTextFieldの正規化(前後の空白の除去の後のmb_convert_kanaと、ヴ・ゔ等の変換)と、正規化の後の検証。
 *
 * 入力値とメッセージの日本語は、テストの対象そのもの(かなの文字種と、その違反)である。
 */
final class KanaTextFieldTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_salt')->justReturn('secret');
        Functions\when('nocache_headers')->justReturn();
        // フロントの要求。Formはhandle()で、POSTで送る名前を公開のクエリ変数と照らす(照合はtmswp/coreのテスト)
        Functions\when('is_admin')->justReturn(false);
        $wp = Mockery::mock(WP::class);
        self::assertInstanceOf(WP::class, $wp);
        $wp->public_query_vars = [];
        $GLOBALS['wp'] = $wp;
        $GLOBALS['wpdb'] = WpdbTestDouble::create();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['wp']);

        parent::tearDown();
    }

    /** @return iterable<string, array{KanaType, string, string}> */
    public static function acceptedValues(): iterable
    {
        yield '半角カタカナを全角にする' => [KanaType::Katakana, ' ﾀﾛｳ ', 'タロウ'];
        yield 'ひらがなをカタカナにする' => [KanaType::Katakana, ' たろう ', 'タロウ'];
        yield 'カタカナの項目のゔ・ゕ・ゖをヴ・ヵ・ヶにする' => [KanaType::Katakana, 'ゔぁ ゕゖ', 'ヴァ ヵヶ'];
        yield 'カタカナの項目の全角スペースを半角にする' => [KanaType::Katakana, 'ヤマダ　タロウ', 'ヤマダ タロウ'];
        yield 'カタカナの項目の長音符と中黒を通す' => [KanaType::Katakana, 'ジョン・スミー', 'ジョン・スミー'];
        yield 'カタカナをひらがなにする' => [KanaType::Hiragana, ' アイウ ', 'あいう'];
        yield 'ひらがなの項目のヴ・ヵ・ヶをゔ・ゕ・ゖにする' => [KanaType::Hiragana, 'ヴィ ヵヶ', 'ゔぃ ゕゖ'];
        yield 'ひらがなの項目の半角カタカナをひらがなにする' => [KanaType::Hiragana, 'ﾔﾏﾀﾞ　ﾊﾟﾊﾟ', 'やまだ ぱぱ'];
        yield 'ひらがなの項目の半角のヴを1文字のゔにする' => [KanaType::Hiragana, 'ｳﾞｨ', 'ゔぃ'];
        yield 'ひらがなの項目のゔをそのまま通す' => [KanaType::Hiragana, 'ゔ', 'ゔ'];
        yield 'ひらがなの項目の全角スペースを半角にする' => [KanaType::Hiragana, 'やまだ　たろう', 'やまだ たろう'];
    }

    #[DataProvider('acceptedValues')]
    public function testValueIsNormalizedAndAccepted(KanaType $type, string $input, string $expected): void
    {
        $result = $this->submit(KanaTextField::create('kana_name', 'Kana', $type, maxLength: 20), $input);

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame($expected, $result->values['kana_name']);
    }

    /** @return iterable<string, array{KanaType, string, string}> */
    public static function rejectedValues(): iterable
    {
        $katakana = 'Please enter Kana in katakana.';
        $hiragana = 'Please enter Kana in hiragana.';

        yield 'カタカナの項目の改行' => [KanaType::Katakana, "ヤマダ\nタロウ", $katakana];
        yield 'カタカナの項目の改行(CRLF)' => [KanaType::Katakana, "ヤマダ\r\nタロウ", $katakana];
        yield 'カタカナの項目のタブ' => [KanaType::Katakana, "ヤマダ\tタロウ", $katakana];
        yield 'カタカナの項目の漢字' => [KanaType::Katakana, '山田タロウ', $katakana];
        yield 'カタカナの項目の英字' => [KanaType::Katakana, 'Taro', $katakana];
        yield 'カタカナの項目の数字' => [KanaType::Katakana, 'タロウ1', $katakana];
        yield 'カタカナの項目の単独の濁点' => [KanaType::Katakana, 'ワ゛', $katakana];
        yield 'ひらがなの項目の改行' => [KanaType::Hiragana, "やまだ\nたろう", $hiragana];
        yield 'ひらがなの項目の漢字' => [KanaType::Hiragana, '山田たろう', $hiragana];
        yield 'ひらがなの項目の英字' => [KanaType::Hiragana, 'taro', $hiragana];
    }

    #[DataProvider('rejectedValues')]
    public function testValueThatIsNotKanaIsRejected(KanaType $type, string $input, string $message): void
    {
        $result = $this->submit(KanaTextField::create('kana_name', 'Kana', $type, maxLength: 20), $input);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['kana_name' => [$message]], $result->fieldErrors);
    }

    /** 文字数は正規化の後の値で数える。半角の濁点が1文字になった後の値が上限以内なら通る。 */
    public function testMaxLengthCountsTheNormalizedValue(): void
    {
        $field = KanaTextField::create('kana_name', 'Kana', KanaType::Katakana, maxLength: 3);

        self::assertSame('ガギグ', $this->submit($field, 'ｶﾞｷﾞｸﾞ')->values['kana_name']);

        $result = $this->submit($field, 'ガギグゲ');

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['kana_name' => ['Kana must be 3 characters or fewer.']], $result->fieldErrors);
    }

    /** 呼び出し側の規則(Required等)は、かなの検証より先に評価される。 */
    public function testCallerRulesAreEvaluatedFirst(): void
    {
        $field = KanaTextField::create('kana_name', 'Kana', KanaType::Katakana, [new Required()]);

        self::assertSame(['kana_name' => ['Kana is required.']], $this->submit($field, '　')->fieldErrors);
    }

    /**
     * メッセージは、項目を作った時点ではなく検証の時点で訳す(A6-15)。initより前に作ったフォームの定義でも、検証の
     * 時点で読み込んだ訳が出る。
     */
    public function testMessageIsTranslatedWhenValidatingNotWhenCreating(): void
    {
        $field = KanaTextField::create('kana_name', 'フリガナ', KanaType::Katakana);

        Functions\when('__')->alias(static fn(string $text): string => match ($text) {
            'Please enter {label} in katakana.' => '{label}は全角カタカナで入力してください。',
            default => $text,
        });

        self::assertSame(['kana_name' => ['フリガナは全角カタカナで入力してください。']], $this->submit($field, '山田')->fieldErrors);
    }

    private function submit(Field $field, string $value): FormResult
    {
        $form = new Form(FormDefinition::create('kana')->add($field));

        return $form->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'kana',
            Form::FIELD_NONCE => 'nonce',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('kana'),
            'kana_name' => $value,
        ]));
    }
}
