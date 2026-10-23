<?php

declare(strict_types=1);

// 統合テスト用: テーマのルートの中にある、クラスの無いファイルから Log を静的に呼ぶ
TMSWP\Core\Log\Log::error('tmswp fixture theme log probe');
