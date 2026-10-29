<?php

declare(strict_types=1);

/*
 * ファイルの項目のあるフォームのページ。FormFileIntegrationTest・FormTempFileIntegrationTestがwp-envの開発用サイトへ
 * HTTPでファイルを送り、実際のアップロード(is_uploaded_file() が真になる $_FILES)とフロントのリクエスト
 * (wp-admin/includesを読み込んでいない)で、次を確かめる。
 * - onComplete に StoredFile が渡り、toAttachment() で添付ファイルにできる(確認画面の有無の両方)。
 * - onComplete が消費しなかった一時ファイルが、完了の後に消える。
 * - 再送の拒否・onCompleteの例外・一時保管の失敗の扱いと、保管によるgcのイベントのスケジュール。
 * クエリで次を選ぶ。
 * - confirm=1: 確認画面ありのフォーム。consume=0: onCompleteがファイルを消費しないフォーム。
 * - kernel=production|debug: そのリクエストの中だけの一時ディレクトリをルートとするプラグインのKernelを
 *   muplugins_loadedでbootし(debugの値はクエリのとおり)、そのルートの中のファイルでフォームを作る。ログの記録先と
 *   debugの判定がこのKernelになり、記録をreportのlogsに返す。debugでhandle() が投げた例外はreportのthrownに返す。
 * - fail=complete: onCompleteが、reportを書いた後(ファイルを消費する前)に例外を投げる。
 * - fail=store: 一時保管の場所を、作れない場所(このファイルの下)にして、保管を失敗させる。
 * - fail=delete: onCompleteが、ファイルを消費せずに、そのトークンのディレクトリにサブディレクトリを作る。完了の後の
 *   一時ファイルの削除が失敗する(トークンのディレクトリはファイルだけを持つ前提のため)。consume=0と一緒に使う。
 *   残ったトークンのディレクトリは、応答を作った後にこのページが消す(cleanupのtokenDirectoryLeftに結果を返す)。
 * - jti=keep: 送信を完了してもjtiを手放さない(次のリクエストで再送を確かめる)。jti=release: 送信の完了に関係なく、
 *   送られた送信トークン・確認トークンのjtiを手放す(再送を確かめたリクエストの後始末)。
 * 結果は JSON で返す。作った添付ファイルは、開発用サイトに残さないよう、その場で消す。
 *
 * 開発用サイトの DB は PHPUnit のテストの DB と違い、テストの後に戻らない。そのため、応答を作った後に、このリクエストが
 * 作ったものだけを消す(cleanup に結果を返し、テストが確かめる)。
 * - 送信を完了したリクエストがclaimしたjti(送信トークン・確認トークンから求める。jti=keep・jti=releaseは上のとおり)
 * - このリクエストが新しくスケジュールした一時ファイルの gc の WP-Cron のイベント(元からあったイベントは消さない)
 */

use TMSWP\Core\Form\ConfirmToken;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\JtiStore;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\Core\Form\TempFileGc;
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\RecordingLogger;

$kernelMode = $_GET['kernel'] ?? '';
$kernelRoot = null;
$kernelLogger = null;

