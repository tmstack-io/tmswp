# このプラグインの開発規約(TMSWP)

このプロジェクトはTMStack WordPress Framework(TMSWP)を使うWordPressプラグインである。
あなた(AIエージェント)はこのファイルの規約に従って実装する。
フレームワーク本体(`vendor/`)はここでは開発しない。

## 最初に読むもの

タスクに応じて、下表の該当レシピを必ず読んでから実装を始める。
レシピはフレームワークの各パッケージに同梱されており、このプロジェクトに入っている版と一致する。

このプラグインの名前空間は`TmswpStarterPlugin\`である(composer.jsonの`autoload`の`psr-4`で`app/`に割り当てたもの。create-projectのときにスラッグから作った)。
レシピのコード例の名前空間`App\`は、`TmswpStarterPlugin\`に読み替える。

| やりたいこと | 読むファイル |
|---|---|
| カスタム投稿タイプを追加する | `vendor/tmswp/core/rules/post-type.md` |
| カスタムタクソノミーを追加する | `vendor/tmswp/core/rules/taxonomy.md` |
| ユーザーロールを追加する | `vendor/tmswp/core/rules/user-role.md` |
| 投稿・ターム・ユーザーにカスタムフィールド(非ACF)を持たせる | `vendor/tmswp/core/rules/meta.md` |
| ACFでフィールドを管理する(acf-json) | `vendor/tmswp/core/rules/acf.md` |
| フォーム(問い合わせ・申し込み等)を実装する | `vendor/tmswp/core/rules/form.md` |
| フォームの完了処理でメールを送る | `vendor/tmswp/core/rules/form-mail.md` |
| 独自DBテーブルを作って読み書きする | `vendor/tmswp/core/rules/model.md` |
| サイト設定値(オプション)を定義する | `vendor/tmswp/core/rules/options.md` |
| add_action・add_filterをまとめて登録する | `vendor/tmswp/core/rules/hooks.md` |
| REST APIのルートを追加する | `vendor/tmswp/core/rules/rest.md` |
| テンプレートパーツを読み込む | `vendor/tmswp/core/rules/view.md` |
| 独自ブロックを追加する | `vendor/tmswp/core/rules/blocks.md` |
| 公式の拡張(acf-bridge・settings-page・thumbnail・ja-toolkit・testing)を導入する | `vendor/tmswp/core/rules/extensions.md` |
| 本番へデプロイする(FTP) | `vendor/tmswp/core/rules/deploy.md` |
| ACFの生成アクセサを生成・照合する(tmswp/acf-bridge) | `vendor/tmswp/acf-bridge/rules/sync-check.md` |
| 管理画面に設定ページを作る(tmswp/settings-page) | `vendor/tmswp/settings-page/rules/settings-page.md` |
| 署名付きのURLでサムネイルを出す(tmswp/thumbnail) | `vendor/tmswp/thumbnail/rules/thumbnail.md` |
| 日本語サイト向けの機能を使う(tmswp/ja-toolkit) | `vendor/tmswp/ja-toolkit/rules/ja-toolkit.md` |
| テストの基底クラス・テストダブルを使う(tmswp/testing。スターターには入っていないため、先に`composer require --dev tmswp/testing`で入れる) | `vendor/tmswp/testing/rules/testing.md` |

拡張のレシピ(`vendor/tmswp/core/`以外)は、その拡張を`composer require`で入れると`vendor/`に入る。入っていなければ、先に`vendor/tmswp/core/rules/extensions.md`の手順で拡張を入れる。

プラグインはトップレベルのテンプレートを持たないため、テンプレートの前処理クラス(`vendor/tmswp/core/rules/page.md`)は使わない。

## プロジェクト構成

```
.
├── {{project_slug}}.php     # プラグインの本体ファイル(プラグインヘッダとFWのブートだけ。ロジックを書かない)
├── tmswp-kernel.php        # Kernelの構成(KernelConfig::plugin())を返す。拡張・ログ・ブロックカテゴリはここに書く。WordPressの関数を呼ばない
├── tmswp-discovery.php     # 探索結果ファイル(wp tmswp discoverの生成物。コミットする)
├── templates/              # プラグイン同梱のテンプレート(Template::part()の読み込み先。テーマに同じ名前のファイルがあればテーマが優先される)
├── languages/              # このプラグインの翻訳ファイル(<テキストドメイン>-<locale>.mo・.l10n.php。例: {{project_slug}}-ja.mo)
├── app/                    # PHPのクラス(名前空間 TmswpStarterPlugin\、PSR-4)
│   ├── Hook/               # フック(*Hook。#[AsHook])
│   ├── Post/               # 投稿タイプ(*Post。#[AsPostType])
│   ├── Tax/                # タクソノミー(*Tax。#[AsTaxonomy])
│   ├── User/               # ユーザーロール(*Role。#[AsUserRole])
│   ├── Form/               # フォーム(*Form)
│   ├── Model/              # 独自テーブル(*Model。#[AsModel])
│   ├── Option/             # サイト設定値(*Option。#[AsOptionGroup]のbacked enum)
│   └── SettingsPage/       # 設定ページ(tmswp/settings-pageを入れたとき)
├── tests/Unit/             # ユニットテスト(composer test)
├── composer.json
├── phpstan.neon
├── phpunit.xml.dist
└── .php-cs-fixer.dist.php
```

`languages/`の翻訳ファイルは、WordPressが本体ファイルのヘッダの`Text Domain`と`Domain Path: /languages`から自動で読み込む(読み込みのコードは要らない。`Domain Path`を消すと`languages/`は探されない)。名前は`<テキストドメイン>-<locale>.mo`・`<テキストドメイン>-<locale>.l10n.php`(例: `{{project_slug}}-ja.mo`)にする。`WP_LANG_DIR`(`wp-content/languages/plugins/`)に同じ名前の翻訳ファイルがあれば、そちらが優先される。
`app/`の下には、レシピが示すほかの置き場(メールの`Mail/`等)も作ってよい。PSR-4のとおり、クラス名とファイルのパスを一致させる。
プラグインのスターターはブロックのビルドの設定(`blocks/`・`package.json`)を持たない。ブロックを置くときは、blocks.mdの手順で足す。

## ロジックの置き場(配置決定木)

書こうとしているコードの置き場は、次の順に当てはめて最初に当たったもので決める。

1. **投稿タイプ・タクソノミー・ユーザーロール**の定義 → `app/Post/`・`app/Tax/`・`app/User/`(post-type.md・taxonomy.md・user-role.md)。
2. **投稿・ターム・ユーザーのメタ** → そのエンティティのクラスの`#[MetaSchema]`付きの定数と、明示のアクセサ(meta.md。ACFの画面で編集する値はacf.md)。
3. **サイト設定値** → `app/Option/`のbacked enum(options.md)。
4. **フォームの定義** → `app/Form/`(form.md)。完了処理のメールは`app/Mail/`(form-mail.md)。
5. **独自テーブル** → `app/Model/`(model.md)。
6. **独自ブロック** → `blocks/<slug>/`のソースをビルドする(blocks.md)。ブロック専用のフックは`app/Hook/`。
7. **それ以外のWordPressのフックの処理**(ショートコード、テンプレートの外で要る前処理を含む) → `app/Hook/`の`#[AsHook]`クラス(hooks.md。ショートコードのフォームの駆動はform.md)。

