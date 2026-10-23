<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Form;

use Closure;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Regex;
use TMSWP\Core\Form\Rule\RuleInterface;

final class KanaTextField
{
    private function __construct() {}

    /** @param list<RuleInterface> $rules */
    public static function create(
        string $name,
        string $label,
        KanaType $type = KanaType::Katakana,
        array $rules = [],
        int $maxLength = 100,
    ): Field {
        $pattern = $type === KanaType::Katakana
            ? '/\A[ァ-ヶー・\s\x{3000}]*\z/u'
            : '/\A[ぁ-んー・\s\x{3000}]*\z/u';
        $message = $type === KanaType::Katakana
            ? __('Please enter {label} in katakana.', 'tmswp')
            : __('Please enter {label} in hiragana.', 'tmswp');
        $mode = $type === KanaType::Katakana ? 'KVC' : 'KVc';

        return Field::text(
            $name,
            $label,
            [...$rules, new Regex($pattern, $message), new MaxLength($maxLength)],
            filters: [self::convertFilter($mode)],
        );
    }

    /** @return Closure(string): string */
    private static function convertFilter(string $mode): Closure
    {
        return static fn(string $value): string => mb_convert_kana($value, $mode, 'UTF-8');
    }
}
