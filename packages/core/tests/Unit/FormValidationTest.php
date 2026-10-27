<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Closure;
use Mockery;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\LogicException;
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
use TMSWP\Core\Form\Rule\RuleInterface;
use TMSWP\Core\Form\Rule\Url;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

final class FormValidationTest extends UnitTestCase
{
    public function testScalarRulesValidateAndSkipOptionalEmptyValues(): void
    {
        $definition = FormDefinition::create('contact')
            ->text('name', 'Name', [new Required(), new MaxLength(3)])
            ->text('code', 'Code', [new MinLength(2), new Regex('/\A[A-Z]+\z/u')])
            ->text('email', 'Email', [new Email()])
            ->text('url', 'URL', [new Url()])
            ->text('age', 'Age', [new Range(18, 65)])
            ->text('kind', 'Type', [new Choice(['a', 'b'])])
            ->text('note', 'Note', [new Callback(static fn(mixed $value): bool|string => $value === 'ok' ? true : 'Note is not valid.')]);

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

        self::assertSame('Name must be 3 characters or fewer.', $errors['name'][0]);
        self::assertSame('Code must be at least 2 characters.', $errors['code'][0]);
        self::assertSame('Please enter a valid email address for Email.', $errors['email'][0]);
        self::assertSame('Please enter a valid URL for URL.', $errors['url'][0]);
        self::assertSame('Age must be between 18 and 65.', $errors['age'][0]);
        self::assertSame('Type has an invalid choice.', $errors['kind'][0]);
        self::assertSame('Note is not valid.', $errors['note'][0]);
    }

    public function testRequiredIfRetypeAndMultipleChoiceValidation(): void
    {
        $definition = FormDefinition::create('account')
            ->select('type', 'Type', ['personal' => 'Personal', 'company' => 'Company'])
            ->text('company', 'Company name', [RequiredIf::equals('type', 'company')])
            ->password('password', 'Password')
            ->password('password_confirmation', 'Password confirmation', [new Retype('password')])
            ->checkboxes('topics', 'Topics', ['news' => 'News', 'docs' => 'Docs'], [new Required()]);

        $errors = (new Validator())->validate($definition, [
            'type' => 'company',
            'company' => '',
            'password' => 'secret',
            'password_confirmation' => 'different',
            'topics' => ['news', 'bad'],
        ]);

        self::assertSame('Company name is required.', $errors['company'][0]);
        self::assertSame('Password confirmation must match Password.', $errors['password_confirmation'][0]);
        self::assertSame('Topics has an invalid choice.', $errors['topics'][0]);
    }

    public function testInvalidRegexPatternThrowsConfigException(): void
    {
        $this->expectException(FormConfigException::class);

        (new Validator())->validate(
            FormDefinition::create('search')->text('keyword', 'Keyword', [new Regex('/[/')]),
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
            FormDefinition::create('contact')->text('name', 'Name', [new MaxLength(3)]),
            ['name' => 'abcd'],
        );

        self::assertSame('Name: max 3 chars', $errors['name'][0]);
    }

    public function testFileRulesAcceptStoredFileAndRejectItsExtensionAndSize(): void
    {
        Functions\expect('size_format')->once()->with(10)->andReturn('10 B');
        $path = $this->createTempDirectory('stored-file') . '/document.pdf';
        file_put_contents($path, 'pdf');

        $definition = FormDefinition::create('upload')
            ->file('file', 'File', [new FileExtension(['pdf'])])
            ->file('photo', 'Photo', [new MaxFileSize(10)]);
        $validator = new Validator();

        self::assertSame([], $validator->validate($definition, [
            'file' => new StoredFile('0123456789abcdef0123456789abcdef', $path, 'document.pdf', 'application/pdf', 3),
            'photo' => new StoredFile('0123456789abcdef0123456789abcdef', $path, 'document.pdf', 'application/pdf', 10),
        ]));

        self::assertSame(
            ['file' => ['Please upload File as one of these file types: pdf.'], 'photo' => ['Photo must be 10 B or smaller.']],
            $validator->validate($definition, [
                'file' => new StoredFile('0123456789abcdef0123456789abcdef', $path, 'document.txt', 'text/plain', 3),
                'photo' => new StoredFile('0123456789abcdef0123456789abcdef', $path, 'document.pdf', 'application/pdf', 11),
            ]),
        );
    }

