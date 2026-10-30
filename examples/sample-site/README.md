# TMSWP Sample Site

TMStack WordPress Framework(TMSWP)の主な機能を、実物のWordPressで動かして見せるテーマです。テーマのスターター(`starters/theme`)から`bin/setup.php`(スラッグ`sample-site`、名前空間`SampleSite\`)で作り、機能の例を足しています。

monorepoの`packages/*`を、Composerのpathリポジトリで使います。案件のテーマは、Packagistから`composer require`で入れます(下の「lockとpathリポジトリ」)。

## 機能とコードの場所

| 機能 | 内容 | コードの場所 |
|---|---|---|
| Kernelの構成 | 拡張の登録(`extensions`)・ブロックカテゴリ・ラベルの言語 | `tmswp-kernel.php`、`functions.php` |
| 投稿タイプ | 製品(`product`)。製品の編集画面で使えるブロックを`allowedBlocks()`で限る | `app/Post/ProductPost.php`、`single-product.php` |
| フォーム | お問い合わせ(入力・確認・完了)。完了でメールを送り、完了画面へリダイレクトする | `app/Form/ContactForm.php`、`app/Page/ContactPage.php`、`app/Mail/`、`page-contact.php` |
| 設定ページ(tmswp/settings-page) | 「設定 > Sample Site」。お問い合わせの通知先とトップページの説明文を保存する | `app/SettingsPage/SiteSettingsPage.php`、`app/Option/SiteOption.php` |
| サムネイル(tmswp/thumbnail) | 製品のカードのアイキャッチを`tms_thumb()`の署名付きのURLで出す | `parts/product-card.php` |
| 日本語対応(tmswp/ja-toolkit) | `JapaneseSlug`(日本語のタイトルのスラッグを`product-123`の形にする)と、フォームの`KanaTextField`(フリガナ) | `tmswp-kernel.php`、`app/Form/ContactForm.php` |
| ACF(tmswp/acf-bridge) | フィールドグループ「製品情報」。価格(number)は`#[MetaSchema]`と`AcfMetaStorage`で`MetaBag`から、仕様(group)は生成アクセサ`ProductFields`から読む | `acf-json/group_product.json`、`app/Acf/`(生成物)、`app/Page/ProductSinglePage.php` |
| ブロック | 新しい製品を並べる動的ブロック`sample-site/latest-products`(カテゴリ`sample-site`) | `blocks/latest-products/`(ソース)、`build/blocks/latest-products/`(ビルドの出力) |
| テンプレートの前処理 | テンプレートが使う値を用意するクラス | `app/Page/`(`front-page.php`・`single-product.php`・`page-contact.php`の先頭で呼ぶ) |

ACFは、CIと同じwp.orgの無料版(6.8.10)で動く型(number・group・text)だけを使います。repeater・flexible_content・オプションページ等のACF Pro専用の機能は含めません。

画面の文言は、ソースを英語にしてテキストドメイン`sample-site`で`__()`に渡しています(翻訳ファイルは置いていません)。投稿タイプのラベルは、`tmswp-kernel.php`の`labelLanguage`で日本語にしています。

## 必要なもの

- PHP 8.3〜8.5と、Composer 2
- Node.js 22.13以上(monorepoのルートと同じ。`@wordpress/env` 12.0.0の要件)と、Docker(wp-envが使う)

## 起動の手順

monorepoのルートで、版を固定した`@wordpress/env`を入れます。sample-siteのwp-envも、ルートの`node_modules/@wordpress/env`をパスで呼びます(`npx wp-env`は使いません)。

```bash
# monorepoのルート
npm ci
```

sample-siteのディレクトリで、依存を入れて、wp-envを起動します。

```bash
cd examples/sample-site
composer install      # composer.lockを作る(lockはコミットしない)
npm ci                # ブロックのビルドの道具(@wordpress/scripts)
composer wp:start     # node ../../node_modules/@wordpress/env/bin/wp-env start
composer wp:setup     # テーマを有効にし、dev/seed.phpで固定ページ・製品・設定を作る(何回実行してもよい)
```

- サイトは<http://localhost:8882>で開きます。管理画面は<http://localhost:8882/wp-admin>(ユーザー`admin`、パスワード`password`)。
- ルートの環境(`.wp-env.json`。既定のポートは8888)とは、ポートとコンテナが別です。同時に起動できます。ポートを変えるときは、`examples/sample-site/.wp-env.override.json`(コミットしない)に`{"port": 8883}`のように書きます。
- 止めるときは`composer wp:stop`です。

`dev/`は開発環境だけで使うファイルで、テーマの実行時には読みません。

| ファイル | 役割 |
|---|---|
| `dev/seed.php` | 確認用の固定ページ(`/contact/`・`/contact/thanks/`・`/blocks/`)、アイキャッチ・価格・仕様の付いた製品、トップページの説明文を作る |
| `dev/sample-product.jpg` | 製品のアイキャッチに取り込む画像 |
| `dev/mu-plugins/sample-site-mail-capture.php` | wp-envがmu-pluginとして読み込む。`wp_mail()`のメールを送らずに、オプション`sample_site_captured_mails`に記録する(wp-envのコンテナにはメールを送る手段が無いため) |

## 開発のコマンド

| コマンド | 内容 |
|---|---|
| `composer qa` | lint・analyse(PHPStan level max)・`acf:check --strict`・探索結果ファイルの照合(`discover:check`)・ユニットテスト。照合はwp-envで行うため、`composer wp:start`の後に実行する |
| `npm run build` | `blocks/`を`build/blocks/`にビルドし、続けて探索結果ファイル(`tmswp-discovery.php`)を作り直す(`composer discover`) |
| `composer discover` | 探索結果ファイルを作り直す(wp-envで`wp tmswp discover`)。`app/`・`build/blocks/`を変えたら実行してコミットする |
| `composer acf:sync` | `acf-json/`から`app/Acf/`を生成する(`vendor/bin/tms-acf sync`。WordPressは要らない) |
| `composer acf:check` | `acf-json/`と`app/Acf/`の食い違いを検査する(`vendor/bin/tms-acf check`) |
| `composer test` | ユニットテスト(`tests/Unit/`。基底は`tmswp/testing`の`UnitTestCase`) |

探索結果ファイル・`build/blocks/`・`app/Acf/`は生成物で、コミットします。手で編集しません。

## 確認の方法

`composer wp:start`と`composer wp:setup`の後に、次を確かめます。monorepoのルートの`composer test:e2e`(`tests/E2E/`。Playwright)が、同じ項目を自動で確かめます(`docs/10-quality.md` §4.10)。

1. トップページ(<http://localhost:8882/>)が200を返し、設定ページの説明文と、製品のカードが出る。カードのアイキャッチのURLは、サムネイルの生成の前はRESTのURL(`/wp-json/tmswp/v1/thumb?...`。302で生成したファイルへ移る)、生成の後はキャッシュの静的なURL(`/wp-content/uploads/tmswp-thumb/...`)になる。
2. 製品の詳細(トップページのカードから開く。URLは`/product/product-<ID>/`で、日本語のタイトルのスラッグが置き換わっている)に、価格と仕様(ACFの値)が出る。管理画面の製品の編集画面で値を変えると、表示が変わる。
3. <http://localhost:8882/blocks/>に、ブロック`sample-site/latest-products`の製品の一覧が出る。固定ページの編集画面で、カテゴリ「Sample Site」からこのブロックを挿入できる。製品の編集画面では、`allowedBlocks()`で許したブロックだけを使える。
4. 管理画面の「設定 > Sample Site」で、通知先と説明文を保存できる。メールアドレスの形でない通知先は保存されずにエラーになる。
5. <http://localhost:8882/contact/>のフォームで、入力・確認・送信ができ、送信の後に`/contact/thanks/`へ移る。フリガナのひらがな・半角カナは、確認画面で全角カタカナになる。送ったメールは次で読む。

   ```bash
   node ../../node_modules/@wordpress/env/bin/wp-env run cli wp option get sample_site_captured_mails --format=json
   ```

## lockとpathリポジトリ

- `composer.lock`はコミットしません(`.gitignore`の対象)。pathリポジトリのパッケージのlockは`packages/*`の内容を記録するため、`packages/*`を変えるたびに古くなるからです。手元では`composer install`でlockを作り、`packages/*`の依存(composer.json)を変えたときは`composer update 'tmswp/*'`で作り直します。lockが無い状態でも`composer validate`は通ります。
- pathリポジトリは`../../packages/*`を`symlink: true`で入れます(`vendor/tmswp/*`は`packages/*`へのシンボリックリンクになる)。`packages/*/src`の変更が、`composer install`をやり直さずにそのまま反映されます。wp-envのコンテナの中でもリンクの先が見えるよう、`.wp-env.json`が`packages/`を`wp-content/packages`にマウントしています(リンクは`vendor/tmswp/`からの相対パス`../../../../packages/*`で、テーマの位置`wp-content/themes/sample-site`から同じ場所を指す)。
- pathリポジトリの`versions`は、`tmswp/core`の`Kernel::VERSION`(0.9.1)に合わせます。Kernelは起動時に、入っている`tmswp/core`の版(`vendor/composer/installed.php`)と`Kernel::VERSION`を照合し、食い違えば起動しません。`Kernel::VERSION`を上げたときは、`versions`と`require`の制約(`^0.9.1`)も合わせて変えます。

## 開発規約

このテーマでコードを書くときの規約・禁止事項・必須コマンドは、[AGENTS.md](./AGENTS.md)にあります。機能ごとのレシピは`vendor/tmswp/*/rules/*.md`にあります(どれを読むかは、AGENTS.mdの「最初に読むもの」に書いています)。
