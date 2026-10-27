# {{project_name}}

TMStack WordPress Framework を使用したWordPressプラグイン。

<!-- tmswp-starter-setup -->
## 新規プロジェクトの作成

新規プロジェクトはこの雛形から作成する。作成の直後に`bin/setup.php`が、スラッグから名前空間(例: `my-shop` → `MyShop\`)を作り、composer.json・本体ファイルの名前(`plugin.php`→`<スラッグ>.php`)・`tmswp-kernel.php`・`app/`・AGENTS.mdを書き換えて、自身を削除する。

```bash
composer create-project tmswp/starter-plugin my-plugin
```

スラッグ(英小文字・数字で、英字で始まり、語を1つの`-`か`_`でつなぐ)と表示名は、端末では対話で尋ねる。端末の無い環境(CI等)では、環境変数で渡す。渡さなければエラーで止まる。

```bash
TMSWP_SETUP_SLUG=my-plugin TMSWP_SETUP_NAME="My Project" composer create-project --no-interaction tmswp/starter-plugin my-plugin
```

`composer create-project --no-scripts`で作成した場合と、セットアップがエラーで止まった場合は、`bin/setup.php`を手で実行し、書き換えた名前空間でオートローダを作り直す(create-projectは、この2つを続けて実行する)。

```bash
php bin/setup.php --slug=my-plugin --name="My Project"
composer dump-autoload
```

<!-- /tmswp-starter-setup -->

## セットアップの後

`WP_DEBUG`を有効にしたWordPressのサイト(開発環境)でこのプラグインを有効にし、WP-CLIがそのサイトに届く場所で次を実行する。探索結果ファイル(`tmswp-discovery.php`)を作り、コミットする。探索結果ファイルを作るまでは、`WP_DEBUG`が無効のサイトでは起動時にエラーになる(debugのときだけ`app/`を毎回走査する)。

```bash
composer install
composer discover    # 探索結果ファイルの作成(wp tmswp discover)
composer qa
```

## 開発規約

- 開発規約・禁止事項・必須コマンド: [AGENTS.md](./AGENTS.md)
- タスク別レシピ: `vendor/tmswp/core/rules/*.md`(どのレシピを読むかはAGENTS.mdの「最初に読むもの」)
- 拡張のレシピ: 拡張を入れると`vendor/tmswp/<拡張>/rules/*.md`に入る