    public function testMaxFileSizeInMegabytesTakesACustomMessageAndFormatsTheSizeWithSizeFormat(): void
    {
        Functions\expect('size_format')->twice()->with(2 * 1024 * 1024)->andReturn('2 MB');
        $file = new StoredFile('0123456789abcdef0123456789abcdef', '/tmp/large.pdf', 'large.pdf', 'application/pdf', 3 * 1024 * 1024);
        $definition = FormDefinition::create('upload')
            ->file('default', 'Default', [MaxFileSize::mb(2)])
            ->file('custom', 'Custom', [MaxFileSize::mb(2, message: '{label} is over {size}.')]);

        self::assertSame(
            ['default' => ['Default must be 2 MB or smaller.'], 'custom' => ['Custom is over 2 MB.']],
            (new Validator())->validate($definition, ['default' => $file, 'custom' => $file]),
        );
    }

    public function testSingleCheckboxAcceptsOnlyItsCheckedValue(): void
    {
        $definition = FormDefinition::create('signup')
            ->checkbox('privacy', 'Privacy policy', 'agree', [new Required()])
            ->checkbox('newsletter', 'Newsletter');
        $validator = new Validator();

        self::assertSame([], $validator->validate($definition, ['privacy' => 'agree', 'newsletter' => '1']));
        self::assertSame([], $validator->validate($definition, ['privacy' => 'agree', 'newsletter' => '']));
        self::assertSame(
            ['privacy' => ['Privacy policy has an invalid choice.'], 'newsletter' => ['Newsletter has an invalid choice.']],
            $validator->validate($definition, ['privacy' => 'any string', 'newsletter' => 'agree']),
        );
        self::assertSame(['privacy' => ['Privacy policy is required.']], $validator->validate($definition, ['privacy' => '', 'newsletter' => '']));
    }

    public function testRequiredIfEqualsMatchesAnElementOfACheckboxesValue(): void
    {
        $definition = FormDefinition::create('inquiry')
            ->checkboxes('topics', 'Topics', ['product' => 'Product', 'other' => 'Other'])
            ->text('other_detail', 'Other detail', [RequiredIf::equals('topics', 'other')]);
        $validator = new Validator();

        self::assertSame(
            ['other_detail' => ['Other detail is required.']],
            $validator->validate($definition, ['topics' => ['product', 'other'], 'other_detail' => '']),
        );
        self::assertSame([], $validator->validate($definition, ['topics' => ['product'], 'other_detail' => '']));
        self::assertSame([], $validator->validate($definition, ['topics' => [], 'other_detail' => '']));
        self::assertSame([], $validator->validate($definition, ['topics' => ['other'], 'other_detail' => 'Details']));
    }

    public function testScalarValueOfAMultipleFieldIsValidatedAsASingleElementArray(): void
    {
        $definition = FormDefinition::create('inquiry')
            ->checkboxes('length', 'Length', ['long' => 'Long', 'ok' => 'OK'], [new Required(), new MaxLength(3)])
            ->checkboxes('format', 'Format', ['abc' => 'ABC', 'a1' => 'A1'], [new Regex('/\A[a-z]+\z/')])
            ->checkboxes('required', 'Required', ['a' => 'A'], [new Required()]);
        $validator = new Validator();

        foreach ([
            [['length' => 'long', 'format' => 'a1', 'required' => ''], ['length' => ['long'], 'format' => ['a1'], 'required' => []]],
            [['length' => 'ok', 'format' => 'abc', 'required' => 'a'], ['length' => ['ok'], 'format' => ['abc'], 'required' => ['a']]],
            [['length' => 'other', 'format' => null, 'required' => null], ['length' => ['other'], 'format' => [], 'required' => []]],
        ] as [$scalars, $arrays]) {
            $expected = $validator->validate($definition, $arrays);

            self::assertSame($expected, $validator->validate($definition, $scalars), json_encode($scalars, JSON_THROW_ON_ERROR));
        }

        self::assertSame(
            [
                'length' => ['Length must be 3 characters or fewer.'],
                'format' => ['Format has an invalid format.'],
                'required' => ['Required is required.'],
            ],
            $validator->validate($definition, ['length' => 'long', 'format' => 'a1', 'required' => '']),
        );
    }

    /**
     * 複数選択の項目への MaxLength は、先頭だけでなく全要素に適用する。先頭は正しく、2番目以降だけが長さの違反になる
     * 値(どの要素も選択肢の中)で確かめる。
     */
    public function testMaxLengthOfAMultipleFieldChecksEveryElementNotOnlyTheFirst(): void
    {
        $definition = FormDefinition::create('inquiry')
            ->checkboxes('length', 'Length', ['ok' => 'OK', 'yes' => 'Yes', 'long' => 'Long'], [new MaxLength(3)]);
        $validator = new Validator();

        self::assertSame([], $validator->validate($definition, ['length' => ['ok', 'yes']]));
        self::assertSame(['length' => ['Length must be 3 characters or fewer.']], $validator->validate($definition, ['length' => ['ok', 'long']]));
        self::assertSame(['length' => ['Length must be 3 characters or fewer.']], $validator->validate($definition, ['length' => ['ok', 'yes', 'long']]));
    }

