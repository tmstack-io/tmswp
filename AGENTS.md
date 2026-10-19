# TMSWP 開発ルール

このリポジトリは TMStack WordPress Framework 本体の開発用 monorepo です。実装は `docs/12-implementation-plan.md` のマイルストーン順に進めます。

- 対応範囲は PHP 8.3〜8.5、WordPress 7.0 以上とする。
- `tmswp/core` の runtime 依存は `psr/log` と `psr/container` のみに限定する。
- 旧 iwf2b 互換のシム、別名、移行用 API は追加しない。
- すべての PHP ファイルに `declare(strict_types=1)` を置き、公開 API は型宣言を必須とする。
- 品質確認は `composer lint` / `composer analyse` / `composer test:unit` / `composer test:integration` / `composer qa` を使う。
- wp-env は `npm ci` で入れたローカルの `@wordpress/env`(package.json で版を固定)を `composer wp:start` / `wp:stop` / `test:integration` 経由で使う。直接呼ぶときは `node node_modules/@wordpress/env/bin/wp-env` とパスで指定する。`npx` や npm の `exec` / `run` でコマンド名 `wp-env` を解決する呼び出しは、第三者のパッケージやグローバルの同名コマンドを実行しうるので使わない。
- 変更後は該当マイルストーンの受け入れ基準を `docs/acceptance/` に記録する。
