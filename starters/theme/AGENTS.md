# このテーマの開発規約(TMSWP)

このプロジェクトはTMStack WordPress Framework(TMSWP)を使うWordPressテーマである。
あなた(AIエージェント)はこのファイルの規約に従って実装する。
フレームワーク本体(`vendor/`)はここでは開発しない。

## 最初に読むもの

タスクに応じて、下表の該当レシピを必ず読んでから実装を始める。
レシピはフレームワークの各パッケージに同梱されており、このプロジェクトに入っている版と一致する。

このテーマの名前空間は`TmswpStarterTheme\`である(composer.jsonの`autoload`の`psr-4`で`app/`に割り当てたもの。create-projectのときにスラッグから作った)。
レシピのコード例の名前空間`App\`は、`TmswpStarterTheme\`に読み替える。

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
| テンプレートパーツを読み込む | `vendor/tmswp/core/rules/view.md` |
| 独自ブロックを追加する | `vendor/tmswp/core/rules/blocks.md` |
| 公式の拡張(acf-bridge・settings-page・thumbnail・ja-toolkit・testing)を導入する | `vendor/tmswp/core/rules/extensions.md` |
| ACFの生成アクセサを生成・照合する(tmswp/acf-bridge) | `vendor/tmswp/acf-bridge/rules/sync-check.md` |
| 管理画面に設定ページを作る(tmswp/settings-page) | `vendor/tmswp/settings-page/rules/settings-page.md` |
| 署名付きのURLでサムネイルを出す(tmswp/thumbnail) | `vendor/tmswp/thumbnail/rules/thumbnail.md` |
| 日本語サイト向けの機能を使う(tmswp/ja-toolkit) | `vendor/tmswp/ja-toolkit/rules/ja-toolkit.md` |
| テストの基底クラス・テストダブルを使う(tmswp/testing。スターターには入っていないため、先に`composer require --dev tmswp/testing`で入れる) | `vendor/tmswp/testing/rules/testing.md` |

拡張のレシピ(`vendor/tmswp/core/`以外)は、その拡張を`composer require`で入れると`vendor/`に入る。入っていなければ、先に`vendor/tmswp/core/rules/extensions.md`の手順で拡張を入れる。

## プロジェクト構成

```
.
├── style.css               # テーマヘッダ(Theme Name・Text Domain等)
├── functions.php           # FWのブートだけ。ロジックを書かない
├── tmswp-kernel.php        # Kernelの構成(KernelConfig::theme())を返す。拡張・ログ・ブロックカテゴリはここに書く。WordPressの関数を呼ばない
├── tmswp-discovery.php     # 探索結果ファイル(wp tmswp discoverの生成物。コミットする)
├── index.php ほか          # WordPress標準のテンプレート階層(header.php・footer.php・page-{slug}.php等。通常のテーマと同じ)
├── parts/                  # テンプレートパーツ(Template::part()・get_template_part()の読み込み先)
├── assets/css/main.css     # app/Hook/ThemeSetupHook.phpが読み込むCSS
├── blocks/                 # 独自ブロックのソース(1ブロック1ディレクトリ。blocks.md)
├── build/blocks/           # ブロックのビルドの出力(npm run build。Kernelが登録する。コミットする)
├── languages/              # このテーマの翻訳ファイル(<locale>.mo・<locale>.l10n.php。例: ja.mo)
├── app/                    # PHPのクラス(名前空間 TmswpStarterTheme\、PSR-4)
│   ├── Hook/               # フック(*Hook。#[AsHook])
│   ├── Post/               # 投稿タイプ(*Post。#[AsPostType])
│   ├── Tax/                # タクソノミー(*Tax。#[AsTaxonomy])
│   ├── User/               # ユーザーロール(*Role。#[AsUserRole])
│   ├── Form/               # フォーム(*Form)
│   ├── Model/              # 独自テーブル(*Model。#[AsModel])
│   ├── Option/             # サイト設定値(*Option。#[AsOptionGroup]のbacked enum)
│   └── Page/               # テンプレートの前処理クラス(*Page。使うときに作る)
├── tests/Unit/             # ユニットテスト(composer test)
├── composer.json
├── package.json            # ブロックのビルド(npm run build)
├── phpstan.neon
├── phpunit.xml.dist
└── .php-cs-fixer.dist.php
```

`languages/`の翻訳ファイルは、WordPressがテーマのヘッダの`Text Domain`から自動で読み込む(読み込みのコードは要らない)。名前は`<locale>.mo`・`<locale>.l10n.php`(例: `ja.mo`)にする(テキストドメインを付けた名前では読まれない)。`WP_LANG_DIR`(`wp-content/languages/themes/`)に同じテキストドメインの翻訳ファイル(`<テキストドメイン>-<locale>.mo`)があれば、そちらが優先される。
`app/`の下には、レシピが示すほかの置き場(メールの`Mail/`等)も作ってよい。PSR-4のとおり、クラス名とファイルのパスを一致させる。

## ロジックの置き場(配置決定木)

書こうとしているコードの置き場は、次の順に当てはめて最初に当たったもので決める。

1. **特定のテンプレートの表示前の処理**(そのテンプレートでだけ要る値の用意・整形・フォームの送信の処理・PRGのリダイレクト)
   → `app/Page/<Name>Page.php`の前処理クラス。テンプレートの先頭(`get_header()`より前)で`$vm = <Name>Page::handle();`を1回だけ呼び、返された値(`public readonly`のプロパティ)をテンプレートで読む(page.md)。
2. **投稿タイプ・タクソノミー・ユーザーロール**の定義 → `app/Post/`・`app/Tax/`・`app/User/`(post-type.md・taxonomy.md・user-role.md)。
3. **投稿・ターム・ユーザーのメタ** → そのエンティティのクラスの`#[MetaSchema]`付きの定数と、明示のアクセサ(meta.md。ACFの画面で編集する値はacf.md)。
4. **サイト設定値** → `app/Option/`のbacked enum(options.md)。
5. **フォームの定義** → `app/Form/`(form.md)。完了処理のメールは`app/Mail/`(form-mail.md)。
6. **独自テーブル** → `app/Model/`(model.md)。
7. **独自ブロック** → `blocks/<slug>/`のソースをビルドする(blocks.md)。ブロック専用のフックは`app/Hook/`。
8. **それ以外のWordPressのフックの処理**(リクエストの種類を問わず動くもの) → `app/Hook/`の`#[AsHook]`クラス(hooks.md)。