    public function testCallbackReturningNeitherBoolNorStringThrows(): void
    {
        $callback = require __DIR__ . '/../fixtures/form-validation/callback-returning-null.php';
        self::assertInstanceOf(Callback::class, $callback);
        $definition = FormDefinition::create('contact')->text('note', 'Note', [$callback]);

        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage('the Callback rule must return bool or string, got null');

        (new Validator())->validate($definition, ['note' => 'text']);
    }

    public function testEveryRuleMessageIsResolvedThroughTheFilterBeforePlaceholderReplacement(): void
    {
        $filtered = [];
        Filters\expectApplied('tmswp/form/rule_message')
            ->times(6)
            ->andReturnUsing(static function (string $message, RuleInterface $rule, ValidationContext $context) use (&$filtered): string {
                $filtered[] = [$context->currentField, $rule::class];

                return "[{label}] {$message}";
            });

        $definition = FormDefinition::create('account')
            ->text('nickname', 'Nickname', [new Callback(static fn(mixed $value): bool => false)])
            ->text('code', 'Code', [new Callback(static fn(mixed $value): string => '{label} is taken.')])
            ->text('email', 'Email')
            ->text('email_confirmation', 'Email confirmation', [new Retype('email')])
            ->select('plan', 'Plan', ['free' => 'Free'])
            ->checkbox('privacy', 'Privacy policy')
            ->text('name', 'Name', [new Required(message: '{label} please.')]);

        $errors = (new Validator())->validate($definition, [
            'nickname' => 'taro',
            'code' => 'abc',
            'email' => 'a@example.com',
            'email_confirmation' => 'b@example.com',
            'plan' => 'paid',
            'privacy' => 'yes',
            'name' => '',
        ]);

        self::assertSame([
            'nickname' => ['[Nickname] Nickname is invalid.'],
            'code' => ['[Code] Code is taken.'],
            'email_confirmation' => ['[Email confirmation] Email confirmation must match Email.'],
            'plan' => ['[Plan] Plan has an invalid choice.'],
            'privacy' => ['[Privacy policy] Privacy policy has an invalid choice.'],
            'name' => ['[Name] Name please.'],
        ], $errors);
        self::assertSame([
            ['nickname', Callback::class],
            ['code', Callback::class],
            ['email_confirmation', Retype::class],
            ['plan', Choice::class],
            ['privacy', Choice::class],
            ['name', Required::class],
        ], $filtered);
    }

    public function testRuleMessageFilterReturningANonStringThrows(): void
    {
        Filters\expectApplied('tmswp/form/rule_message')->once()->andReturn(null);

        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage('the filter tmswp/form/rule_message must return a string, got null');

        (new Validator())->validate(FormDefinition::create('contact')->text('name', 'Name', [new Required()]), ['name' => '']);
    }

