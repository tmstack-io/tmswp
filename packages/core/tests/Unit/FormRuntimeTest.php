<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\ConfirmToken;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\Tests\Support\WpdbTestDouble;

final class FormRuntimeTest extends UnitTestCase
{
    /** @var list<array<string, mixed>> */
    private array $completed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = $this->newWpdb();
        $this->completed = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);

        parent::tearDown();
    }

    public function testGetAndOtherFormPostReturnInputWithoutCsrf(): void
    {
        $definition = FormDefinition::create('contact')->text('name', '名前', default: '初期値');

        self::assertSame(FormStep::Input, (new Form($definition))->handle(new FormRequest('GET'))->step);
        self::assertSame('初期値', (new Form($definition))->values()['name']);

        $post = new FormRequest('POST', [Form::FIELD_FORM_ID => 'other', 'name' => 'ignored']);
        $result = (new Form($definition))->handle($post);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('初期値', $result->values['name']);
    }

    public function testCsrfFailureBindsValuesAndReturnsFormError(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(false);

        $form = new Form(FormDefinition::create('contact')->text('name', '名前'));
        $result = $form->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'bad',
            Form::FIELD_STEP => 'submit',
            'name' => ' Taro ',
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame(['Your session has expired. Please submit the form again.'], $result->formErrors);
    }

    public function testUnknownStepReturnsInputWithBoundValues(): void
    {
        $this->stubRuntimeFunctions();

        $result = (new Form(FormDefinition::create('contact')->text('name', '名前')))
            ->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'contact',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => 'unknown',
                'name' => ' Taro ',
            ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
    }

    public function testConfirmDisabledTreatsConfirmActionAsSubmit(): void
    {
        $this->stubRuntimeFunctions();

        $definition = FormDefinition::create('contact')
            ->text('name', '名前', [new Required()])
            ->onComplete(function (array $values): void {
                $this->completed[] = $values;
            });

        $form = new Form($definition);
        $result = $form->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => ' Taro ',
        ]));

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame([['name' => 'Taro']], $this->completed);
    }

    public function testConfirmSubmitConsumesJtiAndHandleIsIdempotent(): void
    {
        $this->stubRuntimeFunctions();

        $definition = FormDefinition::create('entry')
            ->text('name', '名前', [new Required()])
            ->add(new RuntimeTestDateField('birth', '生年月日', minYear: 2000, maxYear: 2026))
            ->confirm()
            ->onComplete(function (array $values): void {
                $this->completed[] = $values;
            });

        $confirm = new Form($definition);
        $confirmResult = $confirm->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => ' Taro ',
            'birth' => ['year' => '2026', 'month' => '6', 'day' => '13'],
        ]));
        $token = $this->confirmToken($confirm->open());

        self::assertSame(FormStep::Confirm, $confirmResult->step);
        self::assertSame('2026-06-13', $confirmResult->values['birth']);

        $submit = new Form($definition);
        $submitResult = $submit->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $token,
        ]));
        $secondCall = $submit->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $token,
        ]));

        self::assertSame(FormStep::Complete, $submitResult->step);
        self::assertSame($submitResult, $secondCall);
        self::assertSame([['name' => 'Taro', 'birth' => '2026-06-13']], $this->completed);

        $duplicate = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $token,
        ]));

        self::assertSame(FormStep::Input, $duplicate->step);
        self::assertSame(['This form has already been submitted.'], $duplicate->formErrors);
    }

    public function testInvalidAndExpiredConfirmTokensReturnInputState(): void
    {
        $this->stubRuntimeFunctions();

        $definition = FormDefinition::create('entry')
            ->text('name', '名前')
            ->add(new RuntimeTestDateField('birth', '生年月日'))
            ->confirm()
            ->confirmTtl(1);
        $expired = ConfirmToken::issue('entry', ['birth' => '2026-06-13', 'name' => 'Taro'], 'secret', time() - 10);

        $invalidResult = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => 'invalid',
        ]));
        self::assertSame(FormStep::Input, $invalidResult->step);
        self::assertSame(['The form could not be resubmitted. Please start again.'], $invalidResult->formErrors);

        $expiredResult = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $expired,
        ]));
        self::assertSame(FormStep::Input, $expiredResult->step);
        self::assertSame(['year' => '2026', 'month' => '6', 'day' => '13'], $expiredResult->values['birth']);
        self::assertSame(['The confirmation screen has expired. Please review your entries and continue again.'], $expiredResult->formErrors);
    }

    public function testBackRestoresSignedValuesAndStoredFileToken(): void
    {
        $this->stubRuntimeFunctions();

        $storeDir = $this->createTempDirectory('form-back-store');
        $token = '0123456789abcdef0123456789abcdef';
        $this->createStoredFile($storeDir, $token, 'resume.pdf', 'application/pdf', 'body');
        $store = new TempFileStore($storeDir);
        $definition = FormDefinition::create('entry')
            ->text('name', '名前')
            ->file('resume', '履歴書')
            ->confirm()
            ->tempFileStore($store);
        $confirmToken = ConfirmToken::issue('entry', [
            'name' => 'Taro',
            'resume' => ['__tmsfile' => $token],
        ], 'secret', time() - 10);

        $result = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'back',
            Form::FIELD_CONFIRM_TOKEN => $confirmToken,
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
        self::assertInstanceOf(StoredFile::class, $result->values['resume']);
        self::assertSame('resume.pdf', $result->values['resume']->clientName);
    }

    private function stubRuntimeFunctions(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_salt')->justReturn('secret');
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }

    private function confirmToken(string $html): string
    {
        if (preg_match('/name="_confirm_token" value="([^"]+)"/', $html, $matches) !== 1) {
            self::fail('確認トークンのhiddenフィールドが見つかりません。');
        }

        return $matches[1];
    }

    private function createStoredFile(string $storeDir, string $token, string $name, string $mime, string $contents): void
    {
        $dir = $storeDir . DIRECTORY_SEPARATOR . $token;

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("一時ファイルディレクトリを作成できません: {$dir}");
        }

        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $contents);
        file_put_contents($dir . '/meta.json', json_encode([
            'created' => time(),
            'mime' => $mime,
            'name' => $name,
            'size' => \strlen($contents),
        ], JSON_THROW_ON_ERROR));
    }

    private function newWpdb(): object
    {
        return WpdbTestDouble::create();
    }
}

final class RuntimeTestDateField extends CompositeField
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
