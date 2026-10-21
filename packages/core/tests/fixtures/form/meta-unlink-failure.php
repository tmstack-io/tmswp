<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

/*
 * TempFileStore・StoredFile の unlink() を、メタデータのファイル(TempFileStore::META_NAME)だけ失敗させる(本体の
 * ファイルは消す)。一時ファイルの削除が途中で失敗した状態を作るテストが、別のプロセスで読み込む。
 */
function unlink(string $filename): bool
{
    if (basename($filename) === TempFileStore::META_NAME) {
        return false;
    }

    return \unlink($filename);
}
