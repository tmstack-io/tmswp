# このテーマの開発規約(TMSWP)

このプロジェクトはTMStack WordPress Framework(TMSWP)を使うWordPressテーマ(TMSWPのサンプルサイト)である。
テーマのスターター(monorepoの`starters/theme`)から作り、FWの主な機能の例を足している。
あなた(AIエージェント)はこのファイルの規約に従って実装する。
フレームワーク本体(`vendor/`。monorepoの`packages/*`へのシンボリックリンク)はここでは開発しない。

## 最初に読むもの

タスクに応じて、下表の該当レシピを必ず読んでから実装を始める。
レシピはフレームワークの各パッケージに同梱されており、このプロジェクトに入っている版と一致する。

このテーマの名前空間は`SampleSite\`である(composer.jsonの`autoload`の`psr-4`で`app/`に割り当てたもの)。
レシピのコード例の名前空間`App\`は、`SampleSite\`に読み替える。

| やりたいこと | 読むファイル |
|---|---|
| カスタム投稿タイプを追加する | `vendor/tmswp/core/rules/post-type.md` |
| カスタムタクソノミーを追加する | `vendor/tmswp/core/rules/taxonomy.md` |
| ユーザーロールを追加する | `vendor/tmswp/core/rules/user-role.md` |
| 投稿・ターム・ユーザーにカスタムフィールド(非ACF)を持たせる | `vendor/tmswp/core/rules/meta.md` |
| ACFでフィールドを管理する(acf-json) | `vendor/tmswp/core/rules/acf.md` |
| フォーム(問い合わせ・申し込み等)を実装する | `vendor/tmswp/core/rules/form.md` |
| フォームの完了処理でメールを送る | `vendor/tmswp/core/rules/form-mail.md` |
| テンプレートの前処理(値の用意・フォームの駆動)を書く | `vendor/tmswp/core/rules/page.md` |
| 独自DBテーブルを作って読み書きする | `vendor/tmswp/core/rules/model.md` |
| サイト設定値(オプション)を定義する | `vendor/tmswp/core/rules/options.md` |
| add_action・add_filterをまとめて登録する | `vendor/tmswp/core/rules/hooks.md` |
| REST APIのルートを追加する | `vendor/tmswp/core/rules/rest.md` |
| テンプレートパーツを読み込む | `vendor/tmswp/core/rules/view.md` |
| 独自ブロックを追加する | `vendor/tmswp/core/rules/blocks.md` |
| 公式の拡張を導入する | `vendor/tmswp/core/rules/extensions.md` |
| 本番へデプロイする(FTP) | `vendor/tmswp/core/rules/deploy.md` |
| ACFの生成アクセサを生成・照合する(tmswp/acf-bridge) | `vendor/tmswp/acf-bridge/rules/sync-check.md` |
| 管理画面に設定ページを作る(tmswp/settings-page) | `vendor/tmswp/settings-page/rules/settings-page.md` |
| 署名付きのURLでサムネイルを出す(tmswp/thumbnail) | `vendor/tmswp/thumbnail/rules/thumbnail.md` |
| 日本語サイト向けの機能を使う(tmswp/ja-toolkit) | `vendor/tmswp/ja-toolkit/rules/ja-toolkit.md` |
| テストの基底クラス・テストダブルを使う(tmswp/testing) | `vendor/tmswp/testing/rules/testing.md` |

このテーマは、acf-bridge・settings-page・thumbnail・ja-toolkitを`tmswp-kernel.php`の`extensions`で登録し、testingを`require-dev`で入れている。

## このテーマの例の場所

| 機能 | コードの場所 |
|---|---|
| Kernelの構成(拡張・ブロックカテゴリ・ラベルの言語) | `tmswp-kernel.php` |
| 投稿タイプ(製品)と、使えるブロックの制限(`allowedBlocks()`) | `app/Post/ProductPost.php`、`single-product.php` |
| フォーム(入力・確認・完了)とメール | `app/Form/ContactForm.php`、`app/Page/ContactPage.php`、`app/Mail/`、`page-contact.php` |
| 設定ページ(settings-page)と設定値 | `app/SettingsPage/SiteSettingsPage.php`、`app/Option/SiteOption.php` |
| サムネイル(thumbnail)の`tms_thumb()` | `parts/product-card.php` |
| 日本語対応(ja-toolkit)の`JapaneseSlug`・`KanaTextField` | `tmswp-kernel.php`、`app/Form/ContactForm.php` |
| ACF(acf-bridge)の`AcfMetaStorage`と生成アクセサ | `acf-json/group_product.json`、`app/Acf/`(生成物)、`app/Page/ProductSinglePage.php` |
| 独自ブロック | `blocks/latest-products/`(ソース)、`build/blocks/latest-products/`(ビルドの出力) |
| テンプレートの前処理 | `app/Page/`(`front-page.php`・`single-product.php`・`page-contact.php`の先頭で呼ぶ) |
| ユニットテスト(tmswp/testingの`UnitTestCase`) | `tests/Unit/` |

## プロジェクト構成

```
.
├── style.css               # テーマヘッダ(Text Domain: sample-site)
├── functions.php           # FWのブートだけ。ロジックを書かない
├── tmswp-kernel.php        # Kernelの構成(KernelConfig::theme())を返す。拡張・ログ・ブロックカテゴリはここに書く。WordPressの関数を呼ばない
├── tmswp-discovery.php     # 探索結果ファイル(wp tmswp discoverの生成物。コミットする)
├── index.php ほか          # WordPress標準のテンプレート階層(front-page.php・page-contact.php・single-product.php等)
├── parts/                  # テンプレートパーツ(Template::part()の読み込み先)
├── assets/css/main.css     # テーマのCSS
├── blocks/                 # 独自ブロックのソース(1ブロック1ディレクトリ)
├── build/blocks/           # ブロックのビルドの出力(npm run build。Kernelが登録する。コミットする)
├── acf-json/               # ACFのフィールドグループ(ACFが書き出すJSON。コミットする)
├── app/                    # PHPのクラス(名前空間 SampleSite\、PSR-4)
│   ├── Acf/                # acf-json/から生成したアクセサ(composer acf:syncの生成物)
│   ├── Form/               # フォーム
│   ├── Hook/               # フック
│   ├── Mail/               # フォームの完了処理のメール
│   ├── Option/             # サイト設定値(backed enum)
│   ├── Page/               # テンプレートの前処理クラス
│   ├── Post/               # 投稿タイプ
│   └── SettingsPage/       # 設定ページ
├── dev/                    # wp-envの開発用のファイル(初期データとメールの記録。テーマの実行時には読まない)
├── tests/Unit/             # ユニットテスト(composer test)
├── composer.json
├── package.json            # ブロックのビルド(npm run build)
├── .wp-env.json            # このテーマのwp-env(ポート8882)
├── phpstan.neon
├── phpunit.xml.dist
└── .php-cs-fixer.dist.php
```

## ロジックの置き場(配置決定木)

書こうとしているコードの置き場は、次の順に当てはめて最初に当たったもので決める。

1. **特定のテンプレートの表示前の処理**(そのテンプレートでだけ要る値の用意・整形・フォームの送信の処理・PRGのリダイレクト)
   → `app/Page/<Name>Page.php`の前処理クラス。テンプレートの先頭(`get_header()`より前)で`$vm = <Name>Page::handle();`を1回だけ呼び、返された値(`public readonly`のプロパティ)をテンプレートで読む(page.md。例: `app/Page/ContactPage.php`)。
2. **投稿タイプ・タクソノミー・ユーザーロール**の定義 → `app/Post/`・`app/Tax/`・`app/User/`。
3. **投稿・ターム・ユーザーのメタ** → そのエンティティのクラスの`#[MetaSchema]`付きの定数と、明示のアクセサ(meta.md)。ACFの画面で編集する値は、`#[UseMetaStorage(AcfMetaStorage::class)]`か生成アクセサ(acf.md。例: `app/Post/ProductPost.php`)。
4. **サイト設定値** → `app/Option/`のbacked enum(options.md)。管理画面の入力は`app/SettingsPage/`(settings-page.md)。
5. **フォームの定義** → `app/Form/`(form.md)。完了処理のメールは`app/Mail/`(form-mail.md)。
6. **独自テーブル** → `app/Model/`(model.md)。
7. **独自ブロック** → `blocks/<slug>/`のソースをビルドする(blocks.md)。ブロック専用のフックは`app/Hook/`。
8. **それ以外のWordPressのフックの処理** → `app/Hook/`の`#[AsHook]`クラス(hooks.md)。

