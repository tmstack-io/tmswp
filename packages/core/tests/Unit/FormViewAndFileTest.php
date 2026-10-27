<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use Closure;
use Error;
use Exception;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\Form\TempFileException;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\FieldType;
use TMSWP\Core\Form\FieldView;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormHtml;
use TMSWP\Core\Form\OptionView;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\TempFileGc;
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Core\Form\UploadedFile;
use TMSWP\Testing\UnitTestCase;
use WP;

final class FormViewAndFileTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wp']);

        parent::tearDown();
    }

    public function testFormHtmlAndFieldViewEscapeRenderedMarkup(): void
    {
        $this->stubEscapers();

        $definition = FormDefinition::create('contact')
            ->text('name', 'Name')
            ->select('pref', 'Prefecture', ['tokyo' => '<Tokyo>']);
        $form = new Form($definition);

        self::assertSame(
            '<input type="text" name="name" value="" id="contact-name" data-label="&lt;name&gt;">',
            $form->field('name')->input(['data-label' => '<name>']),
        );
        self::assertSame(
            '<select name="pref" id="contact-pref"><option value="tokyo">&lt;Tokyo&gt;</option></select>',
            $form->field('pref')->select(),
        );
    }

    public function testFieldViewRendersErrorsOptionsAndFileToken(): void
    {
        $this->stubEscapers();

        $checkboxes = new FieldView(
            Field::checkboxes('topics', 'Topics', ['news' => 'News', 'docs' => 'Docs']),
            ['docs'],
            ['Please choose.'],
            'contact',
            '<span>%s</span>',
        );
        $file = new FieldView(
            Field::file('resume', 'Resume'),
            new StoredFile('0123456789abcdef0123456789abcdef', '/tmp/resume.pdf', 'resume.pdf', 'application/pdf', 10),
            [],
            'contact',
            '<span>%s</span>',
        );

        self::assertSame('<span>Please choose.</span>', $checkboxes->errors());
        self::assertSame('Docs', $checkboxes->display());
        self::assertStringContainsString('name="topics[]" value="docs" checked', $checkboxes->checkboxes());
        self::assertSame('<input type="hidden" name="resume__token" value="0123456789abcdef0123456789abcdef">', $file->hidden());
    }

    public function testOptionViewRendersRadioAndLabel(): void
    {
        $this->stubEscapers();

        $option = new OptionView('yes', 'Agree', true, 'contact-agree-0', 'agree', FieldType::Radios);

        self::assertSame('<input type="radio" name="agree" value="yes" checked id="contact-agree-0">', $option->input());
        self::assertSame('<label for="contact-agree-0">Agree</label>', $option->labelTag());
    }

    public function testFieldViewRejectsInvalidAttributeValue(): void
    {
        $this->expectException(FormConfigException::class);

        (new FieldView(Field::text('name', 'Name'), '', [], 'contact', '%s'))->input(['value' => new stdClass()]);
    }

    public function testEveryTextNodeIsEscapedWithEscHtml(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        // esc_html のフィルタを通ったことが分かる印を付ける
        Functions\when('esc_html')->alias(static fn(string $value): string => '[' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . ']');
        $definition = FormDefinition::create('contact')
            ->textarea('message', 'Message')
            ->select('plan', 'Plan', ['basic' => 'Basic'])
            ->radios('color', 'Color', ['red' => 'Red'])
            ->checkbox('agree', 'Agree', checkedLabel: 'Yes');
        $form = new Form($definition);
        $message = new FieldView(Field::textarea('message', 'Message'), '<b>', ['Too <long>'], 'contact', '<p>%s</p>');

        self::assertSame('<textarea name="message" id="contact-message">[&lt;b&gt;]</textarea>', $message->textarea());
        self::assertSame('<p>[Too &lt;long&gt;]</p>', $message->errors());
        self::assertSame('[&lt;b&gt;]', $message->display());
        self::assertSame('[Message]', $message->label());
        self::assertSame('<label for="contact-message">[Message]</label>', $message->labelTag());
        self::assertStringContainsString('<option value="basic">[Basic]</option>', $form->field('plan')->select());
        self::assertStringEndsWith(' [Red]</label>', $form->field('color')->radios());
        self::assertSame('<label for="contact-color-0">[Red]</label>', $form->field('color')->options()[0]->labelTag());
    }

    /**
     * esc_html が無い環境で、別のエスケープ(htmlspecialchars)に黙って切り替えない。WordPress の esc_html フィルタを
     * 通らない出力の経路を残さないため。
     */
    #[RunInSeparateProcess]
    public function testFormHtmlDoesNotFallBackToAnotherEscaperWithoutEscHtml(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        self::assertFalse(\function_exists('esc_html'));

        $this->expectException(Error::class);
        $this->expectExceptionMessage('esc_html');

        FormHtml::label('<Name>', 'contact-name');
    }

    public function testIdOfControlsAndForOfLabelsCanBeOverridden(): void
    {
        $this->stubEscapers();
        $form = new Form(FormDefinition::create('contact')
            ->text('name', 'Name')
            ->textarea('message', 'Message')
            ->select('plan', 'Plan', ['basic' => 'Basic'])
            ->checkbox('agree', 'Agree')
            ->radios('color', 'Color', ['red' => 'Red']));

        self::assertSame('<input type="email" name="name" value="" id="custom-name" class="wide">', $form->field('name')->input(['type' => 'email', 'id' => 'custom-name', 'class' => 'wide']));
        self::assertSame('<label for="custom-name">Name</label>', $form->field('name')->labelTag(['for' => 'custom-name']));
        // HTML の属性名は大文字と小文字を区別しない
        self::assertSame('<input type="text" name="name" value="" id="upper-name">', $form->field('name')->input(['ID' => 'upper-name']));
        self::assertSame('<textarea name="message" id="custom-message"></textarea>', $form->field('message')->textarea(['id' => 'custom-message']));
        self::assertStringStartsWith('<select name="plan" id="custom-plan">', $form->field('plan')->select(['id' => 'custom-plan']));
        self::assertSame('<input type="checkbox" name="agree" value="1" id="custom-agree" required>', $form->field('agree')->checkbox(['id' => 'custom-agree', 'required']));
        $option = $form->field('color')->options()[0];
        self::assertSame('<input type="radio" name="color" value="red" id="custom-red">', $option->input(['id' => 'custom-red']));
        self::assertSame('<label for="custom-red">Red</label>', $option->labelTag(['for' => 'custom-red']));
        self::assertSame('contact-name', $form->field('name')->id());
    }

    /** @return iterable<string, array{Closure(Form): string}> */
    public static function attributesSetByTheForm(): iterable
    {
        yield 'name of input' => [static fn(Form $form): string => $form->field('name')->input(['name' => 'other'])];
        yield 'name of input in upper case' => [static fn(Form $form): string => $form->field('name')->input(['NAME' => 'other'])];
        yield 'name of textarea' => [static fn(Form $form): string => $form->field('message')->textarea(['name' => 'other'])];
        yield 'name of select' => [static fn(Form $form): string => $form->field('plan')->select(['name' => 'other'])];
        yield 'multiple of select' => [static fn(Form $form): string => $form->field('plan')->select(['multiple'])];
        yield 'value of checkbox' => [static fn(Form $form): string => $form->field('agree')->checkbox(['value' => 'yes'])];
        yield 'checked of checkbox' => [static fn(Form $form): string => $form->field('agree')->checkbox(['checked' => true])];
        yield 'type of option' => [static fn(Form $form): string => $form->field('color')->options()[0]->input(['type' => 'checkbox'])];
        yield 'value of option' => [static fn(Form $form): string => $form->field('color')->options()[0]->input(['value' => 'blue'])];
        yield 'id of all options' => [static fn(Form $form): string => $form->field('color')->radios(['id' => 'color'])];
        yield 'for of all option labels' => [static fn(Form $form): string => $form->field('color')->radios(labelAttrs: ['for' => 'color'])];
        yield 'value of password' => [static fn(Form $form): string => $form->field('secret')->input(['value' => 'shown'])];
        yield 'value of file' => [static fn(Form $form): string => $form->field('upload')->input(['value' => 'x.pdf'])];
        yield 'the same attribute twice' => [static fn(Form $form): string => $form->field('name')->input(['id' => 'a', 'ID' => 'b'])];
        yield 'for of FormHtml::label' => [static fn(Form $form): string => FormHtml::label('Name', 'contact-name', ['for' => 'other'])];
        yield 'method of form' => [static fn(Form $form): string => $form->open(['method' => 'get'])];
        yield 'enctype of form' => [static fn(Form $form): string => $form->open(['enctype' => 'text/plain'])];
    }

    /** @param Closure(Form): string $render */
    #[DataProvider('attributesSetByTheForm')]
    public function testAttributesSetByTheFormCannotBeSpecified(Closure $render): void
    {
        $this->stubEscapers();
        // フロントの要求。Formはopen()で、POSTで送る名前を公開のクエリ変数と照らす(照合はFormRuntimeTest)
        Functions\when('is_admin')->justReturn(false);
        $wp = Mockery::mock(WP::class);
        self::assertInstanceOf(WP::class, $wp);
        $wp->public_query_vars = [];
        $GLOBALS['wp'] = $wp;
        Functions\when('nocache_headers')->justReturn();
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_salt')->justReturn('secret');
        $form = new Form(FormDefinition::create('contact')
            ->text('name', 'Name')
            ->textarea('message', 'Message')
            ->select('plan', 'Plan', ['basic' => 'Basic'])
            ->checkbox('agree', 'Agree')
            ->radios('color', 'Color', ['red' => 'Red'])
            ->password('secret', 'Password')
            ->file('upload', 'Upload'));

        $this->expectException(FormConfigException::class);

        $render($form);
    }

    public function testPasswordIsNeitherRedisplayedNorDisplayed(): void
    {
        $this->stubEscapers();
        $password = new FieldView(Field::password('secret', 'Password'), 'p@ss<word>', [], 'contact', '%s');

        self::assertSame('<input type="password" name="secret" value="" id="contact-secret">', $password->input());
        self::assertSame('<input type="text" name="secret" value="" id="contact-secret">', $password->input(['type' => 'text']));

        foreach (['hidden' => static fn(): string => $password->hidden(), 'display' => static fn(): string => $password->display()] as $method => $render) {
            try {
                $render();
                self::fail("{$method}() rendered the password");
            } catch (FormConfigException $exception) {
                self::assertStringContainsString("password field 'secret'", $exception->getMessage());
            }
        }
    }

    public function testCheckboxIsDisplayedWithItsCheckedLabel(): void
    {
        $this->stubEscapers();
        $agree = Field::checkbox('agree', 'Agree', checkedLabel: 'I <agree>');

        self::assertSame('I &lt;agree&gt;', (new FieldView($agree, '1', [], 'contact', '%s'))->display());
        self::assertSame('', (new FieldView($agree, '', [], 'contact', '%s'))->display());
        self::assertSame('I agree', (new FieldView(Field::checkbox('agree', 'Agree', 'yes', checkedLabel: 'I agree'), 'yes', [], 'contact', '%s'))->display());
        self::assertSame('I agree', (new FieldView(Field::checkbox('agree', 'Agree', checkedLabel: 'I agree')->withRules(), '1', [], 'contact', '%s'))->display());

        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage("checkbox field 'agree' needs checkedLabel to be displayed");

        (new FieldView(Field::checkbox('agree', 'Agree'), '1', [], 'contact', '%s'))->display();
    }

    public function testErrorTemplateMayContainPercentSigns(): void
    {
        $this->stubEscapers();
        $view = new FieldView(Field::text('name', 'Name'), '', ['Use 100% or %s'], 'contact', '<p style="width:100%">%s</p>');

        self::assertSame('<p style="width:100%">Use 100% or %s</p>', $view->errors());
        self::assertSame('<b>%d Use 100% or %s</b>', $view->errors('<b>%d %s</b>'));

        $this->expectException(FormConfigException::class);

        $view->errors('<p>no placeholder</p>');
    }

    public function testChoicesWithNumericKeysAreComparedAsStrings(): void
    {
        $this->stubEscapers();
        // PHP は '1' のような数字の文字列のキーを int にする
        $field = Field::select('size', 'Size', ['1' => 'Small', '2' => 'Large']);

        self::assertSame([1 => 'Small', 2 => 'Large'], $field->choices);
        self::assertStringContainsString('<option value="2" selected>Large</option>', (new FieldView($field, '2', [], 'contact', '%s'))->select());
        self::assertSame('Large', (new FieldView($field, '2', [], 'contact', '%s'))->display());
    }

    public function testTempFileStoreResolvesMovesDeletesAndGarbageCollectsFiles(): void
    {
        Functions\when('wp_unique_filename')->alias(static fn(string $_directory, string $filename): string => $filename);

        $baseDir = $this->createTempDirectory('temp-file-store');
        $store = new TempFileStore($baseDir);
        $token = '0123456789abcdef0123456789abcdef';
        $this->createStoredFile($baseDir, $token, 'document.txt', 'text/plain', 'text', time() - 100);

        $stored = $store->resolve($token);

        self::assertSame('document.txt', $stored->clientName);
        self::assertTrue($store->exists($token));

        $targetDir = $this->createTempDirectory('temp-file-target');
        $target = $stored->moveTo($targetDir, 'moved.txt');

        self::assertSame($targetDir . DIRECTORY_SEPARATOR . 'moved.txt', $target);
        self::assertFileExists($target);
        self::assertFalse($store->exists($token));

        $oldToken = 'ffffffffffffffffffffffffffffffff';
        $this->createStoredFile($baseDir, $oldToken, 'old.txt', 'text/plain', 'old', time() - 86401);

        self::assertSame(1, $store->gc(86400));
        self::assertFalse($store->exists($oldToken));
    }

    public function testTempFileStoreRejectsInvalidTokensAndBrokenMetadata(): void
    {
        $baseDir = $this->createTempDirectory('temp-file-invalid');
        $store = new TempFileStore($baseDir);
        $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $dir = $baseDir . DIRECTORY_SEPARATOR . $token;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/meta.json', '{broken');

        $this->expectException(TempFileException::class);
        $store->resolve($token);
    }

    /**
     * resolve() は、トークンの形(32桁の小文字の16進数)でない値を、パスにする前に TempFileException で拒否する。保管場所の
     * 外に保管済みのファイルと同じ形のファイルを置き、`../` 等でそれを指しても読めないことを確かめる。
     */
    public function testTempFileStoreResolveRejectsTokensThatAreNotOfTheTokenForm(): void
    {
        $root = $this->createTempDirectory('temp-file-traversal');
        $baseDir = $root . DIRECTORY_SEPARATOR . 'store';
        self::assertTrue(mkdir($baseDir));
        $store = new TempFileStore($baseDir);
        $outsideToken = '0123456789abcdef0123456789abcdef';
        // 保管場所の外(親ディレクトリ)の、保管済みのファイルと同じ形のファイル
        $this->createStoredFile($root, 'sibling', 'secret.txt', 'text/plain', 'secret', time());
        $this->createStoredFile($root, $outsideToken, 'secret.txt', 'text/plain', 'secret', time());

        foreach ([
            '../sibling',
            '../' . $outsideToken,
            'store/../../sibling',
            $root . DIRECTORY_SEPARATOR . 'sibling',
            '..',
            '',
            strtoupper($outsideToken),
            substr($outsideToken, 0, 31),
            $outsideToken . "\n",
        ] as $token) {
            try {
                $store->resolve($token);
                self::fail('resolve() accepted ' . var_export($token, true));
            } catch (TempFileException $exception) {
                self::assertSame("invalid temporary file token: {$token}", $exception->getMessage());
            }

            self::assertFalse($store->exists($token), var_export($token, true));
        }
    }

    public function testTempFileStoreCreatesProtectedBaseDirectoryOnFirstStore(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('sanitize_file_name')->alias(static fn(string $filename): string => $filename);
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        // 期限切れのファイルを消す WP-Cron のイベントはスケジュール済み
        Functions\when('wp_next_scheduled')->justReturn(1);

        $tmpPath = $this->createTempDirectory('temp-file-protection-upload') . '/upload.txt';
        file_put_contents($tmpPath, 'sample upload');
        $baseDir = $this->createTempDirectory('temp-file-protection') . '/store';
        $store = new TempFileStore($baseDir);

        self::assertDirectoryDoesNotExist($baseDir);

        $stored = $store->store(new UploadedFile($tmpPath, 'upload.txt', 'text/plain', 13, UPLOAD_ERR_OK));

        self::assertDirectoryExists($baseDir);
        // Apache 2.4(mod_authz_core)と 2.2 の両方の構文で拒否する
        $htaccess = (string) file_get_contents($baseDir . '/.htaccess');
        self::assertStringContainsString("<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>", $htaccess);
        self::assertStringContainsString("<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>", $htaccess);
        self::assertSame('', file_get_contents($baseDir . '/index.html'));
        self::assertStringStartsWith($baseDir . DIRECTORY_SEPARATOR, $stored->path);

        // 2回目以降の保管では書き直さない
        file_put_contents($baseDir . '/.htaccess', 'edited by the site');
        file_put_contents($baseDir . '/index.html', 'edited by the site');
        file_put_contents($tmpPath, 'second upload');
        $store->store(new UploadedFile($tmpPath, 'upload.txt', 'text/plain', 13, UPLOAD_ERR_OK));

        self::assertSame('edited by the site', file_get_contents($baseDir . '/.htaccess'));
        self::assertSame('edited by the site', file_get_contents($baseDir . '/index.html'));
    }

    public function testStoredFileIsSavedUnderAFixedNameSoAClientNameOfMetaJsonCannotOverwriteTheMetadata(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('sanitize_file_name')->alias(static fn(string $filename): string => $filename);
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        // 期限切れのファイルを消す WP-Cron のイベントはスケジュール済み
        Functions\when('wp_next_scheduled')->justReturn(1);
        $tmpPath = $this->createTempDirectory('meta-json-upload') . '/upload';
        file_put_contents($tmpPath, 'uploaded contents');
        $store = new TempFileStore($this->createTempDirectory('meta-json-store'));

        $stored = $store->store(new UploadedFile($tmpPath, 'meta.json', 'application/json', 17, UPLOAD_ERR_OK));

        // 保存名にクライアントのファイル名を使わない
        self::assertSame(TempFileStore::FILE_NAME, basename($stored->path));
        self::assertSame('meta.json', $stored->clientName);
        self::assertSame('uploaded contents', file_get_contents($stored->path));

        $resolved = $store->resolve($stored->token);

        self::assertSame($stored->path, $resolved->path);
        self::assertSame('meta.json', $resolved->clientName);
        self::assertSame('uploaded contents', file_get_contents($resolved->path));
    }

    public function testTempFileStoreDetectsMimeBeforeMovingUploadedFile(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('sanitize_file_name')->alias(static fn(string $filename): string => $filename);
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        // 期限切れのファイルを消す WP-Cron のイベントはスケジュール済み
        Functions\when('wp_next_scheduled')->justReturn(1);

        $tmpPath = $this->createTempDirectory('uploaded-mime') . '/upload.txt';
        file_put_contents($tmpPath, 'sample upload');
        $store = new TempFileStore($this->createTempDirectory('uploaded-mime-store'));

        $stored = $store->store(new UploadedFile($tmpPath, 'upload.txt', 'text/plain', 13, UPLOAD_ERR_OK));

        self::assertSame('text/plain', $stored->mime);
        self::assertFileExists($stored->path);
        self::assertFileDoesNotExist($tmpPath);
    }

    public function testUploadedFileMimeDetectionFailuresAreTempFileExceptionsInsteadOfADefaultMime(): void
    {
        $missing = new UploadedFile($this->createTempDirectory('mime-missing') . '/gone.txt', 'gone.txt', 'text/plain', 1, UPLOAD_ERR_OK);

        try {
            $missing->detectedMime();
            self::fail('TempFileException was not thrown for a file that cannot be read');
        } catch (TempFileException $exception) {
            self::assertStringStartsWith('failed to detect the MIME type of the uploaded file ', $exception->getMessage());
        }

        $path = $this->createTempDirectory('mime-magic') . '/upload.txt';
        file_put_contents($path, 'sample upload');
        $file = new UploadedFile($path, 'upload.txt', 'text/plain', 13, UPLOAD_ERR_OK);
        // libmagic は環境変数 MAGIC の magic のデータベースを読む。読めないデータベースで finfo の初期化を失敗させる
        $magic = getenv('MAGIC');
        putenv('MAGIC=' . $path . '.missing-magic');

        try {
            $file->detectedMime();
            self::fail('TempFileException was not thrown for finfo that cannot be initialized');
        } catch (TempFileException $exception) {
            self::assertSame('failed to initialize finfo to detect the MIME type of an uploaded file', $exception->getMessage());
            self::assertInstanceOf(Exception::class, $exception->getPrevious());
        } finally {
            putenv($magic === false ? 'MAGIC' : "MAGIC={$magic}");
        }

        self::assertSame('text/plain', $file->detectedMime());
    }

    public function testTempFileStoreDefaultRequiresWordPressUploadDir(): void
    {
        Functions\when('wp_upload_dir')->justReturn(['error' => 'failed']);

        $this->expectException(TempFileException::class);

        TempFileStore::default();
    }

    public function testUploadedFileRequiresRealUploadedFile(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->justReturn(false);

        $path = $this->createTempDirectory('uploaded-file') . '/local.txt';
        file_put_contents($path, 'local');
        $file = new UploadedFile($path, 'local.txt', 'text/plain', 5, UPLOAD_ERR_OK);

        self::assertFalse($file->isValid());

        $this->expectException(TempFileException::class);

        (new TempFileStore($this->createTempDirectory('uploaded-store')))->store($file);
    }

    public function testStoreSchedulesTheGarbageCollectionOnlyWhenItIsNotScheduledYet(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('sanitize_file_name')->returnArg();
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\expect('wp_next_scheduled')->twice()->with(TempFileGc::HOOK)->andReturn(false, 1_900_000_000);
        Functions\expect('wp_schedule_event')->once()->with(Mockery::type('int'), 'hourly', TempFileGc::HOOK, [], true)->andReturn(true);
        $uploadDir = $this->createTempDirectory('schedule-upload');
        $store = new TempFileStore($this->createTempDirectory('schedule-store'));

        foreach (['first.txt', 'second.txt'] as $name) {
            file_put_contents($uploadDir . '/' . $name, 'upload');
            $store->store(new UploadedFile($uploadDir . '/' . $name, $name, 'text/plain', 6, UPLOAD_ERR_OK));
        }
    }

    /** @return iterable<string, array{int, non-empty-string}> 削除を失敗させるディレクトリの権限と、例外のメッセージの先頭 */
    public static function undeletableDirectories(): iterable
    {
        yield 'files cannot be unlinked' => [0500, 'failed to delete temporary file: '];
        yield 'directory cannot be listed' => [0300, 'failed to list temporary file directory: '];
    }

    /** @param non-empty-string $message */
    #[DataProvider('undeletableDirectories')]
    public function testFailuresToDeleteTemporaryFilesAreExceptions(int $mode, string $message): void
    {
        $baseDir = $this->createTempDirectory('delete-failure');
        $store = new TempFileStore($baseDir);
        $token = '0123456789abcdef0123456789abcdef';
        $this->createStoredFile($baseDir, $token, 'document.txt', 'text/plain', 'text', time());
        $stored = $store->resolve($token);
        $failures = [
            'TempFileStore::delete()' => static function () use ($store, $token): void {
                $store->delete($token);
            },
            'StoredFile::delete()' => static function () use ($stored): void {
                $stored->delete();
            },
        ];

        foreach ($failures as $case => $delete) {
            $exception = $this->withReadOnlyDirectory($baseDir . DIRECTORY_SEPARATOR . $token, $mode, static function () use ($delete): ?TempFileException {
                try {
                    $delete();
                } catch (TempFileException $exception) {
                    return $exception;
                }

                return null;
            });

            self::assertInstanceOf(TempFileException::class, $exception, $case);
            self::assertStringStartsWith($message, $exception->getMessage(), $case);
        }

        $store->delete($token);

        self::assertFalse($store->exists($token));
        // 既に無いものの削除は何もしない
        $store->delete($token);
        $stored->delete();
    }

    #[DataProvider('undeletableDirectories')]
    public function testGarbageCollectionContinuesAfterAFailedDeletionAndReportsTheFailureAtTheEnd(int $mode, string $message): void
    {
        $baseDir = $this->createTempDirectory('gc-failure');
        $store = new TempFileStore($baseDir);
        $locked = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $expired = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $fresh = 'cccccccccccccccccccccccccccccccc';
        $this->createStoredFile($baseDir, $locked, 'locked.txt', 'text/plain', 'locked', time() - 86401);
        $this->createStoredFile($baseDir, $expired, 'expired.txt', 'text/plain', 'expired', time() - 86401);
        $this->createStoredFile($baseDir, $fresh, 'fresh.txt', 'text/plain', 'fresh', time() - 60);
        // トークンの形でない名前のディレクトリは、古くても対象にしない
        mkdir($baseDir . '/not-a-token');
        touch($baseDir . '/not-a-token', time() - 86401);
        file_put_contents($baseDir . '/.htaccess', 'deny');

        $exception = $this->withReadOnlyDirectory($baseDir . DIRECTORY_SEPARATOR . $locked, $mode, static function () use ($store): ?TempFileException {
            try {
                $store->gc(86400);
            } catch (TempFileException $exception) {
                return $exception;
            }

            return null;
        });

        self::assertInstanceOf(TempFileException::class, $exception);
        self::assertStringStartsWith('1 expired temporary file directories could not be deleted (1 deleted): ' . $message, $exception->getMessage());
        self::assertInstanceOf(TempFileException::class, $exception->getPrevious());
        // 削除に失敗したディレクトリは残る(0300 では、名前で消せる本体とメタデータは消えてから一覧の取得で失敗する)
        self::assertDirectoryExists($baseDir . DIRECTORY_SEPARATOR . $locked);
        self::assertFalse($store->exists($expired));
        self::assertTrue($store->exists($fresh));
        self::assertDirectoryExists($baseDir . '/not-a-token');
        self::assertFileExists($baseDir . '/.htaccess');

        // 次の gc で消える(メタデータが消えた場合はディレクトリの更新時刻で期限を判定するため、古い時刻にする)
        self::assertTrue(touch($baseDir . DIRECTORY_SEPARATOR . $locked, time() - 86401));
        self::assertSame(1, $store->gc(86400));
        self::assertDirectoryDoesNotExist($baseDir . DIRECTORY_SEPARATOR . $locked);
    }

    /**
     * ディレクトリの権限を $mode にして $callback を呼び、戻り値を返す。0500 なら中のファイルの unlink() が、0300 なら一覧の取得が
     * 失敗する(0300 でも、名前で指定する本体とメタデータの unlink() は成功する)。
     * 失敗の警告はテストの失敗にしない。
     *
     * @template T
     *
     * @param int $mode chmod() の権限
     * @param Closure(): T $callback
     *
     * @return T
     */
    private function withReadOnlyDirectory(string $dir, int $mode, Closure $callback): mixed
    {
        self::assertTrue(chmod($dir, $mode));
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return $callback();
        } finally {
            restore_error_handler();
            chmod($dir, 0700);
        }
    }

    private function stubEscapers(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }

    private function createStoredFile(string $baseDir, string $token, string $name, string $mime, string $contents, int $created): void
    {
        $dir = $baseDir . DIRECTORY_SEPARATOR . $token;

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("一時ファイルディレクトリを作成できません: {$dir}");
        }

        file_put_contents($dir . DIRECTORY_SEPARATOR . TempFileStore::FILE_NAME, $contents);
        file_put_contents($dir . '/meta.json', json_encode([
            'created' => $created,
            'mime' => $mime,
            'name' => $name,
            'size' => \strlen($contents),
        ], JSON_THROW_ON_ERROR));
    }
}