## クラス追加の基本手順(全機能共通)

1. `app/`の該当するディレクトリに、1ファイル1クラスで`final class`を作る(PSR-4: `TmswpStarterPlugin\Post\NewsPost` → `app/Post/NewsPost.php`)。
2. 投稿タイプ・タクソノミー・ロール・独自テーブル・フックは、機能に対応するAttribute(`#[AsPostType]`等)を付けてFWの基底クラスを継承し、サイト設定値は`#[AsOptionGroup]`のbacked enumにする(レシピのとおりにする)。フォーム(`FormDefinition`で定義して`Form`を返すクラス)は、Attributeも基底クラスも無い`final class`で、使う場所(フックのクラス等)から明示的に呼ぶ(form.md)。
3. **登録のコードは書かない。** Attributeを付けたクラスは、`app/`に置けばKernelが探索して登録する(本体ファイルへの追記は要らない)。独自ブロックは、`build/blocks/`の`block.json`から登録される(blocks.md)。
4. `composer discover`で探索結果ファイル(プラグインのルートの`tmswp-discovery.php`)を作り直し、クラスと一緒にコミットする。開発環境(Kernelのdebugが真。省略すると`WP_DEBUG`の値)は起動のたびに`app/`と`build/blocks/`を走査するため、作り直す前でも反映される。本番は探索結果ファイルだけを読む。
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
```

- このプロジェクトの`composer test`はユニットテストだけを指す(統合テストは既定で持たない)。
- スターターのユニットテストはBrain Monkey(`brain/monkey`)だけで書く構成で、`tmswp/testing`は入っていない。Kernelをbootするテスト(メタ・オプションのメモリのストレージ等を使うもの)を書くときは、`composer require --dev tmswp/testing`で入れ、`vendor/tmswp/testing/rules/testing.md`を読む。
- ユニットテストは、`app/`のディレクトリの構成をミラーして`tests/Unit/`に置く(`app/Hook/PluginSetupHook.php`のテストは`tests/Unit/Hook/PluginSetupHookTest.php`で、名前空間は`TmswpStarterPlugin\Tests\Unit\Hook\`)。例の`PluginSetupHookTest`は、処理を持たない雛形の`PluginSetupHook`に対する出発点で、Brain Monkeyの準備の形を示す。`PHPUnit\Framework\TestCase`を継承して`MockeryPHPUnitIntegration`を使い(Brain Monkeyの期待をアサーションとして数える)、`setUp()`で`Brain\Monkey\setUp()`を、`tearDown()`で`Brain\Monkey\tearDown()`を呼ぶ。フックに処理を足したら、テストもそのメソッドを直接呼び、WordPressの関数の呼び出しを`Brain\Monkey\Functions\expect()`で、戻り値を`assertSame()`等で確かめる形に書き換える。
- `phpunit.xml.dist`は、テストが1件も無いとき(`failOnEmptyTestSuite`)と、risky・警告・非推奨・Noticeを出したテストやテスト中の出力があるときに、`composer test`を失敗にする。
- `composer qa`は探索結果ファイルの照合(`wp tmswp discover --check`)を含む。`composer discover`とともに、WP-CLIの`wp`がこのプラグインを有効にしたサイトに届く場所で実行する。

## コーディング規約

- すべてのPHPファイル(テンプレートを含む)に`declare(strict_types=1);`を置く。
- `app/`のクラスは`final`にし、プロパティ・引数・戻り値のすべてに型を宣言する。マジックメソッド(`__call`・`__callStatic`・`__get`・`__set`)と`extract()`・`eval()`は使わない。
- メタのキーは`#[MetaSchema]`付きのクラス定数で、オプションのキーは`#[OptionSchema]`付きのenumのケースで宣言し、キーの文字列リテラルをコードに直接書かない。
- 頻繁に読むメタ・設定の値は、定義のクラスに明示のアクセサ(例: `ProductPost::price($post): int`)を書く。
- テンプレートでは、ファイルの先頭の`use`文でクラスを読み込んでよい。
- 出力は`esc_html()`・`esc_attr()`・`esc_url()`でエスケープする。FWのフォームの描画(`$form->open()`、`$form->field('name')`の`input()`・`label()`・`errors()`・`display()`等のHTMLを返すメソッド)はエスケープ済みなので、そのまま`echo`してよい。`value()`は生の値なので、出力するときはエスケープする。
- テキストドメインはこのプラグインのもの(`{{project_slug}}`。本体ファイルのヘッダの`Text Domain`)を使い、FWのドメイン`tmswp`を使わない。
- コードスタイルは`.php-cs-fixer.dist.php`(TMSWPのmonorepoと同じ規則)に従う。`templates/`のテンプレートはlintの対象外だが、`declare(strict_types=1);`とエスケープの規約は守る。

