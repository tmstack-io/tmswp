<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\Rule\Callback;
use TMSWP\Core\Form\Rule\Choice;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\FileExtension;
use TMSWP\Core\Form\Rule\MaxFileSize;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\MinLength;
use TMSWP\Core\Form\Rule\Range;
use TMSWP\Core\Form\Rule\Regex;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\Rule\RequiredIf;
use TMSWP\Core\Form\Rule\Retype;
use TMSWP\Core\Form\Rule\UploadFile;
use TMSWP\Core\Form\Rule\Url;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\Validator;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class FormValidationTest extends UnitTestCase
{
    public function testScalarRulesValidateAndSkipOptionalEmptyValues(): void
    {
        $definition = FormDefinition::create('contact')
            ->text('name', '名前', [new Required(), new MaxLength(3)])
            ->text('code', 'コード', [new MinLength(2), new Regex('/\A[A-Z]+\z/u')])
            ->text('email', 'メール', [new Email()])
            ->text('url', 'URL', [new Url()])
            ->text('age', '年齢', [new Range(18, 65)])
            ->text('kind', '種別', [new Choice(['a', 'b'])])
            ->text('note', '備考', [new Callback(static fn(mixed $value): bool|string => $value === 'ok' ? true : '備考が不正です。')]);

        $validator = new Validator();

        self::assertSame([], $validator->validate($definition, [
            'name' => '0',
            'code' => 'AB',
            'email' => '',
            'url' => 'https://example.com',
            'age' => '20',
            'kind' => 'a',
            'note' => 'ok',
        ]));

        $errors = $validator->validate($definition, [
            'name' => 'abcd',
            'code' => 'a',
            'email' => 'invalid',
            'url' => 'ftp://example.com',
            'age' => '80',
            'kind' => 'x',
            'note' => 'ng',
        ]);

        self::assertSame('名前 must be 3 characters or fewer.', $errors['name'][0]);
        self::assertSame('コード must be at least 2 characters.', $errors['code'][0]);
        self::assertSame('Please enter a valid email address for メール.', $errors['email'][0]);
        self::assertSame('Please enter a valid URL for URL.', $errors['url'][0]);
        self::assertSame('年齢 must be between 18 and 65.', $errors['age'][0]);
        self::assertSame('種別 has an invalid choice.', $errors['kind'][0]);
        self::assertSame('備考が不正です。', $errors['note'][0]);
    }

    public function testRequiredIfRetypeAndMultipleChoiceValidation(): void
    {
        $definition = FormDefinition::create('account')
            ->select('type', '種別', ['personal' => '個人', 'company' => '法人'])
            ->text('company', '会社名', [RequiredIf::equals('type', 'company')])
            ->password('password', 'パスワード')
            ->password('password_confirmation', '確認用パスワード', [new Retype('password')])
            ->checkboxes('topics', '関心', ['news' => 'ニュース', 'docs' => '資料'], [new Required()]);

        $errors = (new Validator())->validate($definition, [
            'type' => 'company',
            'company' => '',
            'password' => 'secret',
            'password_confirmation' => 'different',
            'topics' => ['news', 'bad'],
        ]);

        self::assertSame('会社名 is required.', $errors['company'][0]);
        self::assertSame('確認用パスワード must match パスワード.', $errors['password_confirmation'][0]);
        self::assertSame('関心 has an invalid choice.', $errors['topics'][0]);
    }

    public function testInvalidRegexPatternThrowsConfigException(): void
    {
        $this->expectException(FormConfigException::class);

        (new Validator())->validate(
            FormDefinition::create('search')->text('keyword', 'キーワード', [new Regex('/[/')]),
            ['keyword' => 'text'],
        );
    }

    public function testRuleMessagesPassThroughFilterBeforePlaceholderReplacement(): void
    {
        Functions\expect('apply_filters')
            ->once()
            ->with('tmswp/form/rule_message', '{label} must be {length} characters or fewer.', Mockery::type(MaxLength::class), Mockery::any())
            ->andReturn('{label}: max {length} chars');

        $errors = (new Validator())->validate(
            FormDefinition::create('contact')->text('name', '名前', [new MaxLength(3)]),
            ['name' => 'abcd'],
        );

        self::assertSame('名前: max 3 chars', $errors['name'][0]);
    }

    public function testFileRulesAcceptStoredFileAndRejectInvalidFileState(): void
    {
        $path = $this->createTempDirectory('stored-file') . '/document.pdf';
        file_put_contents($path, 'pdf');

        $definition = FormDefinition::create('upload')
            ->file('file', 'ファイル', [new UploadFile(), new FileExtension(['pdf']), new MaxFileSize(10)]);
        $validator = new Validator();

        self::assertSame([], $validator->validate($definition, [
            'file' => new StoredFile('0123456789abcdef0123456789abcdef', $path, 'document.pdf', 'application/pdf', 3),
        ]));

        $errors = $validator->validate($definition, [
            'file' => new StoredFile('0123456789abcdef0123456789abcdef', $path . '.missing', 'document.txt', 'text/plain', 20),
        ]);

        self::assertSame('The file upload failed.', $errors['file'][0]);
    }

    public function testCompositeDateValidationAndCanonicalFormValue(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_salt')->justReturn('secret');

        $definition = FormDefinition::create('profile')
            ->add(new ValidationTestDateField('birth', '生年月日', [new Required()], minYear: 2000, maxYear: 2026));
        $validator = new Validator();

        self::assertSame(
            'Please complete all parts of 生年月日.',
            $validator->validate($definition, ['birth' => ['year' => '2026', 'month' => '', 'day' => '1']])['birth'][0],
        );
        self::assertSame(
            '生年月日が正しい日付ではありません。',
            $validator->validate($definition, ['birth' => ['year' => '2026', 'month' => '2', 'day' => '31']])['birth'][0],
        );

        $form = new Form($definition);
        $result = $form->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'profile',
            Form::FIELD_NONCE => 'nonce',
            Form::FIELD_STEP => 'submit',
            'birth' => ['year' => '2026', 'month' => '2', 'day' => '3'],
        ]));

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame('2026-02-03', $result->values['birth']);
    }
}

final class ValidationTestDateField extends CompositeField
{
    /** @param list<\TMSWP\Core\Form\Rule\RuleInterface> $rules */
    public function __construct(
        string $name,
        string $label,
        array $rules = [],
        private readonly int $minYear = 1940,
        private readonly ?int $maxYear = null,
    ) {
        parent::__construct($name, $label, $rules);
    }

    public function parts(): array
    {
        return [
            'year' => Field::select($this->name . '_year', '年', $this->yearChoices()),
            'month' => Field::select($this->name . '_month', '月', $this->rangeChoices(1, 12)),
            'day' => Field::select($this->name . '_day', '日', $this->rangeChoices(1, 31)),
        ];
    }

    public function compose(array $partValues): string
    {
        return \sprintf('%04d-%02d-%02d', (int) $partValues['year'], (int) $partValues['month'], (int) $partValues['day']);
    }

    public function decompose(string $value): array
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $matches) !== 1) {
            return ['year' => '', 'month' => '', 'day' => ''];
        }

        return ['year' => (string) (int) $matches[1], 'month' => (string) (int) $matches[2], 'day' => (string) (int) $matches[3]];
    }

    public function validateParts(array $partValues): bool
    {
        return checkdate((int) $partValues['month'], (int) $partValues['day'], (int) $partValues['year']);
    }

    public function invalidPartsMessage(): string
    {
        return __('{label}が正しい日付ではありません。', 'tmswp');
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