前処理クラスはAttributeも継承も要らない`final class`で、テンプレートが使う値を型付きのプロパティで返す。出力(`echo`)はしない。全テンプレートに共通の前処理は持たない。共通の処理は`app/Hook/`に置くか、パーツの中でエンティティのアクセサを直接読む。

## クラス追加の基本手順(全機能共通)

1. `app/`の該当するディレクトリに、1ファイル1クラスで`final class`を作る(PSR-4: `TmswpStarterTheme\Post\NewsPost` → `app/Post/NewsPost.php`)。
2. 投稿タイプ・タクソノミー・ロール・独自テーブル・フックは、機能に対応するAttribute(`#[AsPostType]`等)を付けてFWの基底クラスを継承し、サイト設定値は`#[AsOptionGroup]`のbacked enumにする(レシピのとおりにする)。フォーム(`FormDefinition`で定義して`Form`を返すクラス)と前処理クラスは、Attributeも基底クラスも無い`final class`で、使う場所から明示的に呼ぶ(form.md・page.md)。
3. **登録のコードは書かない。** Attributeを付けたクラスは、`app/`に置けばKernelが探索して登録する(functions.phpへの追記は要らない)。独自ブロックは、`build/blocks/`の`block.json`から登録される(blocks.md)。
4. `composer discover`(ブロックを変えた場合は`npm run build`)で探索結果ファイル(テーマのルートの`tmswp-discovery.php`)を作り直し、クラスと一緒にコミットする。開発環境(Kernelのdebugが真。省略すると`WP_DEBUG`の値)は起動のたびに`app/`と`build/blocks/`を走査するため、作り直す前でも反映される。本番は探索結果ファイルだけを読む。
5. `composer qa`を通す。
6. WordPress上で動作を確かめる(各レシピの「確認方法」)。

