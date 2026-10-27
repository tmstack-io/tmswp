<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Form;

use Closure;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\RuleInterface;

/**
 * カナ入力の構成済みの項目(Field::text)を作る。WordPressのフックは登録しない。
 *
 * 違反のメッセージの訳は、JaToolkitを`KernelConfig`の`extensions`に登録したときに読み込まれる(モジュールは
 * 無くてよい)。登録しない場合、訳は保証しない。
 */
final class KanaTextField
{
    /** mb_convert_kana()が変換しない、ひらがなとカタカナの対 */
    private const HIRAGANA_TO_KATAKANA = ['ゔ' => 'ヴ', 'ゕ' => 'ヵ', 'ゖ' => 'ヶ'];

    private function __construct() {}

    /**
     * 値は、前後の空白の除去の後に正規化してから検証する。半角カタカナを全角に、濁点・半濁点を1文字にし
     * (`mb_convert_kana(..., 'KV')`)、その後に次の変換をする。2回に分けるのは、1回の`'KVc'`では半角カタカナが
     * ひらがなまで変換されないためである。
     *
     * - Katakana: ひらがなをカタカナに、全角スペースを半角にする(`'Cs'`と、ゔ・ゕ・ゖをヴ・ヵ・ヶにする変換)
     * - Hiragana: カタカナをひらがなに、全角スペースを半角にする(`'cs'`と、ヴ・ヵ・ヶをゔ・ゕ・ゖにする変換)
     *
     * @param list<RuleInterface> $rules KanaRule・MaxLengthより先に検証する規則(Required等)
     */
    public static function create(
        string $name,
        string $label,
        KanaType $type = KanaType::Katakana,
        array $rules = [],
        int $maxLength = 100,
    ): Field {
        return Field::text(
            $name,
            $label,
            [...$rules, new KanaRule($type), new MaxLength($maxLength)],
            filters: [self::convertFilter($type)],
        );
    }

    /** @return Closure(string): string */
    private static function convertFilter(KanaType $type): Closure
    {
        if ($type === KanaType::Katakana) {
            return static fn(string $value): string => strtr(mb_convert_kana(mb_convert_kana($value, 'KV', 'UTF-8'), 'Cs', 'UTF-8'), self::HIRAGANA_TO_KATAKANA);
        }

        return static fn(string $value): string => strtr(mb_convert_kana(mb_convert_kana($value, 'KV', 'UTF-8'), 'cs', 'UTF-8'), array_flip(self::HIRAGANA_TO_KATAKANA));
    }
}
