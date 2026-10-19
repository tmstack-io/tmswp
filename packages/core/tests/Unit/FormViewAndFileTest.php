<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
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
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Core\Form\UploadedFile;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class FormViewAndFileTest extends UnitTestCase
{
    public function testFormHtmlAndFieldViewEscapeRenderedMarkup(): void
    {
        $this->stubEscapers();

        $definition = FormDefinition::create('contact')
            ->text('name', '名前')
            ->select('pref', '都道府県', ['tokyo' => '<東京>']);
        $form = new Form($definition);

        self::assertSame(
            '<input type="text" name="name" value="" id="contact-name" data-label="&lt;name&gt;">',
            $form->field('name')->input(['data-label' => '<name>']),
        );
        self::assertSame(
            '<select name="pref" id="contact-pref"><option value="tokyo">&lt;東京&gt;</option></select>',
            $form->field('pref')->select(),
        );
        self::assertSame('&lt;script&gt;', FormHtml::escHtml('<script>'));
    }

    public function testFieldViewRendersErrorsOptionsAndFileToken(): void
    {
        $this->stubEscapers();

        $checkboxes = new FieldView(
            Field::checkboxes('topics', '関心', ['news' => 'ニュース', 'docs' => '資料']),
            ['docs'],
            ['選択してください。'],
            'contact',
            '<span>%s</span>',
        );
        $file = new FieldView(
            Field::file('resume', '履歴書'),
            new StoredFile('0123456789abcdef0123456789abcdef', '/tmp/resume.pdf', 'resume.pdf', 'application/pdf', 10),
            [],
            'contact',
            '<span>%s</span>',
        );

        self::assertSame('<span>選択してください。</span>', $checkboxes->errors());
        self::assertSame('資料', $checkboxes->display());
        self::assertStringContainsString('name="topics[]" value="docs" checked', $checkboxes->checkboxes());
        self::assertSame('<input type="hidden" name="resume__token" value="0123456789abcdef0123456789abcdef">', $file->hidden());
    }

    public function testOptionViewRendersRadioAndLabel(): void
    {
        $this->stubEscapers();

        $option = new OptionView('yes', '同意', true, 'contact-agree-0', 'agree', FieldType::Radios);

        self::assertSame('<input type="radio" name="agree" value="yes" checked id="contact-agree-0">', $option->input());
        self::assertSame('<label for="contact-agree-0">同意</label>', $option->labelTag());
    }

    public function testFieldViewRejectsInvalidAttributeValue(): void
    {
        $this->expectException(FormConfigException::class);

        (new FieldView(Field::text('name', '名前'), '', [], 'contact', '%s'))->input(['value' => new stdClass()]);
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

    public function testTempFileStoreCreatesProtectedBaseDirectoryOnFirstStore(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('sanitize_file_name')->alias(static fn(string $filename): string => $filename);
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));

        $tmpPath = $this->createTempDirectory('temp-file-protection-upload') . '/upload.txt';
        file_put_contents($tmpPath, 'sample upload');
        $baseDir = $this->createTempDirectory('temp-file-protection') . '/store';
        $store = new TempFileStore($baseDir);

        self::assertDirectoryDoesNotExist($baseDir);

        $stored = $store->store(new UploadedFile($tmpPath, 'upload.txt', 'text/plain', 13, UPLOAD_ERR_OK));

        self::assertDirectoryExists($baseDir);
        self::assertSame("Require all denied\n", file_get_contents($baseDir . '/.htaccess'));
        self::assertSame('', file_get_contents($baseDir . '/index.html'));
        self::assertStringStartsWith($baseDir . DIRECTORY_SEPARATOR, $stored->path);
    }

    public function testTempFileStoreDetectsMimeBeforeMovingUploadedFile(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('sanitize_file_name')->alias(static fn(string $filename): string => $filename);
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));

        $tmpPath = $this->createTempDirectory('uploaded-mime') . '/upload.txt';
        file_put_contents($tmpPath, 'sample upload');
        $store = new TempFileStore($this->createTempDirectory('uploaded-mime-store'));

        $stored = $store->store(new UploadedFile($tmpPath, 'upload.txt', 'text/plain', 13, UPLOAD_ERR_OK));

        self::assertSame('text/plain', $stored->mime);
        self::assertFileExists($stored->path);
        self::assertFileDoesNotExist($tmpPath);
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

        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $contents);
        file_put_contents($dir . '/meta.json', json_encode([
            'created' => $created,
            'mime' => $mime,
            'name' => $name,
            'size' => \strlen($contents),
        ], JSON_THROW_ON_ERROR));
    }
}