## 禁止事項(違反はレビューで差し戻す)

1. **`vendor/`の編集。** FWの挙動を変えたいときは、フック・`tmswp-kernel.php`の構成・基底クラスの継承で行う。それでもできなければ、FW本体への課題として報告する。
2. **FWのコードのコピーと改変。** `vendor/tmswp/*`のクラスを`app/`へ写して変えない。
3. **WordPress標準のテンプレート階層の迂回。** プラグインでも`template_include`でテンプレートを差し替えない。`exit`でWordPressのテンプレートの読み込みを止めない(フォームのPOSTの処理の後の`wp_safe_redirect()`の直後だけは例外)。
4. **登録系のWordPressの関数の直接の呼び出し。** `register_post_type`・`register_taxonomy`・`register_post_meta`・`add_role`・`register_block_type`を`app/`や本体ファイルで直接呼ばない。FWの定義のクラスを使う(該当のレシピ)。
5. **独自のマイグレーション。** 独自テーブルの定義は`wp tmswp migrate`で適用する(プラグインの有効化では適用しない)。マイグレーションのために`register_activation_hook`を直接書かない(model.md)。
6. **`$wpdb`の直接の利用。** 独自テーブルはModel(model.md)を使う。WordPress標準のテーブルは`WP_Query`等の標準のAPIで読む。
7. **本体ファイルへのロジックの追加。** 本体ファイルはプラグインヘッダとFWのブートだけにする。フックは`app/Hook/`(hooks.md)へ書く。
8. **生成物の手での編集。** `tmswp-discovery.php`は`wp tmswp discover`の生成物である。手で編集せず、`app/`を変えたら作り直してコミットする。本番は探索結果ファイルが無いと起動時にエラーになり、古いと追加・変更したクラスが反映されない。

