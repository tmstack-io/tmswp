# このプラグインの開発規約(TMSWP)

このプロジェクトは TMStack WordPress Framework を使うWordPressプラグインである。
あなた(AIエージェント)はこのファイルの規約に従って実装する。
フレームワーク本体(`vendor/`)はここでは開発しない。

## 最初に読むもの

タスクに応じて、該当レシピを必ず読んでから実装を始める。

| やりたいこと | 読むファイル |
|---|---|
| カスタム投稿タイプを追加する | `vendor/tmswp/core/rules/post-type.md` |
| カスタムタクソノミーを追加する | `vendor/tmswp/core/rules/taxonomy.md` |
| 投稿/ターム/ユーザーにカスタムフィールドを持たせる | `vendor/tmswp/core/rules/meta.md` |
| ACFでフィールドを管理する | `vendor/tmswp/core/rules/acf.md` |
| フォームを実装する | `vendor/tmswp/core/rules/form.md` |
| 独自DBテーブルを作る | `vendor/tmswp/core/rules/model.md` |
| サイト設定値を定義する | `vendor/tmswp/core/rules/options.md` |
| add_action/add_filter をまとめる | `vendor/tmswp/core/rules/hooks.md` |
| テンプレートパーツを扱う | `vendor/tmswp/core/rules/view.md` |
| 公式拡張を導入する | `vendor/tmswp/core/rules/extensions.md` |

## 構成

- `{{project_slug}}.php`: プラグインヘッダとFWブートのみ。
- `app/`: PHPクラス。名前空間は `PluginApp\`。
- `templates/`: プラグイン同梱テンプレート。テーマ側の同名ファイルが優先される。

プラグインでは `actions/` 規約を使わない。テンプレート単位の前処理が必要な場合は `app/Hook/` に置く。
レシピ内の `App\` は、このプラグインでは `PluginApp\` に読み替える。

## 必須コマンド

```bash
composer qa
composer analyse
composer fix
composer test
```

このスターターでは `composer test` はユニットテストのみを指す。

## 禁止事項

- `vendor/` 配下を編集しない。
- プラグイン本体ファイルにロジックを足さない。
- `register_post_type` / `register_taxonomy` / `register_post_meta` / `add_role` を直接呼ばない。
- 独自マイグレーション用に `register_activation_hook` を直接書かない。ModelのマイグレーションはFWが配線する。
- `$wpdb` を直接使わない。独自テーブルは `Model` を使う。
- `template_include` でテンプレート階層を迂回しない。
- `__call` / `__callStatic` / `extract()` を使わない。

## 完了条件

実装後は `composer qa` を通し、必要に応じてWordPress上で有効化・保存・CLI実行を確認する。
