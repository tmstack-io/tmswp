# フォームを実装する

フォーム定義は `app/Form/` に置き、テンプレートはWordPress標準のページテンプレートで作る。
CSRF・確認画面トークン・バリデーションはFWに任せる。

## 手順

1. `app/Form/ContactForm.php` を作る。
2. ページテンプレートでフォームを生成し、`handle()` を呼ぶ。
3. 完了時の保存・メール送信は `onComplete()` に閉じ込める。

## 完全な実装例

`app/Form/ContactForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\Required;

final class ContactForm
{
    public static function create(): Form
    {
        return new Form(
            FormDefinition::create('contact')
                ->text('name', 'お名前', [new Required()])
                ->text('email', 'メールアドレス', [new Required(), new Email()])
                ->textarea('message', 'お問い合わせ内容', [new Required()])
                ->confirm()
        );
    }
}
```

テンプレート:

```php
<?php

declare(strict_types=1);

$form = \App\Form\ContactForm::create();
$result = $form->handle();

get_header();
echo $form->open();
echo $form->field('name')->labelTag();
echo $form->field('name')->input();
echo $form->field('name')->errors();
echo $form->field('email')->labelTag();
echo $form->field('email')->input(['type' => 'email']);
echo $form->field('email')->errors();
echo $form->field('message')->labelTag();
echo $form->field('message')->textarea();
echo $form->field('message')->errors();
echo '<button type="submit">送信</button>';
echo $form->close();
get_footer();
```

## してはいけないこと

- `$_POST` を直接信用しない。
- hidden値だけで確認画面状態を保持しない。
- セッション前提のフォームにしない。

## 複合フィールド(CompositeField)を自作する

年月日を別々の `<select>` で入力させるなど、複数の入力部品を1つの値として扱う場合は `CompositeField` を継承したフィールドを app 側で定義する。`parts()` は入力部品、`compose()` は送信値から保存用の正準値、`decompose()` は正準値から入力部品の値を返す。部品がすべて空なら未入力、部品の一部だけが空なら `incompleteMessage()`、部品が揃った後の整合性は `validateParts()` で検証する。

`app/Form/BirthDateField.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Rule\RuleInterface;

final class BirthDateField extends CompositeField
{
    /** @param list<RuleInterface> $rules */
    public function __construct(
        string $name,
        string $label,
        array $rules = [],
        private readonly int $minYear = 1940,
        private readonly ?int $maxYear = null,
        private readonly bool $withDay = true,
    ) {
        parent::__construct($name, $label, $rules);
    }

    public function parts(): array
    {
        $parts = [
            'year' => Field::select($this->name . '_year', '年', $this->yearChoices()),
            'month' => Field::select($this->name . '_month', '月', $this->rangeChoices(1, 12)),
        ];

        if ($this->withDay) {
            $parts['day'] = Field::select($this->name . '_day', '日', $this->rangeChoices(1, 31));
        }

        return $parts;
    }

    public function compose(array $partValues): string
    {
        $year = (int) ($partValues['year'] ?? 0);
        $month = (int) ($partValues['month'] ?? 0);
        $day = $this->withDay ? (int) ($partValues['day'] ?? 0) : 1;

        if ($year < 1 || $month < 1 || $day < 1) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public function decompose(string $value): array
    {
        if ($value === '' || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $matches) !== 1) {
            return $this->emptyParts();
        }

        $parts = [
            'year' => (string) (int) $matches[1],
            'month' => (string) (int) $matches[2],
        ];

        if ($this->withDay) {
            $parts['day'] = (string) (int) $matches[3];
        }

        return $parts;
    }

    public function display(string $value): string
    {
        $parts = $this->decompose($value);

        if ($parts['year'] === '' || $parts['month'] === '' || ($this->withDay && $parts['day'] === '')) {
            return '';
        }

        if ($this->withDay) {
            return "{$parts['year']}年{$parts['month']}月{$parts['day']}日";
        }

        return "{$parts['year']}年{$parts['month']}月";
    }

    public function validateParts(array $partValues): bool
    {
        $year = (int) ($partValues['year'] ?? 0);
        $month = (int) ($partValues['month'] ?? 0);
        $day = $this->withDay ? (int) ($partValues['day'] ?? 0) : 1;

        return checkdate($month, $day, $year);
    }

    public function invalidPartsMessage(): string
    {
        return __('{label}が正しい日付ではありません。', 'tmswp');
    }

    /** @return array<string, string> */
    private function emptyParts(): array
    {
        $parts = ['year' => '', 'month' => ''];

        if ($this->withDay) {
            $parts['day'] = '';
        }

        return $parts;
    }

    /** @return array<array-key, string> */
    private function yearChoices(): array
    {
        $choices = ['' => '----'];
        $maxYear = $this->maxYear ?? (int) date('Y');

        for ($year = $maxYear; $year >= $this->minYear; --$year) {
            $choices[(string) $year] = (string) $year;
        }

        return $choices;
    }

    /** @return array<array-key, string> */
    private function rangeChoices(int $min, int $max): array
    {
        $choices = ['' => '--'];

        for ($value = $min; $value <= $max; ++$value) {
            $choices[(string) $value] = (string) $value;
        }

        return $choices;
    }
}
```

## 確認方法

1. `composer qa` が通ること。
2. 空送信でバリデーションエラーが表示されること。
3. 入力→確認→完了が通ること。