## デバッグ

- **開発環境は`WP_DEBUG`を有効にする。** Kernelのdebug(`tmswp-kernel.php`の`KernelConfig`の`debug`。省略すると`WP_DEBUG`の値)が真なら、設定の誤りと実行時のデータの取得の失敗が例外になり、起動のたびに`app/`と`build/blocks/`を走査する。debugが偽(本番)では、実行時の失敗をKernelのロガーに`error`で記録して既定値で続ける。
- **ログ:** `\TMSWP\Core\Log\Log::info('message', ['context' => $value]);`で書く(処理の種類を示すときは`Log::scope('mail')->error(...)`)。どのチャンネル(出力先)に書くかは、`tmswp-kernel.php`の`logChannels`の各`LogChannel`の`levels`・`scopes`で決める。宣言したチャンネルへ直接書くときは`Log::channel('<名前>')`を使う(宣言していない名前は例外になる)。スターターの`SimpleLogger`は既定で`wp-content/tmswp-logs/`に書く。`var_dump`・`print_r`をコミットに残さない。
- **クラスを置いたのに有効にならない:** 次の順に確かめる。
  1. クラス名とファイルのパスがPSR-4で一致しているか(`app/Post/NewsPost.php`なら`TmswpStarterPlugin\Post\NewsPost`)。
  2. Attribute(`#[AsPostType]`等)を付け忘れていないか。
  3. `wp tmswp discover --check`で探索結果ファイルが最新か。古ければ`composer discover`で作り直す。
- **探索結果ファイルとキャッシュ:** FWは探索の結果をtransient・オブジェクトキャッシュに持たず、探索結果ファイルだけを使う。そのため、消すべきFWのキャッシュは無く、反映されないときは探索結果ファイルを作り直す。`wp tmswp discover`は、そのサイトで起動したすべてのKernel(このプラグインと、TMSWPを使うテーマ・ほかのプラグイン)の探索結果ファイルを作り直す。本番へ上げた探索結果ファイルが反映されない場合のOPcacheとページキャッシュの扱いは、`vendor/tmswp/core/rules/deploy.md`にある。
- **起動時のエラー:** 同じサイトのTMSWPの版が食い違う場合(テーマ・ほかのプラグインが別の版の`tmswp/core`を入れている等)と、他のプラグインが互換の無い`psr/log`・`psr/container`を先に読み込んだ場合は、Kernelが起動時に明示のエラーにする。メッセージに従って版をそろえる(そろえ方は`vendor/tmswp/core/rules/deploy.md`)。同じサイトの別のプラグインと名前空間が同じ場合も、Kernelが起動時のエラーにする。

## 完了条件

実装の後は`composer qa`を通し、WordPress上で有効化・保存・CLIの実行を確かめる(各レシピの「確認方法」)。