if ($kernelMode === 'production' || $kernelMode === 'debug') {
    $kernelRoot = sys_get_temp_dir() . '/tmswp-form-file-' . bin2hex(random_bytes(8));
    register_shutdown_function(static function () use ($kernelRoot): void {
        if (!is_dir($kernelRoot)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kernelRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($kernelRoot);
    });

    // プラグインの読み込みの前(initの前)にbootするため、WordPressの読み込みの前にフックを登録しておく
    $GLOBALS['wp_filter']['muplugins_loaded'][10][] = [
        'function' => static function () use ($kernelRoot, $kernelMode, &$kernelLogger): void {
            require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
            mkdir($kernelRoot . '/app', 0700, true);
            file_put_contents($kernelRoot . '/plugin.php', "<?php\n");
            file_put_contents($kernelRoot . '/form-factory.php', <<<'PHP'
<?php

declare(strict_types=1);

return static fn(\TMSWP\Core\Form\FormDefinition $definition): \TMSWP\Core\Form\Form => new \TMSWP\Core\Form\Form($definition);
PHP);
            $kernelLogger = $logger = new RecordingLogger();
            $config = KernelConfig::plugin(
                $kernelRoot . '/plugin.php',
                $kernelRoot . '/app',
                'TmswpHttpFormFile' . bin2hex(random_bytes(4)),
                $kernelMode === 'debug',
                logChannels: static fn(): array => ['http' => new LogChannel($logger)],
            );
            (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
            Kernel::boot($config);
        },
        'accepted_args' => 1,
    ];
}

require dirname(__DIR__, 6) . '/wp-load.php';
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$consume = ($_GET['consume'] ?? '1') === '1';
$confirmEnabled = ($_GET['confirm'] ?? '') === '1';
$fail = $_GET['fail'] ?? '';
$jtiMode = $_GET['jti'] ?? '';
$report = ['mediaFunctionsLoadedBeforeComplete' => function_exists('media_handle_sideload')];

$definition = FormDefinition::create('file_probe')
    ->file('document', 'Document', [new Required()])
    ->confirm($confirmEnabled)
    ->onComplete(static function (array $values) use (&$report, $consume, $fail): void {
        $file = $values['document'];
        $report['receivedType'] = get_debug_type($file);

        if (!$file instanceof StoredFile) {
            return;
        }

        $report['tokenDirectory'] = dirname($file->path);
        $report['storedName'] = basename($file->path);
        $report['clientName'] = $file->clientName;
        $report['storedContents'] = file_get_contents($file->path);

        if ($fail === 'complete') {
            throw new RuntimeException('onComplete failed on purpose');
        }

        if ($fail === 'delete') {
            $report['blockerCreated'] = mkdir(dirname($file->path) . '/blocker');
        }

        if (!$consume) {
            return;
        }

        $attachmentId = $file->toAttachment();
        $attachedFile = get_attached_file($attachmentId);
        $report['attachmentId'] = $attachmentId;
        $report['attachmentMime'] = get_post_mime_type($attachmentId);
        $report['attachedContents'] = is_string($attachedFile) ? file_get_contents($attachedFile) : false;
        $report['tokenDirectoryExistsAfterAttachment'] = is_dir(dirname($file->path));
        wp_delete_attachment($attachmentId, true);
    });

if ($fail === 'store' || $fail === 'delete') {
    // mkdir()・rmdir() の失敗の警告をJSONの応答に混ぜない
    ini_set('display_errors', '0');
}

if ($fail === 'store') {
    // このファイルの下にはディレクトリを作れない
    $definition->tempFileStore(new TempFileStore(__FILE__ . '/tmswp-form-tmp'));
}

/** 既定の一時保管の場所にあるトークンのディレクトリの名前 */
$storedTokens = static function (): array {
    $tokens = [];

    foreach (glob(wp_upload_dir()['basedir'] . '/tmswp-form-tmp/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (preg_match('/\A[a-f0-9]{32}\z/', basename($dir)) === 1) {
            $tokens[] = basename($dir);
        }
    }

    sort($tokens);

    return $tokens;
};

$form = $kernelRoot !== null ? (require $kernelRoot . '/form-factory.php')($definition) : new Form($definition);
$gcEventExisted = wp_next_scheduled(TempFileGc::HOOK) !== false;
$report['gcEventExistedBeforeHandle'] = $gcEventExisted;
$report['storedTokensBeforeHandle'] = $storedTokens();
$result = null;

try {
    $result = $form->handle();
} catch (Throwable $exception) {
    if ($kernelMode !== 'debug') {
        throw $exception;
    }

    $report['thrown'] = ['class' => $exception::class, 'message' => $exception->getMessage()];
}

$report['storedTokensAfterHandle'] = $storedTokens();
$report['gcScheduleAfterHandle'] = wp_get_schedule(TempFileGc::HOOK);

if ($kernelLogger instanceof RecordingLogger) {
    $report['logs'] = $kernelLogger->records;
}

if (isset($report['tokenDirectory']) && is_string($report['tokenDirectory'])) {
    $report['tokenDirectoryExistsAfterComplete'] = is_dir($report['tokenDirectory']);
}

$html = $result !== null ? $form->open() . $form->close() : '';
$cleanup = ['jtiClaimedBeforeCleanup' => null, 'jtiClaimedAfterCleanup' => null, 'gcEventCreated' => false, 'gcEventLeft' => false];

if ($fail === 'delete' && isset($report['tokenDirectory']) && is_string($report['tokenDirectory']) && is_dir($report['tokenDirectory'])) {
    rmdir($report['tokenDirectory'] . '/blocker');
    TempFileStore::deleteDirectory($report['tokenDirectory']);
    $cleanup['tokenDirectoryLeft'] = is_dir($report['tokenDirectory']);
}

if (($result?->step === FormStep::Complete && $jtiMode !== 'keep') || $jtiMode === 'release') {
    $jti = $confirmEnabled
        ? ConfirmToken::verify('file_probe', (string) wp_unslash($_POST[Form::FIELD_CONFIRM_TOKEN] ?? ''))->jti
        : SubmitToken::verify('file_probe', (string) wp_unslash($_POST[Form::FIELD_SUBMIT_TOKEN] ?? ''))->jti;
    $jtiStore = new JtiStore();
    $cleanup['jtiClaimedBeforeCleanup'] = $jtiStore->isClaimed($jti);
    $jtiStore->release($jti);
    $cleanup['jtiClaimedAfterCleanup'] = $jtiStore->isClaimed($jti);
}

if (!$gcEventExisted && wp_next_scheduled(TempFileGc::HOOK) !== false) {
    $cleanup['gcEventCreated'] = true;
    wp_clear_scheduled_hook(TempFileGc::HOOK);
    $cleanup['gcEventLeft'] = wp_next_scheduled(TempFileGc::HOOK) !== false;
}

header('Content-Type: application/json');

echo json_encode([
    'step' => $result !== null ? $result->step->value : 'exception',
    'fieldErrors' => $result?->fieldErrors ?? [],
    'formErrors' => $result?->formErrors ?? [],
    'html' => $html,
    'report' => $report,
    'cleanup' => $cleanup,
], JSON_THROW_ON_ERROR);
