<?php

declare(strict_types=1);

namespace SampleSite\Mail;

use RuntimeException;

/** wp_mail()が送信に失敗した。メッセージに宛先・件名・本文を入れない */
final class MailSendException extends RuntimeException {}