## クラス追加の基本手順(全機能共通)

1. `app/`の該当するディレクトリに、1ファイル1クラスで`final class`を作る(PSR-4: `SampleSite\Post\ProductPost` → `app/Post/ProductPost.php`)。
2. 投稿タイプ・タクソノミー・ロール・独自テーブル・フックは、機能に対応するAttribute(`#[AsPostType]`等)を付けてFWの基底クラスを継承し、サイト設定値は`#[AsOptionGroup]`のbacked enumにする(レシピのとおりにする)。フォーム(`FormDefinition`で定義して`Form`を返すクラス)と前処理クラスは、Attributeも基底クラスも無い`final class`で、使う場所から明示的に呼ぶ(form.md・page.md)。
3. **登録のコードは書かない。** Attributeを付けたクラスは、`app/`に置けばKernelが探索して登録する。独自ブロックは、`build/blocks/`の`block.json`から登録される(blocks.md)。
4. `composer discover`(ブロックを変えた場合は`npm run build`)で探索結果ファイルを作り直し、クラスと一緒にコミットする。wp-envのサイトは`WP_DEBUG`が真のため、作り直す前でも反映される。
5. `composer qa`を通す。
6. wp-envのサイト(<http://localhost:8882>)で動作を確かめる(README.mdの「確認の方法」と、各レシピの「確認方法」)。

## 必須コマンド

monorepoのルートで一度`npm ci`を実行してから使う(このテーマのwp-envは、ルートの`node_modules/@wordpress/env`をパスで呼ぶ)。

```bash
composer wp:start   # このテーマのwp-envを起動する(ポート8882。ルートのwp-envの8888とは別)
composer wp:setup   # テーマを有効にし、dev/seed.phpで確認用のデータを作る(何回実行してもよい)
composer qa         # lint → analyse → acf:check --strict → discover:check → test。タスクを終える前に必ず通す
composer lint       # コードスタイルの検査(php-cs-fixerのdry-run)
composer fix        # コードスタイルの自動修正
composer analyse    # PHPStan level max
composer test       # ユニットテスト(tests/Unit/)
composer discover   # 探索結果ファイルを作り直す(wp-envでwp tmswp discover)
composer acf:sync   # acf-json/からapp/Acf/を生成する(vendor/bin/tms-acf sync。WordPressは要らない)
composer acf:check  # acf-json/とapp/Acf/の食い違いを検査する(vendor/bin/tms-acf check)
npm run build       # blocks/をbuild/blocks/にビルドし、続けて探索結果ファイルを作り直す
composer wp:stop    # wp-envを止める
```

- このテーマの`composer test`はユニットテストだけを指す。
- `composer qa`・`composer discover`・`npm run build`はwp-envで`wp tmswp discover`を実行するため、`composer wp:start`と`composer wp:setup`の後に実行する。
- wp-envは`composer wp:*`か、`node ../../node_modules/@wordpress/env/bin/wp-env`とパスで指定して呼ぶ。`npx wp-env`等の、コマンド名`wp-env`を名前解決する呼び出しは使わない(第三者のパッケージやグローバルの同名コマンドを実行しうるため)。

## コーディング規約

- すべてのPHPファイル(テンプレートを含む)に`declare(strict_types=1);`を置く。
- `app/`のクラスは`final`にし、プロパティ・引数・戻り値のすべてに型を宣言する。マジックメソッド(`__call`・`__callStatic`・`__get`・`__set`)と`extract()`・`eval()`は使わない。
- メタのキーは`#[MetaSchema]`付きのクラス定数で、オプションのキーは`#[OptionSchema]`付きのenumのケースで宣言し、キーの文字列リテラルをコードに直接書かない。
- 頻繁に読むメタ・設定の値は、定義のクラスに明示のアクセサを書く。
- テンプレートでは、ファイルの先頭の`use`文でクラスを読み込んでよい。
- 出力は`esc_html()`・`esc_attr()`・`esc_url()`でエスケープする。FWのフォームの描画(`$form->open()`、`$form->field('name')`の`input()`・`label()`・`errors()`・`display()`等のHTMLを返すメソッド)はエスケープ済みなので、そのまま`echo`してよい。`value()`は生の値なので、出力するときはエスケープする。
- 画面の文言はソースを英語にし、テキストドメイン`sample-site`で`__()`等に渡す。FWのドメイン`tmswp`を使わない。
- ブロックの名前は`sample-site/<名前>`の形にし、カテゴリは`tmswp-kernel.php`の`blockCategories`のもの(`sample-site`)かWordPressの既定のものにする。
- コードスタイルは`.php-cs-fixer.dist.php`(TMSWPのmonorepoと同じ規則。対象は`app/`・`dev/`・`tests/`と`functions.php`・`tmswp-kernel.php`)に従う。

## 禁止事項(違反はレビューで差し戻す)

1. **`vendor/`の編集。** `vendor/tmswp/*`はmonorepoの`packages/*`へのシンボリックリンクである。FWを直すときは、このテーマではなくmonorepoのFW本体の作業として行う。
2. **FWのコードのコピーと改変。** `vendor/tmswp/*`のクラスを`app/`へ写して変えない。
3. **WordPress標準のテンプレート階層の迂回。** `template_include`でテンプレートを差し替えない。`exit`でWordPressのテンプレートの読み込みを止めない(フォームのPOSTの処理の後の`wp_safe_redirect()`の直後だけは例外)。
4. **登録系のWordPressの関数の直接の呼び出し。** `register_post_type`・`register_taxonomy`・`register_post_meta`・`add_role`・`register_block_type`を直接呼ばない。
5. **`$wpdb`の直接の利用。** 独自テーブルはModel(model.md)を使う。
6. **functions.phpへのロジックの追加。** functions.phpはFWのブートだけにする。
7. **生成物の手での編集。** `tmswp-discovery.php`(`composer discover`)、`build/blocks/`(`npm run build`)、`app/Acf/`(`composer acf:sync`)は生成物である。手で編集せず、元を変えたら作り直し、元と同じコミットに入れる。
8. **ACF Pro専用の機能の利用。** このテーマはCIと同じwp.orgの無料版のACFで動かすため、repeater・flexible_content・オプションページ等を使わない。

## デバッグ

- **ログ:** `\TMSWP\Core\Log\Log::info('message', ['context' => $value]);`で書く(処理の種類を示すときは`Log::scope('mail')->error(...)`。例: `app/Mail/ContactMailer.php`)。出力先は`tmswp-kernel.php`の`logChannels`で決める。`SimpleLogger`は既定でwp-envのコンテナの`wp-content/tmswp-logs/`に書く。ユニットテストでは、`tests/bootstrap.php`が`WP_CONTENT_DIR`をシステムの一時ディレクトリの下に定義するため、`tmswp-kernel.php`を`KernelConfig::load()`で読んだ構成の`logChannels`を呼べる(例: `tests/Unit/TmswpKernelTest.php`)。この定数はユニットテストだけで定義するため、`app/`のコードから`WP_CONTENT_DIR`を直接読まない。
- **メール:** wp-envでは`dev/mu-plugins/sample-site-mail-capture.php`が`wp_mail()`のメールを送らずにオプション`sample_site_captured_mails`に記録する。`node ../../node_modules/@wordpress/env/bin/wp-env run cli wp option get sample_site_captured_mails --format=json`で読む。
- **クラスを置いたのに有効にならない:** クラス名とファイルのパスのPSR-4の一致、Attributeの付け忘れ、探索結果ファイルが最新か(`composer discover`で作り直す)の順に確かめる。
- **探索結果ファイルとキャッシュ:** FWは探索の結果をtransient・オブジェクトキャッシュに持たず、探索結果ファイルだけを使う。そのため、消すべきFWのキャッシュは無く、反映されないときは探索結果ファイルを作り直す。
- **起動しない:** `composer.json`のpathリポジトリの`versions`(`tmswp/core`)が`Kernel::VERSION`と食い違うと、Kernelが起動時の版の照合でエラーにする(README.mdの「lockとpathリポジトリ」)。

## 完了条件

実装の後は`composer qa`を通し、wp-envのサイトで表示・保存・CLIの実行を確かめる(README.mdの「確認の方法」)。
