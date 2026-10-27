# TMStack WordPress Framework

TMStack WordPress Framework(TMSWP)は、PHP 8.3〜8.5・WordPress 7.0以上を対象にした、WordPressのテーマ・プラグインを作るためのフレームワークです。

- テーマ・プラグインの`app/`にAttributeを付けたクラスを置くと、Kernelが探索して投稿タイプ・タクソノミー・ロール・メタ・オプション・フック・独自テーブルを登録します。独自ブロックは、ビルドの出力(`build/blocks/`以下の`block.json`)を探索して登録します。フォームは探索の対象ではなく、`FormDefinition`で定義し、テンプレートの前処理クラスで明示的に作って使います。探索の結果は探索結果ファイル(`tmswp-discovery.php`)としてコミットし、本番はこのファイルだけを読みます。
- WordPress標準のテンプレート階層をそのまま使います。テンプレートで使う値は、テンプレートの先頭で呼ぶ前処理クラスが用意します。
- AIエージェントが実装することを前提に、規約をスターターの`AGENTS.md`に、機能ごとの手順を各パッケージの`rules/`(レシピ)に置いています。

このリポジトリは開発用のmonorepoです。パッケージとスターターは、公開のときにGitHub Actionsで読み取り専用のリポジトリへ同期し、Packagistに登録します。

## パッケージ

次の8つを、それぞれ読み取り専用のリポジトリ(`tmstack-io/tmswp-*`)として配布します。

| パッケージ | ディレクトリ | 内容 |
|---|---|---|
| `tmswp/core` | `packages/core` | Kernel・探索・Container・投稿タイプ/タクソノミー/ロール・メタ・オプション・フォーム・Model(独自テーブル)・テンプレート・ログ・独自ブロックの登録。runtimeの依存は`psr/log`と`psr/container`だけ |
| `tmswp/acf-bridge` | `packages/acf-bridge` | acf-jsonからの型付きアクセサの生成と照合(`tms-acf`)、メタの保存先をACFにする`AcfMetaStorage` |
| `tmswp/settings-page` | `packages/settings-page` | 管理画面の設定ページ(coreのフォームを使う) |
| `tmswp/thumbnail` | `packages/thumbnail` | 署名付きのURLで作るサムネイル(REST APIを使う。`ext-gd`が必要) |
| `tmswp/ja-toolkit` | `packages/ja-toolkit` | 日本語サイト向けの機能(すべてオプトイン。`ext-mbstring`が必要) |
| `tmswp/testing` | `packages/testing` | テストの支援コード(テストの基底クラスとテストダブル。利用側はrequire-devで入れる) |
| `tmswp/starter-theme` | `starters/theme` | テーマの雛形(`composer create-project`で使う) |
| `tmswp/starter-plugin` | `starters/plugin` | プラグインの雛形(`composer create-project`で使う) |

拡張(acf-bridge・settings-page・thumbnail・ja-toolkit)は、テーマ・プラグインの`tmswp-kernel.php`の`KernelConfig`の`extensions`に渡したKernelでだけ有効になります。

`examples/sample-site`は、テーマのスターターから作ったサンプルのテーマです。フォーム・設定ページ・サムネイル・日本語対応・ACF・ブロックを、専用のwp-envで動かせます(`examples/sample-site/README.md`)。

## 要件

- PHP 8.3〜8.5
- WordPress 7.0以上(クラシックテーマとプラグイン。ブロックテーマとマルチサイトは対象外)
- Composer 2

## 使い始め方

テーマ・プラグインは、スターターから作ります。Packagistへの公開はv0.9.0で行う予定で、まだ行っていません。公開の後は、次のコマンドで作れます。

```bash
# テーマ(wp-content/themes/で実行する)
composer create-project tmswp/starter-theme my-theme

# プラグイン(wp-content/plugins/で実行する)
composer create-project tmswp/starter-plugin my-plugin
```

作成の直後に、スターターの`bin/setup.php`がスラッグと表示名を尋ね、スラッグから作った名前空間(例: `my-theme` → `MyTheme\`)でプロジェクトを書き換えます。端末の無い環境では、`TMSWP_SETUP_SLUG`・`TMSWP_SETUP_NAME`の環境変数で渡します。その後の手順(探索結果ファイルの作成と`composer qa`)は、作ったプロジェクトのREADME.mdにあります。

公開の前は、このmonorepoの`tools/ci/create-starter-project.sh`で作ります。スターターと`tmswp/core`を、このリポジトリのディレクトリからComposerのpathリポジトリで入れます(CIのStarter Projectsのジョブと同じ手順)。

```bash
tools/ci/create-starter-project.sh theme ../my-theme my-theme 'My Theme'
tools/ci/create-starter-project.sh plugin ../my-plugin my-plugin 'My Plugin'
```

公開の前は、拡張のパッケージもPackagistから入れられません。拡張を使う場合は、作ったプロジェクトのcomposer.jsonに、このリポジトリの`packages/*`を指すpathリポジトリを足します(`examples/sample-site/composer.json`が例です)。

作ったプロジェクトでの開発の規約は、そのプロジェクトの`AGENTS.md`にあります。機能ごとの手順は`vendor/tmswp/*/rules/*.md`のレシピにあります。

## 開発の始め方

このmonorepoで開発するには、PHP 8.3〜8.5(lintは8.3で実行する)、Composer 2.7以上、Node.js 22.13以上、Docker、Gitが必要です。

```bash
composer install        # vendor/とvendor-bin/wp-tests/(統合テストのPHPUnit)を入れる
npm ci                  # 版を固定した@wordpress/envを入れる
composer qa:fast        # lint → analyse → test:unit(Docker不要)
composer wp:start       # wp-envを起動する(http://localhost:8888)
composer qa             # lint → analyse → acf:check:fixtures → discover:check → test(統合テストを含む)
```

wp-envは、`npm ci`で入れたローカルの`@wordpress/env`を`composer wp:start`・`wp:stop`等で使います。`npx wp-env`は使いません。開発のルール・コマンドの一覧・CI・新しいパッケージを足す手順は[AGENTS.md](./AGENTS.md)にあります。セットアップの詳細とトラブルシューティングは`docs/10-quality.md` §9にあります。

## 文書

- [docs/README.md](./docs/README.md): 設計書(`docs/00〜11`)の地図と読む順番
- [docs/12-implementation-plan.md](./docs/12-implementation-plan.md): v0.1.0〜v0.9.0のロードマップ
- [docs/acceptance/](./docs/acceptance/): 各版の受け入れ記録
- [docs/RELEASING.md](./docs/RELEASING.md): リリースの手順
- [CHANGELOG.md](./CHANGELOG.md): 変更履歴
- [AGENTS.md](./AGENTS.md): このmonorepoの開発ルール

## ライセンス

GPL-2.0-or-later([LICENSE](./LICENSE))。各パッケージも同じライセンスで、それぞれに`LICENSE`を置いています。
