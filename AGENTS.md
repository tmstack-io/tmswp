# TMSWP 開発ルール

このリポジトリは TMStack WordPress Framework 本体の開発用 monorepo です。実装は `docs/12-implementation-plan.md` のマイルストーン順に進めます。

- PHP は 8.2 以上、WordPress は 6.4 以上を下限とする。
- `tmswp/core` の runtime 依存は `psr/log` と `psr/container` のみに限定する。
- 旧 iwf2b 互換のシム、別名、移行用 API は追加しない。
- すべての PHP ファイルに `declare(strict_types=1)` を置き、公開 API は型宣言を必須とする。
- 品質確認は `composer lint` / `composer analyse` / `composer test:unit` / `composer test:integration` / `composer qa` を使う。
- 変更後は該当マイルストーンの受け入れ基準を `docs/acceptance/` に記録する。
