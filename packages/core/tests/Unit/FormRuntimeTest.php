<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use Closure;
use LogicException as PhpLogicException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException as PhpRuntimeException;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\Form\UnknownFieldException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\ConfirmToken;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\JtiClaim;
use TMSWP\Core\Form\JtiStore;
use TMSWP\Core\Form\Rule\MaxFileSize;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\SpamGuardInterface;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Core\Form\UploadedFile;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Tests\Support\RecordingLogger;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\Tests\Support\WpdbTestDouble;

final class FormRuntimeTest extends UnitTestCase
{
    private const string EXPIRED_CONFIRMATION = 'The confirmation screen has expired. Please review your entries and continue again.';

    private const string SEND_FAILED = 'The form could not be sent. Please try again.';

    /** @var list<array<string, mixed>> */
    private array $completed = [];

    private WpdbTestDouble $wpdb;

    /** onComplete に例外を投げさせるか(一度失敗させてから再送を確かめるテストで切り替える) */
    private bool $failOnComplete = false;

    /** onComplete が投げた例外の場所(ファイル:行) */
    private string $thrownAt = '';

    private RecordingLogger $logger;

    /** @var null|Closure(FormDefinition): Form */
    private ?Closure $formFactory = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = WpdbTestDouble::create();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->completed = [];
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        Kernel::reset();

