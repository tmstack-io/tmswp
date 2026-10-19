# TMStack WordPress Framework

TMStack WordPress Framework (TMSWP) は、PHP 8.3〜8.5 / WordPress 7.0 以上を前提にした WordPress テーマ・プラグイン制作用フレームワークです。

本リポジトリは開発用 monorepo です。`packages/` にコアと拡張、`starters/` にテーマ・プラグイン雛形を収載し、GitHub Actions の split ワークフローで配布用 read-only リポジトリへ展開する構成です。

## パッケージ

- `packages/core`: Kernel、Discovery、Container、Entity、Meta/Option、Form、Model、View、Log、Support
- `packages/thumbnail`: 署名付き URL サムネイル
- `packages/settings-page`: 管理画面設定ページ
- `packages/acf-bridge`: acf-json からの型付きアクセサ生成
- `packages/ja-toolkit`: 日本語サイト向けオプトインモジュール

## 開発コマンド

```bash
composer install
composer qa:fast
composer wp:start
composer qa
```

設計と実装計画は `docs/` を参照してください。
