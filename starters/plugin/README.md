# {{project_name}}

TMStack WordPress Framework を使用したWordPressプラグイン。

## セットアップ

```bash
composer install
composer qa
```

`composer create-project --no-scripts` で作成した場合は、先にセットアップを実行する。

```bash
php bin/setup.php
```

新規プロジェクトはこの雛形から作成する。

```bash
composer create-project tmswp/starter-plugin my-plugin
```

## 開発規約

- 開発規約・禁止事項・必須コマンド: [AGENTS.md](./AGENTS.md)
- タスク別レシピ: `vendor/tmswp/core/rules/*.md`