    public function testCompositeDateValidationAndCanonicalFormValue(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_salt')->justReturn('secret');

        $definition = FormDefinition::create('profile')
            ->add(new ValidationTestDateField('birth', 'Birth date', [new Required()], minYear: 2000, maxYear: 2026));
        $validator = new Validator();

        self::assertSame(
            'Please complete all parts of Birth date.',
            $validator->validate($definition, ['birth' => ['year' => '2026', 'month' => '', 'day' => '1']])['birth'][0],
        );
        self::assertSame(
            'Birth date is not a valid date.',
            $validator->validate($definition, ['birth' => ['year' => '2026', 'month' => '2', 'day' => '31']])['birth'][0],
        );

        Functions\when('nocache_headers')->justReturn();
        $GLOBALS['wpdb'] = WpdbTestDouble::create();

        try {
            $form = new Form($definition);
            $result = $form->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'profile',
                Form::FIELD_NONCE => 'nonce',
                Form::FIELD_STEP => 'submit',
                Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('profile'),
                'birth' => ['year' => '2026', 'month' => '2', 'day' => '3'],
            ]));
        } finally {
            unset($GLOBALS['wpdb']);
        }

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame('2026-02-03', $result->values['birth']);
    }

    /**
     * app 側の複合フィールド(生年月日)。正準値は compose(decompose($v)) === $v で往復し、部分入力と存在しない日付
     * (2/30・平年の 2/29)は項目のエラーになる。
     */
    public function testCompositeDateRoundTripsItsCanonicalValueAndRejectsPartialAndNonexistentDates(): void
    {
        $field = new ValidationTestDateField('birth', 'Birth date', [new Required()], minYear: 2000, maxYear: 2026);
        $definition = FormDefinition::create('profile')->add($field);
        $validator = new Validator();

        foreach (['2026-02-03', '2000-12-31', '2024-02-29'] as $value) {
            self::assertSame($value, $field->compose($field->decompose($value)));
            self::assertSame([], $validator->validate($definition, ['birth' => $field->decompose($value)]), $value);
        }

        foreach ([['2026', '2', '30'], ['2025', '2', '29']] as [$year, $month, $day]) {
            self::assertSame(
                ['birth' => ['Birth date is not a valid date.']],
                $validator->validate($definition, ['birth' => ['year' => $year, 'month' => $month, 'day' => $day]]),
            );
        }

        self::assertSame(
            ['birth' => ['Please complete all parts of Birth date.']],
            $validator->validate($definition, ['birth' => ['year' => '2026', 'month' => '', 'day' => '']]),
        );
    }

    public function testCompositePartsAreCheckedAgainstTheirChoices(): void
    {
        $definition = FormDefinition::create('profile')
            ->add(new ValidationTestDateField('birth', 'Birth date', [new Required()], minYear: 2000, maxYear: 2026));
        $validator = new Validator();

        foreach ([['1999', '1', '1'], ['2027', '1', '1'], ['2020', '13', '1'], ['2020', '1', '0']] as [$year, $month, $day]) {
            self::assertSame(
                ['birth' => ['Birth date has an invalid choice.']],
                $validator->validate($definition, ['birth' => ['year' => $year, 'month' => $month, 'day' => $day]]),
                "{$year}-{$month}-{$day}",
            );
        }

        self::assertSame([], $validator->validate($definition, ['birth' => ['year' => '2000', 'month' => '12', 'day' => '31']]));
    }

    public function testCompositePartsRunTheirOwnRulesAfterTheChoiceCheck(): void
    {
        $definition = FormDefinition::create('profile')->add(new ValidationTestCodeField('code', 'Code'));
        $validator = new Validator();

        self::assertSame(['code' => ['Code has an invalid choice.']], $validator->validate($definition, ['code' => ['area' => 'x', 'number' => '12']]));
        self::assertSame(['code' => ['Code has an invalid format.']], $validator->validate($definition, ['code' => ['area' => 'a', 'number' => '1x']]));
        self::assertSame([], $validator->validate($definition, ['code' => ['area' => 'a', 'number' => '12']]));
    }

    public function testCompositeFieldNameAndRulesAreCheckedAtDefinition(): void
    {
        foreach (['Birth', 'birth__token', '_tmswp_form'] as $name) {
            try {
                new ValidationTestCodeField($name, 'Code');
                self::fail("the name {$name} must be rejected");
            } catch (FormConfigException $e) {
                self::assertStringContainsString($name, $e->getMessage());
            }
        }

        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage('field rules must implement RuleInterface');

        $define = require __DIR__ . '/../fixtures/form-validation/composite-with-invalid-rules.php';
        self::assertInstanceOf(Closure::class, $define);
        $define();
    }

    public function testTrimFilterThrowsForInvalidUtf8InsteadOfReturningTheValueUntrimmed(): void
    {
        $trim = Field::text('name', 'Name')->filters[0];

        self::assertSame('Taro', $trim("\u{3000} Taro \u{3000}"));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the trim filter of a form field failed: Malformed UTF-8 characters, possibly incorrectly encoded');

        $trim(" a\xff ");
    }
}

final class ValidationTestDateField extends CompositeField
{
    /** @param list<RuleInterface> $rules */
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
            'year' => Field::select($this->name . '_year', 'Year', $this->yearChoices()),
            'month' => Field::select($this->name . '_month', 'Month', $this->rangeChoices(1, 12)),
            'day' => Field::select($this->name . '_day', 'Day', $this->rangeChoices(1, 31)),
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
        return __('{label} is not a valid date.', 'tmswp-tests');
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

final class ValidationTestCodeField extends CompositeField
{
    public function parts(): array
    {
        return [
            'area' => Field::select($this->name . '_area', 'Area', ['a' => 'A', 'b' => 'B']),
            'number' => Field::text($this->name . '_number', 'Number', [new Regex('/\\A\\d+\\z/')]),
        ];
    }

    public function compose(array $partValues): string
    {
        return $partValues['area'] . '-' . $partValues['number'];
    }

    public function decompose(string $value): array
    {
        $parts = explode('-', $value, 2);

        return ['area' => $parts[0], 'number' => $parts[1] ?? ''];
    }
}
