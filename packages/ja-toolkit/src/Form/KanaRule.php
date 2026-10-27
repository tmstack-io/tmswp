<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Form;

use TMSWP\Core\Form\Rule\Rule;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

/**
 * 値が、KanaTypeの文字種のかなと、長音符(ー)・中黒(・)・半角スペースだけからなることを検証する。KanaTextFieldが
 * 項目に加える。改行・タブ・全角スペースは通さない(全角スペースはKanaTextFieldの正規化で半角になる)。
 *
 * メッセージは、検証の時点でdefaultMessage()から__()で解決する。フォームの定義をinitより前(訳の読み込みの前)に
 * 作っても、検証の時点で読み込んだ訳が出る。
 */
final class KanaRule extends Rule
{
    /** 全角カタカナ(ァ(U+30A1)〜ヶ(U+30F6)。ヴ・ヵ・ヶを含む)・長音符・中黒・半角スペース */
    private const KATAKANA_PATTERN = '/\A[\x{30A1}-\x{30F6}\x{30FC}\x{30FB} ]*\z/u';

    /** ひらがな(ぁ(U+3041)〜ゖ(U+3096)。ゔ・ゕ・ゖを含む)・長音符・中黒・半角スペース */
    private const HIRAGANA_PATTERN = '/\A[\x{3041}-\x{3096}\x{30FC}\x{30FB} ]*\z/u';

    public function __construct(private readonly KanaType $type)
    {
        parent::__construct();
    }

    /**
     * 不正なUTF-8の値は、preg_match()がfalseを返すため検証に失敗する(Formは規則の検証より前に、不正なUTF-8の値を拒む)。
     */
    protected function passes(mixed $value, ValidationContext $context): bool
    {
        $text = Validator::stringValue($value);
        $pattern = $this->type === KanaType::Katakana ? self::KATAKANA_PATTERN : self::HIRAGANA_PATTERN;

        return $text !== null && preg_match($pattern, $text) === 1;
    }

    public function defaultMessage(): string
    {
        return $this->type === KanaType::Katakana
            ? __('Please enter {label} in katakana.', 'tmswp')
            : __('Please enter {label} in hiragana.', 'tmswp');
    }
}
