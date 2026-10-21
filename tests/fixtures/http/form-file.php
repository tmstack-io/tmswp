<?php

declare(strict_types=1);

/*
 * ファイルの項目のあるフォームのページ。FormFileIntegrationTest が wp-env の開発用サイトへ HTTP でファイルを送り、
 * 実際のアップロード(is_uploaded_file() が真になる $_FILES)とフロントのリクエスト(wp-admin/includes を読み込んで
 * いない)で、次を確かめる。
 * - onComplete に StoredFile が渡り、toAttachment() で添付ファイルにできる(確認画面の有無の両方)。
 * - onComplete が消費しなかった一時ファイルが、完了の後に消える。
 * クエリの confirm=1 で確認画面ありのフォーム、consume=0 で onComplete がファイルを消費しないフォームにする。
 * 結果は JSON で返す。作った添付ファイルは、開発用サイトに残さないよう、その場で消す。
 *
 * 開発用サイトの DB は PHPUnit のテストの DB と違い、テストの後に戻らない。そのため、応答を作った後に、このリクエストが
 * 作ったものだけを消す(cleanup に結果を返し、テストが確かめる)。
 * - 送信を完了したリクエストが claim した jti(送信トークン・確認トークンから求める)
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

require dirname(__DIR__, 6) . '/wp-load.php';
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$consume = ($_GET['consume'] ?? '1') === '1';
$confirmEnabled = ($_GET['confirm'] ?? '') === '1';
$report = ['mediaFunctionsLoadedBeforeComplete' => function_exists('media_handle_sideload')];

$form = new Form(
    FormDefinition::create('file_probe')
        ->file('document', 'Document', [new Required()])
        ->confirm($confirmEnabled)
        ->onComplete(static function (array $values) use (&$report, $consume): void {
            $file = $values['document'];
            $report['receivedType'] = get_debug_type($file);

            if (!$file instanceof StoredFile) {
                return;
            }

            $report['tokenDirectory'] = dirname($file->path);
            $report['storedName'] = basename($file->path);
            $report['clientName'] = $file->clientName;
            $report['storedContents'] = file_get_contents($file->path);

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
        }),
);
$gcEventExisted = wp_next_scheduled(TempFileGc::HOOK) !== false;
$result = $form->handle();

if (isset($report['tokenDirectory']) && is_string($report['tokenDirectory'])) {
    $report['tokenDirectoryExistsAfterComplete'] = is_dir($report['tokenDirectory']);
}

$html = $form->open() . $form->close();
$cleanup = ['jtiClaimedBeforeCleanup' => null, 'jtiClaimedAfterCleanup' => null, 'gcEventCreated' => false, 'gcEventLeft' => false];

if ($result->step === FormStep::Complete) {
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
    'step' => $result->step->value,
    'fieldErrors' => $result->fieldErrors,
    'formErrors' => $result->formErrors,
    'html' => $html,
    'report' => $report,
    'cleanup' => $cleanup,
], JSON_THROW_ON_ERROR);