## 必須コマンド

```bash
composer qa         # lint → analyse → discover:check → test。タスクを終える前に必ず通す
composer lint       # コードスタイルの検査(php-cs-fixerのdry-run)
composer fix        # コードスタイルの自動修正
composer analyse    # PHPStan level max(app/が対象)
composer test       # ユニットテスト(tests/Unit/)
composer discover   # 探索結果ファイルを作り直す(wp tmswp discover)
npm run build       # blocks/をbuild/blocks/にビルドし、続けて探索結果ファイルを作り直す
```

- このプロジェクトの`composer test`はユニットテストだけを指す(統合テストは既定で持たない)。
- スターターのユニットテストはBrain Monkey(`brain/monkey`)だけで書く構成で、`tmswp/testing`は入っていない。Kernelをbootするテスト(メタ・オプションのメモリのストレージ等を使うもの)を書くときは、`composer require --dev tmswp/testing`で入れ、`vendor/tmswp/testing/rules/testing.md`を読む。
- `composer qa`は探索結果ファイルの照合(`wp tmswp discover --check`)を含む。`composer discover`・`npm run build`とともに、WP-CLIの`wp`がこのテーマを有効にしたサイトに届く場所で実行する。
- `npm run build`の前に一度`npm ci`でビルドの道具を入れる。

## コーディング規約

- すべてのPHPファイル(テンプレートを含む)に`declare(strict_types=1);`を置く。
- `app/`のクラスは`final`にし、プロパティ・引数・戻り値のすべてに型を宣言する。マジックメソッド(`__call`・`__callStatic`・`__get`・`__set`)と`extract()`・`eval()`は使わない。
- メタのキーは`#[MetaSchema]`付きのクラス定数で、オプションのキーは`#[OptionSchema]`付きのenumのケースで宣言し、キーの文字列リテラルをコードに直接書かない。
- 頻繁に読むメタ・設定の値は、定義のクラスに明示のアクセサ(例: `ProductPost::price($post): int`)を書く。
- テンプレートでは、ファイルの先頭の`use`文でクラスを読み込んでよい。
- 出力は`esc_html()`・`esc_attr()`・`esc_url()`でエスケープする。FWのフォームの描画(`$form->open()`、`$form->field('name')`の`input()`・`label()`・`errors()`・`display()`等のHTMLを返すメソッド)はエスケープ済みなので、そのまま`echo`してよい。`value()`は生の値なので、出力するときはエスケープする。
- テキストドメインはこのテーマのもの(`{{project_slug}}`。style.cssの`Text Domain`)を使い、FWのドメイン`tmswp`を使わない。
- ブロックの名前は`tmswp-starter/<名前>`の形にする(`blocks/example/block.json`の`name`と同じ名前空間)。
- コードスタイルは`.php-cs-fixer.dist.php`(TMSWPのmonorepoと同じ規則)に従う。テンプレート(テンプレート階層のファイル・`parts/`・ブロックの`render.php`)はlintの対象外だが、`declare(strict_types=1);`とエスケープの規約は守る。

## 禁止事項(違反はレビューで差し戻す)

