<?php

declare(strict_types=1);

namespace TMSWP\Tools\Release;

use RuntimeException;

/**
 * リリースの道具(tools/release/)が、検査の不合格かgitのコマンドの失敗で、リリースを止めるときに投げる。
 */
final class ReleaseException extends RuntimeException {}