        parent::tearDown();
    }

    public function testGetAndOtherFormPostReturnInputWithoutCsrf(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('contact')->text('name', '名前', default: '初期値');

        self::assertSame(FormStep::Input, (new Form($definition))->handle(new FormRequest('GET'))->step);
        self::assertSame('初期値', (new Form($definition))->values()['name']);

        $post = new FormRequest('POST', [Form::FIELD_FORM_ID => 'other', 'name' => 'ignored']);
        $result = (new Form($definition))->handle($post);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('初期値', $result->values['name']);
    }

    public function testCsrfFailureBindsValuesReturnsFormErrorAndIsLogged(): void
    {
        $this->stubRuntimeFunctions();
        Functions\when('wp_verify_nonce')->justReturn(false);
        $this->bootKernel(debug: false);

        $result = $this->form(FormDefinition::create('contact')->text('name', '名前'))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'bad',
            Form::FIELD_STEP => 'submit',
            'name' => ' Taro ',
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame(['Your session has expired. Please submit the form again.'], $result->formErrors);
        self::assertSame([['warning', 'tmswp.form: the CSRF check (nonce) of form {form} failed', 'contact']], $this->logRecords());
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
            ->onComplete($this->recordCompletion(...));

        $result = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('contact'),
            'name' => ' Taro ',
        ]));

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame([['name' => 'Taro']], $this->completed);
    }

    public function testFormWithoutConfirmationEmbedsSubmitTokenAndRejectsReloadAndDoubleClick(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('contact')
            ->text('name', '名前', [new Required()])
            ->onComplete($this->recordCompletion(...));
        $html = $this->form($definition)->open();
        $submitToken = $this->hiddenValue($html, Form::FIELD_SUBMIT_TOKEN);
        $post = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_SUBMIT_TOKEN => $submitToken,
            'name' => 'Taro',
        ]);

        self::assertStringNotContainsString(Form::FIELD_CONFIRM_TOKEN, $html);
        self::assertSame(FormStep::Complete, $this->form($definition)->handle($post)->step);

        // 完了画面のリロードと二重クリックは、同じ送信トークンの再 POST になる
        $reload = $this->form($definition)->handle($post);
        $doubleClick = $this->form($definition)->handle($post);

        self::assertSame([['name' => 'Taro']], $this->completed);
        self::assertSame(FormStep::Input, $reload->step);
        self::assertSame(['This form has already been submitted.'], $reload->formErrors);
        self::assertSame(['This form has already been submitted.'], $doubleClick->formErrors);
        self::assertSame(
            [
                ['notice', 'tmswp.form: a resubmission of form {form} was rejected', 'contact'],
                ['notice', 'tmswp.form: a resubmission of form {form} was rejected', 'contact'],
            ],
            $this->logRecords(),
        );
    }

    public function testFormWithoutConfirmationRejectsMissingTamperedAndForeignSubmitTokens(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('contact')
            ->text('name', '名前')
            ->onComplete($this->recordCompletion(...));
        $valid = SubmitToken::issue('contact');
        [$body, $signature] = explode('.', $valid, 2);
        $tokens = [
            '',
            $body . '.' . strrev($signature),
            SubmitToken::issue('other'),
            // 確認トークンは用途の違う鍵で署名されるため、送信トークンとして受け付けない
            ConfirmToken::issue('contact', ['name' => 'Taro']),
        ];

        foreach ($tokens as $token) {
            $result = $this->form($definition)->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'contact',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => 'submit',
                Form::FIELD_SUBMIT_TOKEN => $token,
                'name' => 'Taro',
            ]));

            self::assertSame(FormStep::Input, $result->step);
            self::assertSame('Taro', $result->values['name']);
            self::assertSame(['Your session has expired. Please submit the form again.'], $result->formErrors);
        }

        self::assertSame([], $this->completed);
        self::assertSame(
            array_fill(0, 4, ['warning', 'tmswp.form: the submit token of form {form} was rejected as invalid (missing, malformed, tampered or issued for another form)', 'contact']),
            $this->logRecords(),
        );
    }

    public function testExpiredSubmitTokenAsksToSubmitAgainWithoutClaiming(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('contact')
            ->text('name', '名前')
            ->onComplete($this->recordCompletion(...));

        $result = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('contact', now: time() - SubmitToken::TTL - 1),
            'name' => 'Taro',
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['Your session has expired. Please submit the form again.'], $result->formErrors);
        self::assertSame([], $this->completed);
        self::assertSame([], $this->wpdb->queries());
    }

    public function testConfirmSubmitConsumesJtiAndHandleIsIdempotent(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);

        $definition = FormDefinition::create('entry')
            ->text('name', '名前', [new Required()])
            ->add(new RuntimeTestDateField('birth', '生年月日', minYear: 2000, maxYear: 2026))
            ->confirm()
            ->onComplete($this->recordCompletion(...));

        $confirm = $this->form($definition);
        $confirmResult = $confirm->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => ' Taro ',
            'birth' => ['year' => '2026', 'month' => '6', 'day' => '13'],
        ]));
        $html = $confirm->open();
        $token = $this->hiddenValue($html, Form::FIELD_CONFIRM_TOKEN);

        self::assertSame(FormStep::Confirm, $confirmResult->step);
        self::assertSame('2026-06-13', $confirmResult->values['birth']);
        // 確認画面ありのフォームは ConfirmToken の jti に一本化し、送信トークンを埋め込まない
        self::assertStringNotContainsString(Form::FIELD_SUBMIT_TOKEN, $html);

        $submitRequest = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $token,
        ]);
        $submit = $this->form($definition);
        $submitResult = $submit->handle($submitRequest);
        $secondCall = $submit->handle($submitRequest);

        self::assertSame(FormStep::Complete, $submitResult->step);
        self::assertSame($submitResult, $secondCall);
        self::assertSame([['name' => 'Taro', 'birth' => '2026-06-13']], $this->completed);

        $duplicate = $this->form($definition)->handle($submitRequest);

        self::assertSame(FormStep::Input, $duplicate->step);
        self::assertSame(['This form has already been submitted.'], $duplicate->formErrors);
        self::assertSame([['notice', 'tmswp.form: a resubmission of form {form} was rejected', 'entry']], $this->logRecords());
    }

    public function testInvalidAndExpiredConfirmTokensReturnInputState(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);

        $definition = FormDefinition::create('entry')
            ->text('name', '名前')
            ->add(new RuntimeTestDateField('birth', '生年月日'))
            ->confirm()
            ->confirmTtl(1);
        $expired = ConfirmToken::issue('entry', ['birth' => '2026-06-13', 'name' => 'Taro'], now: time() - 10);

        $invalidResult = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => 'invalid',
        ]));
        self::assertSame(FormStep::Input, $invalidResult->step);
        self::assertSame(['The form could not be resubmitted. Please start again.'], $invalidResult->formErrors);
        self::assertSame(
            [['warning', 'tmswp.form: the confirm token of form {form} was rejected as invalid (malformed, tampered or issued for another form)', 'entry']],
            $this->logRecords(),
        );

        $expiredResult = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $expired,
        ]));
        self::assertSame(FormStep::Input, $expiredResult->step);
        self::assertSame(['year' => '2026', 'month' => '6', 'day' => '13'], $expiredResult->values['birth']);
        self::assertSame([self::EXPIRED_CONFIRMATION], $expiredResult->formErrors);
    }

    public function testMissingFileIsDetectedBeforeClaimAndAllValuesAreRestored(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $storeDir = $this->createTempDirectory('form-missing-file-store');
        $definition = FormDefinition::create('entry')
            ->text('name', '名前')
            ->file('resume', '履歴書')
            ->add(new RuntimeTestDateField('birth', '生年月日'))
            ->confirm()
            ->tempFileStore(new TempFileStore($storeDir))
            ->onComplete($this->recordCompletion(...));
        // 一時ファイルが gc 等で消えた後の送信
        $token = ConfirmToken::issue('entry', [
            'birth' => '2026-06-13',
            'name' => 'Taro',
            'resume' => ['__tmsfile' => '0123456789abcdef0123456789abcdef'],
        ]);
        $request = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $token,
        ]);

        $result = $this->form($definition)->handle($request);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame([self::EXPIRED_CONFIRMATION], $result->formErrors);
        // ファイル以外の全項目を復元してから戻す
        self::assertSame(['name' => 'Taro', 'resume' => '', 'birth' => ['year' => '2026', 'month' => '6', 'day' => '13']], $result->values);
        self::assertSame([['warning', 'tmswp.form: temporary files of form {form} were missing at submission: {fields}', 'entry']], $this->logRecords());
        self::assertSame('resume', $this->logger->records[0]['context']['fields']);
        // jti を claim していないので、ファイルが戻れば同じトークンで送信できる
        self::assertSame([], $this->wpdb->queries());
        $this->createStoredFile($storeDir, '0123456789abcdef0123456789abcdef', 'resume.pdf', 'application/pdf', 'body');

        self::assertSame(FormStep::Complete, $this->form($definition)->handle($request)->step);
        self::assertCount(1, $this->completed);
    }

    public function testSignedTokenWhoseFieldsDoNotMatchTheFormReturnsInputInsteadOfThrowing(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('entry')
            ->text('name', '名前')
            ->checkboxes('topics', '関心', ['news' => 'ニュース', 'docs' => '資料'])
            ->confirm()
            ->onComplete($this->recordCompletion(...));
        $tokens = [
            // デプロイの前のフォームにあった項目
            ConfirmToken::issue('entry', ['name' => 'Taro', 'topics' => ['news'], 'removed' => 'x']),
            // デプロイの後に足された項目が無い
            ConfirmToken::issue('entry', ['name' => 'Taro']),
            // 項目の種類が変わった(単一の値 → 複数選択)
            ConfirmToken::issue('entry', ['name' => 'Taro', 'topics' => 'news']),
        ];

        foreach ($tokens as $token) {
            $result = $this->form($definition)->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'entry',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => 'submit',
                Form::FIELD_CONFIRM_TOKEN => $token,
            ]));

            self::assertSame(FormStep::Input, $result->step);
            self::assertSame('Taro', $result->values['name']);
            self::assertSame([self::EXPIRED_CONFIRMATION], $result->formErrors);
        }

        self::assertSame([], $this->completed);
        self::assertSame([], $this->wpdb->queries());
        self::assertCount(3, $this->logRecords());
        self::assertSame(['notice', 'tmswp.form: the confirm token of form {form} does not match the current fields of the form', 'entry'], $this->logRecords()[0]);

        // 戻るでは、今の項目にある値だけを復元する
        $back = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'back',
            Form::FIELD_CONFIRM_TOKEN => $tokens[0],
        ]));

        self::assertSame(['name' => 'Taro', 'topics' => ['news']], $back->values);
    }

    public function testValuesInTheConfirmTokenAreValidatedAgainWithTheCurrentRulesBeforeClaiming(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $before = $this->revalidationDefinition(20, ['news' => 'ニュース', 'docs' => '資料']);
        $confirm = $this->form($before);
        self::assertSame(FormStep::Confirm, $confirm->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => 'Taro Yamada',
            'topics' => ['news', 'docs'],
            'birth' => ['year' => '2026', 'month' => '6', 'day' => '13'],
        ]))->step);
        $submit = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $this->hiddenValue($confirm->open(), Form::FIELD_CONFIRM_TOKEN),
        ]);
        // 確認の後のデプロイで、MaxLength を小さくし、選択肢を減らした
        $after = $this->revalidationDefinition(5, ['news' => 'ニュース']);

        $result = $this->form($after)->handle($submit);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['name', 'topics'], array_keys($result->fieldErrors));
        self::assertSame([], $result->formErrors);
        self::assertSame(['name' => 'Taro Yamada', 'topics' => ['news', 'docs'], 'birth' => ['year' => '2026', 'month' => '6', 'day' => '13']], $result->values);
        self::assertSame([], $this->completed);
        // jti を claim していない
        self::assertSame([], $this->wpdb->queries());
        self::assertSame([['notice', 'tmswp.form: the values in the confirm token of form {form} do not pass the current rules: {fields}', 'entry']], $this->logRecords());
        self::assertSame('name, topics', $this->logger->records[0]['context']['fields']);

        // 消費していないので、今の規則で通る定義なら同じトークンで送信できる
        self::assertSame(FormStep::Complete, $this->form($before)->handle($submit)->step);
        self::assertSame([['name' => 'Taro Yamada', 'topics' => ['news', 'docs'], 'birth' => '2026-06-13']], $this->completed);
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
        ], now: time() - 10);

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

    public function testBackRestoresCompositeFieldsAsPartsForTheInputScreen(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('entry')->add(new RuntimeTestDateField('birth', '生年月日'))->confirm();

        $result = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'back',
            Form::FIELD_CONFIRM_TOKEN => ConfirmToken::issue('entry', ['birth' => '2026-06-13']),
        ]));

        self::assertSame(['birth' => ['year' => '2026', 'month' => '6', 'day' => '13']], $result->values);
    }

    public function testBackOnFormWithoutConfirmationRedisplaysInputWithoutError(): void
    {
        $this->stubRuntimeFunctions();

        $result = (new Form(FormDefinition::create('contact')->text('name', '名前')))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'back',
            'name' => ' Taro ',
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame([], $result->formErrors);
    }

    public function testBackWithInvalidTokenShowsErrorAndIsLogged(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('entry')->text('name', '名前', default: '初期値')->confirm();

        $result = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'back',
            Form::FIELD_CONFIRM_TOKEN => 'tampered',
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('初期値', $result->values['name']);
        self::assertSame(['The form could not be resubmitted. Please start again.'], $result->formErrors);
        self::assertSame(
            [['warning', 'tmswp.form: the confirm token of form {form} was rejected as invalid on going back (malformed, tampered or issued for another form)', 'entry']],
            $this->logRecords(),
        );
    }

    public function testConfirmWithOtherFieldErrorsStoresFilesThatPassedTheirOwnRules(): void
    {
        $this->stubRuntimeFunctions();
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        Functions\when('sanitize_file_name')->returnArg();
        $uploadDir = $this->createTempDirectory('form-upload');
        file_put_contents($uploadDir . '/resume.txt', 'resume');
        file_put_contents($uploadDir . '/photo.txt', 'a photo that is too large');
        $definition = FormDefinition::create('entry')
            ->text('name', '名前', [new Required()])
            ->file('resume', '履歴書')
            ->file('photo', '写真', [new MaxFileSize(5)])
            ->confirm()
            ->tempFileStore(new TempFileStore($this->createTempDirectory('form-upload-store')));
        $form = new Form($definition);

        $result = $form->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => '',
        ], [
            'resume' => ['name' => 'resume.txt', 'type' => 'text/plain', 'tmp_name' => $uploadDir . '/resume.txt', 'error' => UPLOAD_ERR_OK, 'size' => 6],
            'photo' => ['name' => 'photo.txt', 'type' => 'text/plain', 'tmp_name' => $uploadDir . '/photo.txt', 'error' => UPLOAD_ERR_OK, 'size' => 25],
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertArrayHasKey('name', $result->fieldErrors);
        self::assertArrayHasKey('photo', $result->fieldErrors);
        // 自身の検証を通ったファイルは保管し、入力画面の hidden でトークンを引き回す
        self::assertInstanceOf(StoredFile::class, $result->values['resume']);
        self::assertSame('resume', file_get_contents($result->values['resume']->path));
        self::assertStringContainsString('name="resume__token" value="' . $result->values['resume']->token . '"', $form->field('resume')->hidden());
        // 自身の検証に失敗したファイルは保管しない
        self::assertInstanceOf(UploadedFile::class, $result->values['photo']);
        self::assertFileExists($uploadDir . '/photo.txt');
    }

    public function testJtiStoreReportsClaimDuplicateAndDatabaseErrors(): void
    {
        $store = new JtiStore();

        self::assertSame(JtiClaim::Claimed, $store->claim('0123456789abcdef0123456789abcdef', 60));
        self::assertSame(JtiClaim::Duplicate, $store->claim('0123456789abcdef0123456789abcdef', 60));

        $store->release('0123456789abcdef0123456789abcdef');
        self::assertSame(JtiClaim::Claimed, $store->claim('0123456789abcdef0123456789abcdef', 60));

        foreach ([
            'claim' => static fn(): mixed => $store->claim('fedcba9876543210fedcba9876543210', 60),
            'release' => static function () use ($store): void {
                $store->release('0123456789abcdef0123456789abcdef');
            },
            'gc' => static fn(): mixed => $store->gc(),
        ] as $operation => $call) {
            $this->wpdb->failNextWrite('Deadlock found');

            try {
                $call();
                self::fail("{$operation} did not throw on a database error");
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Deadlock found', $exception->getMessage(), $operation);
            }
        }

        // データベースのエラーで失敗した claim は、獲得したことにならない
        self::assertSame(JtiClaim::Claimed, $store->claim('fedcba9876543210fedcba9876543210', 60));
    }

    public function testJtiStoreCollectsGarbageBeforeClaimingSoAFailedCollectionClaimsNothing(): void
    {
        $store = new JtiStore();
        // mt_srand(111) の最初の mt_rand(1, 100) は 1 になり、claim の前の確率的な掃除が動く
        mt_srand(111);
        $this->wpdb->failNextWrite('Deadlock found');

        try {
            $store->claim('0123456789abcdef0123456789abcdef', 60);
            self::fail('the failure of gc() was not thrown');
        } catch (RuntimeException $exception) {
            self::assertSame('failed to delete expired form submission IDs: Deadlock found', $exception->getMessage());
        } finally {
            mt_srand();
        }

        self::assertSame(JtiClaim::Claimed, $store->claim('0123456789abcdef0123456789abcdef', 60));
    }

    public function testJtiStoreRejectsMalformedSubmissionIds(): void
    {
        $this->expectException(FormConfigException::class);

        (new JtiStore())->claim('../not-a-jti', 60);
    }

    public function testJtiStoreDatabaseErrorIsThrownInDebug(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: true);
        $definition = FormDefinition::create('contact')->text('name', '名前')->onComplete($this->recordCompletion(...));
        $this->wpdb->failNextWrite('Lost connection');

        try {
            $this->form($definition)->handle($this->directSubmit('contact', ['name' => 'Taro']));
            self::fail('the database error was not thrown');
        } catch (RuntimeException $exception) {
            // claim の前の確率的な掃除(gc)が失敗を受けることもあるため、データベースのエラーの内容で確かめる
            self::assertStringContainsString('Lost connection', $exception->getMessage());
        }

        self::assertSame([], $this->completed);
    }

    public function testJtiStoreDatabaseErrorShowsGenericErrorAndIsLoggedInProduction(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('contact')->text('name', '名前')->onComplete($this->recordCompletion(...));
        $request = $this->directSubmit('contact', ['name' => 'Taro']);
        $this->wpdb->failNextWrite('Lost connection');

        $result = $this->form($definition)->handle($request);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame([self::SEND_FAILED], $result->formErrors);
        self::assertSame([], $this->completed);
        self::assertSame([['error', 'tmswp.form: failed to claim the submission ID of form {form}', 'contact']], $this->logRecords());
        self::assertSame(RuntimeException::class, $this->logger->records[0]['context']['exceptionClass']);
        self::assertArrayNotHasKey('exception', $this->logger->records[0]['context']);
        // 再送とは扱わず、同じトークンで送り直せる
        self::assertSame(FormStep::Complete, $this->form($definition)->handle($request)->step);
    }

    public function testOnCompleteExceptionIsThrownInDebugWithoutConsumingTheJti(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: true);
        $this->failOnComplete = true;
        $definition = FormDefinition::create('contact')
            ->text('name', '名前')
            ->onComplete(function (array $values): void {
                if ($this->failOnComplete) {
                    throw new PhpRuntimeException('mail server is down');
                }

                $this->completed[] = $values;
            });
        $request = $this->directSubmit('contact', ['name' => 'Taro']);

        try {
            $this->form($definition)->handle($request);
            self::fail('the exception of onComplete was not thrown');
        } catch (PhpRuntimeException $exception) {
            self::assertSame('mail server is down', $exception->getMessage());
        }

        $this->failOnComplete = false;

        self::assertSame(FormStep::Complete, $this->form($definition)->handle($request)->step);
        self::assertSame([['name' => 'Taro']], $this->completed);
    }

    public function testOnCompleteExceptionIsLoggedAndCanBeResubmittedInProduction(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $this->failOnComplete = true;
        $definition = FormDefinition::create('entry')
            ->text('name', '名前')
            ->add(new RuntimeTestDateField('birth', '生年月日'))
            ->confirm()
            ->onComplete(function (array $values): void {
                if ($this->failOnComplete) {
                    throw new PhpLogicException('bug in onComplete');
                }

                $this->completed[] = $values;
            });
        $request = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => ConfirmToken::issue('entry', ['birth' => '2026-06-13', 'name' => 'Taro']),
        ]);

        $failed = $this->form($definition)->handle($request);

        self::assertSame(FormStep::Input, $failed->step);
        self::assertSame([self::SEND_FAILED], $failed->formErrors);
        self::assertSame(['name' => 'Taro', 'birth' => ['year' => '2026', 'month' => '6', 'day' => '13']], $failed->values);
        self::assertSame([['error', 'tmswp.form: onComplete of form {form} threw an exception', 'entry']], $this->logRecords());
        self::assertSame(PhpLogicException::class, $this->logger->records[0]['context']['exceptionClass']);

        $this->failOnComplete = false;

        self::assertSame(FormStep::Complete, $this->form($definition)->handle($request)->step);
        self::assertSame([['name' => 'Taro', 'birth' => '2026-06-13']], $this->completed);
    }

    public function testFailedReleaseAfterOnCompleteExceptionIsLoggedAndTheTokenStaysConsumed(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('contact')
            ->text('name', '名前')
            ->onComplete(function (): void {
                // claim の後の次の書き込み(jti の手放し)を失敗させる
                $this->wpdb->failNextWrite('Lost connection');

                throw new PhpRuntimeException('mail server is down');
            });
        $request = $this->directSubmit('contact', ['name' => 'Taro']);

        $result = $this->form($definition)->handle($request);

        self::assertSame([self::SEND_FAILED], $result->formErrors);
        self::assertSame(
            [
                ['error', 'tmswp.form: failed to release the submission ID of form {form}; the same token cannot be resubmitted', 'contact'],
                ['error', 'tmswp.form: onComplete of form {form} threw an exception', 'contact'],
            ],
            $this->logRecords(),
        );
        self::assertSame(RuntimeException::class, $this->logger->records[0]['context']['exceptionClass']);
        self::assertSame(PhpRuntimeException::class, $this->logger->records[1]['context']['exceptionClass']);
        self::assertSame(['This form has already been submitted.'], $this->form($definition)->handle($request)->formErrors);
    }

    public function testLogOfOnCompleteExceptionHasNeitherInputValuesNorTheExceptionItself(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('contact')
            ->text('email', 'メール')
            ->onComplete(function (array $values): void {
                $email = \is_string($values['email']) ? $values['email'] : '';
                $exception = new PhpRuntimeException("failed to send mail to {$email}", 0, new PhpLogicException("smtp rejected {$email}"));
                $this->thrownAt = $exception->getFile() . ':' . $exception->getLine();

                throw $exception;
            });

        $this->form($definition)->handle($this->directSubmit('contact', ['email' => 'secret.user@example.com']));

        self::assertCount(1, $this->logger->records);
        self::assertSame('tmswp.form: onComplete of form {form} threw an exception', $this->logger->records[0]['message']);
        // 例外のクラス名と、投げた場所だけを記録する(メッセージ・previous のメッセージ・トレース・例外のオブジェクトは無い)
        self::assertSame(
            [
                'form' => 'contact',
                'exceptionClass' => PhpRuntimeException::class,
                'exceptionAt' => $this->thrownAt,
                'previousClasses' => [PhpLogicException::class],
            ],
            $this->logger->records[0]['context'],
        );
        self::assertStringNotContainsString('secret.user@example.com', serialize($this->logger->records));
    }

    public function testReleaseFailureOfFormBelongingToNoKernelKeepsTheOriginalException(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('contact')
            ->text('name', '名前')
            ->onComplete(function (): void {
                // claim の後の次の書き込み(jti の手放し)を失敗させる
                $this->wpdb->failNextWrite('Lost connection');

                throw new PhpRuntimeException('mail server is down');
            });

        try {
            // テストのファイルはどの Kernel のルートにも含まれない
            (new Form($definition))->handle($this->directSubmit('contact', ['name' => 'Taro']));
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertInstanceOf(PhpRuntimeException::class, $exception->getPrevious());
            self::assertSame('mail server is down', $exception->getPrevious()->getMessage());
        }
    }

    public function testOnCompleteExceptionKeepsTheOriginalExceptionWhenTheFormBelongsToNoKernel(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('contact')
            ->text('name', '名前')
            ->onComplete(static function (): void {
                throw new PhpRuntimeException('mail server is down');
            });

        try {
            // テストのファイルはどの Kernel のルートにも含まれない
            (new Form($definition))->handle($this->directSubmit('contact', ['name' => 'Taro']));
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertInstanceOf(PhpRuntimeException::class, $exception->getPrevious());
            self::assertSame('mail server is down', $exception->getPrevious()->getMessage());
        }
    }

    public function testHandleAndOpenExcludeThePageFromPageCaches(): void
    {
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_salt')->justReturn('secret');
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        Functions\expect('nocache_headers')->twice();
        $form = new Form(FormDefinition::create('contact')->text('name', '名前'));

        $form->handle(new FormRequest('GET'));
        $form->open();

        self::assertTrue(\defined('DONOTCACHEPAGE'));
    }

    #[RunInSeparateProcess]
    public function testHandleDefinesDoNotCachePage(): void
    {
        Functions\when('nocache_headers')->justReturn();

        self::assertFalse(\defined('DONOTCACHEPAGE'));

        (new Form(FormDefinition::create('contact')->text('name', '名前')))->handle(new FormRequest('GET'));

        self::assertTrue(\constant('DONOTCACHEPAGE'));
    }

    #[RunInSeparateProcess]
    public function testOpenDefinesDoNotCachePageWithoutHandle(): void
    {
        $this->stubRuntimeFunctions();

        self::assertFalse(\defined('DONOTCACHEPAGE'));

        (new Form(FormDefinition::create('contact')->text('name', '名前')))->open();

        self::assertTrue(\constant('DONOTCACHEPAGE'));
    }

    public function testSpamGuardsAreRenderedOnInputAndCheckedBeforeValidation(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $guard = new RuntimeTestSpamGuard();
        $definition = FormDefinition::create('contact')
            ->text('name', '名前', [new Required()])
            ->spamGuard($guard)
            ->onComplete($this->recordCompletion(...));

        self::assertStringContainsString('<input type="text" name="contact_hp" value="">', $this->form($definition)->open());

        // CSRF と送信トークンの検証の後に検査する
        $invalidToken = $this->directSubmit('contact', ['name' => 'Taro', Form::FIELD_SUBMIT_TOKEN => 'tampered']);
        self::assertSame(['Your session has expired. Please submit the form again.'], $this->form($definition)->handle($invalidToken)->formErrors);
        Functions\when('wp_verify_nonce')->justReturn(false);
        self::assertSame(['Your session has expired. Please submit the form again.'], $this->form($definition)->handle($this->directSubmit('contact', ['name' => 'Taro']))->formErrors);
        Functions\when('wp_verify_nonce')->justReturn(1);
        self::assertSame([], $guard->checked);
        $this->logger->records = [];

        $guard->pass = false;
        $rejected = $this->form($definition)->handle($this->directSubmit('contact', ['name' => '']));

        self::assertSame(FormStep::Input, $rejected->step);
        self::assertSame(['Your submission could not be verified. Please try again.'], $rejected->formErrors);
        // 値の検証より前に拒否する
        self::assertSame([], $rejected->fieldErrors);
        self::assertSame([['notice', 'tmswp.form: spam guard {guard} rejected a submission of form {form}', 'contact']], $this->logRecords());
        self::assertSame(RuntimeTestSpamGuard::class, $this->logger->records[0]['context']['guard']);

        $guard->pass = true;

        self::assertSame(FormStep::Complete, $this->form($definition)->handle($this->directSubmit('contact', ['name' => 'Taro']))->step);
        self::assertSame([['name' => 'Taro']], $this->completed);
        self::assertCount(2, $guard->checked);
    }

    public function testSpamGuardsAreCheckedOnConfirmAndNotRenderedOnConfirmationScreen(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $guard = new RuntimeTestSpamGuard();
        $definition = FormDefinition::create('entry')
            ->text('name', '名前')
            ->spamGuard($guard)
            ->confirm()
            ->onComplete($this->recordCompletion(...));
        $guard->pass = false;
        $rejected = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => 'Taro',
        ]));

        self::assertSame(FormStep::Input, $rejected->step);
        self::assertSame(['Your submission could not be verified. Please try again.'], $rejected->formErrors);
        self::assertSame('Taro', $rejected->values['name']);

        $guard->pass = true;
        $confirm = $this->form($definition);

        self::assertSame(FormStep::Confirm, $confirm->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => 'Taro',
        ]))->step);
        $html = $confirm->open();

        self::assertStringNotContainsString('contact_hp', $html);

        // 確認画面からの送信では検査しない(確認の時点で通っている)
        $guard->pass = false;
        $submit = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $this->hiddenValue($html, Form::FIELD_CONFIRM_TOKEN),
        ]));

        self::assertSame(FormStep::Complete, $submit->step);
        self::assertCount(2, $guard->checked);
    }

    public function testApplyDefaultsReplacesDefaultsInTheShapeEachFieldAccepts(): void
    {
        $this->stubRuntimeFunctions();
        $form = new Form(FormDefinition::create('settings')
            ->text('title', 'タイトル', default: '既定')
            ->checkboxes('topics', '関心', ['news' => 'ニュース', 'docs' => '資料'])
            ->add(new RuntimeTestDateField('birth', '生年月日')));

        $form->applyDefaults(['title' => '保存済み', 'topics' => ['docs'], 'birth' => '2026-06-13']);

        self::assertSame(
            ['title' => '保存済み', 'topics' => ['docs'], 'birth' => ['year' => '2026', 'month' => '6', 'day' => '13']],
            $form->handle(new FormRequest('GET'))->values,
        );
    }

    public function testApplyDefaultsAfterHandleIsRejected(): void
    {
        $this->stubRuntimeFunctions();
        $form = new Form(FormDefinition::create('settings')->text('title', 'タイトル'));
        $form->handle(new FormRequest('GET'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("applyDefaults() of form 'settings' must be called before handle()");

        $form->applyDefaults(['title' => '保存済み']);
    }

    public function testApplyDefaultsRejectsUnknownFields(): void
    {
        $form = new Form(FormDefinition::create('settings')->text('title', 'タイトル'));

        $this->expectException(UnknownFieldException::class);

        $form->applyDefaults(['missing' => 'x']);
    }

    public function testApplyDefaultsRejectsValuesOfTheWrongShapeAndFileFields(): void
    {
        $definition = FormDefinition::create('settings')
            ->text('title', 'タイトル')
            ->checkboxes('topics', '関心', ['news' => 'ニュース'])
            ->file('resume', '履歴書');
        $cases = [
            "default value of field 'title' must be a string, int given" => ['title' => 5],
            "default value of field 'title' must be a string, array given" => ['title' => ['a']],
            "default value of field 'topics' must be a list of strings, string given" => ['topics' => 'news'],
            "default value of field 'topics' must be a list of strings, an item is int" => ['topics' => [1]],
            "file field 'resume' cannot have a default value" => ['resume' => ''],
        ];

        foreach ($cases as $message => $values) {
            try {
                (new Form($definition))->applyDefaults($values);
                self::fail("FormConfigException was not thrown: {$message}");
            } catch (FormConfigException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    /** @param array<string, string> $choices */
    private function revalidationDefinition(int $maxLength, array $choices): FormDefinition
    {
        return FormDefinition::create('entry')
            ->text('name', '名前', [new Required(), new MaxLength($maxLength)])
            ->checkboxes('topics', '関心', $choices)
            ->add(new RuntimeTestDateField('birth', '生年月日', [new Required()], minYear: 2000, maxYear: 2026))
            ->confirm()
            ->onComplete($this->recordCompletion(...));
    }

    private function stubRuntimeFunctions(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_salt')->justReturn('secret');
        Functions\when('nocache_headers')->justReturn();
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * 一時ディレクトリをルートとするテーマの Kernel を、記録を残すロガーで boot する。form() は、そのルートの中の
     * ファイルで `new Form()` を書いたフォームを作る(Form は作ったファイルで Kernel を決める)。
     */
    private function bootKernel(bool $debug): void
    {
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        Functions\when('get_template_directory')->justReturn(sys_get_temp_dir());
        Functions\when('get_stylesheet_directory')->justReturn(sys_get_temp_dir());
        $root = $this->createTempDirectory('form-kernel');

        if (!mkdir($root . '/app')) {
            self::fail("ディレクトリを作成できません: {$root}/app");
        }

        $logger = $this->logger;
        $config = KernelConfig::theme(
            $root . '/app',
            'Example\Tests\FormRuntime' . uniqid(),
            $debug,
            false,
            logChannels: static fn(): array => ['test' => new LogChannel($logger)],
        );
        // 本番の boot は探索結果ファイルだけを読む(コンポーネントの無い app)
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        Kernel::boot($config);
        $factoryFile = $root . '/parts/form-factory.php';
        mkdir(\dirname($factoryFile));
        file_put_contents($factoryFile, <<<'PHP'
<?php

declare(strict_types=1);

return static fn(\TMSWP\Core\Form\FormDefinition $definition): \TMSWP\Core\Form\Form => new \TMSWP\Core\Form\Form($definition);
PHP);
        $factory = require $factoryFile;
        self::assertInstanceOf(Closure::class, $factory);
        $this->formFactory = static function (FormDefinition $definition) use ($factory): Form {
            $form = $factory($definition);
            self::assertInstanceOf(Form::class, $form);

            return $form;
        };
    }

    private function form(FormDefinition $definition): Form
    {
        if ($this->formFactory === null) {
            self::fail('bootKernel() must be called before form()');
        }

        return ($this->formFactory)($definition);
    }

    /** @param array<string, mixed> $values */
    private function directSubmit(string $formId, array $values): FormRequest
    {
        return new FormRequest('POST', [
            Form::FIELD_FORM_ID => $formId,
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue($formId),
            ...$values,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function recordCompletion(array $values): void
    {
        $this->completed[] = $values;
    }

    /** @return list<array{mixed, string, mixed}> レベル・メッセージ・フォームの ID */
    private function logRecords(): array
    {
        return array_map(
            static fn(array $record): array => [$record['level'], $record['message'], $record['context']['form'] ?? null],
            $this->logger->records,
        );
    }

    private function hiddenValue(string $html, string $name): string
    {
        if (preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $matches) !== 1) {
            self::fail("hidden フィールド {$name} が見つかりません。");
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
}

final class RuntimeTestSpamGuard implements SpamGuardInterface
{
    public bool $pass = true;

    /** @var list<FormRequest> */
    public array $checked = [];

    public function render(string $formId): string
    {
        return '<input type="text" name="' . $formId . '_hp" value="">';
    }

    public function passes(FormRequest $request): bool
    {
        $this->checked[] = $request;

        return $this->pass;
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