1. **`vendor/`の編集。** FWの挙動を変えたいときは、フック・`tmswp-kernel.php`の構成・基底クラスの継承で行う。それでもできなければ、FW本体への課題として報告する。
2. **FWのコードのコピーと改変。** `vendor/tmswp/*`のクラスを`app/`へ写して変えない。
3. **WordPress標準のテンプレート階層の迂回。** `template_include`でテンプレートを差し替えない。`exit`でWordPressのテンプレートの読み込みを止めない(フォームのPOSTの処理の後の`wp_safe_redirect()`の直後だけは例外)。ページの出し分けは、テンプレート階層(`page-{slug}.php`等)と条件分岐タグで行う。
4. **登録系のWordPressの関数の直接の呼び出し。** `register_post_type`・`register_taxonomy`・`register_post_meta`・`add_role`・`register_block_type`を`app/`やfunctions.phpで直接呼ばない。FWの定義のクラスを使う(該当のレシピ)。
5. **`$wpdb`の直接の利用。** 独自テーブルはModel(model.md)を使う。WordPress標準のテーブルは`WP_Query`等の標準のAPIで読む。
6. **functions.phpへのロジックの追加。** functions.phpはFWのブートだけにする。フックは`app/Hook/`(hooks.md)へ書く。
7. **生成物の手での編集。** `tmswp-discovery.php`は`wp tmswp discover`の、`build/blocks/`は`npm run build`の生成物である。手で編集せず、`app/`・`blocks/`を変えたら作り直してコミットする。本番は探索結果ファイルが無いと起動時にエラーになり、古いと追加・変更したクラスとブロックが反映されない。

## デバッグ

- **開発環境は`WP_DEBUG`を有効にする。** Kernelのdebug(`tmswp-kernel.php`の`KernelConfig`の`debug`。省略すると`WP_DEBUG`の値)が真なら、設定の誤りと実行時のデータの取得の失敗が例外になり、起動のたびに`app/`と`build/blocks/`を走査する。debugが偽(本番)では、実行時の失敗をKernelのロガーに`error`で記録して既定値で続ける。
- **ログ:** `\TMSWP\Core\Log\Log::info('message', ['context' => $value]);`で書く(処理の種類を示すときは`Log::scope('mail')->error(...)`)。どのチャンネル(出力先)に書くかは、`tmswp-kernel.php`の`logChannels`の各`LogChannel`の`levels`・`scopes`で決める。宣言したチャンネルへ直接書くときは`Log::channel('<名前>')`を使う(宣言していない名前は例外になる)。スターターの`SimpleLogger`は既定で`wp-content/tmswp-logs/`に書く。`var_dump`・`print_r`をコミットに残さない。
- **クラスを置いたのに有効にならない:** 次の順に確かめる。
  1. クラス名とファイルのパスがPSR-4で一致しているか(`app/Post/NewsPost.php`なら`TmswpStarterTheme\Post\NewsPost`)。
  2. Attribute(`#[AsPostType]`等)を付け忘れていないか。
  3. `wp tmswp discover --check`で探索結果ファイルが最新か。古ければ`composer discover`で作り直す。
- **探索結果ファイルとキャッシュ:** FWは探索の結果をtransient・オブジェクトキャッシュに持たず、探索結果ファイルだけを使う。そのため、消すべきFWのキャッシュは無く、反映されないときは探索結果ファイルを作り直す。`wp tmswp discover`は、そのサイトで起動したすべてのKernel(このテーマと、TMSWPを使うプラグイン)の探索結果ファイルを作り直し、書いたファイルのOPcacheを無効にする。FTPで上げた探索結果ファイルが本番で反映されない場合は、サーバーのOPcacheが更新を検知しない設定(`opcache.validate_timestamps`が0等)になっていないかを確かめ、OPcacheを空にする。ページキャッシュのプラグインを使うサイトでは、デプロイの後にページキャッシュも消す。
- **起動時のエラー:** 同じサイトのTMSWPの版が食い違う場合(別のテーマ・プラグインが別の版の`tmswp/core`を入れている等)と、他のプラグインが互換の無い`psr/log`・`psr/container`を先に読み込んだ場合は、Kernelが起動時に明示のエラーにする。メッセージに従って版をそろえる。

## 完了条件

実装の後は`composer qa`を通し、WordPress上で表示・保存・CLIの実行を確かめる(各レシピの「確認方法」)。
