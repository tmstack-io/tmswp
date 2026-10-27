# このテーマの開発規約(TMSWP)

このプロジェクトは TMStack WordPress Framework を使うWordPressテーマである。
あなた(AIエージェント)はこのファイルの規約に従って実装する。
フレームワーク本体(`vendor/`)はここでは開発しない。

## 最初に読むもの

タスクに応じて、該当レシピを必ず読んでから実装を始める。

| やりたいこと | 読むファイル |
|---|---|
| カスタム投稿タイプを追加する | `vendor/tmswp/core/rules/post-type.md` |
| カスタムタクソノミーを追加する | `vendor/tmswp/core/rules/taxonomy.md` |
| ユーザーロールを追加する | `vendor/tmswp/core/rules/user-role.md` |
| 投稿/ターム/ユーザーにカスタムフィールドを持たせる | `vendor/tmswp/core/rules/meta.md` |
| ACFでフィールドを管理する | `vendor/tmswp/core/rules/acf.md` |
| フォームを実装する | `vendor/tmswp/core/rules/form.md` |
| フォームの完了処理でメールを送る | `vendor/tmswp/core/rules/form-mail.md` |
| テンプレートの前処理(値の用意・フォームの駆動)を書く | `vendor/tmswp/core/rules/page.md` |
| 独自DBテーブルを作る | `vendor/tmswp/core/rules/model.md` |
| サイト設定値を定義する | `vendor/tmswp/core/rules/options.md` |
| add_action/add_filter をまとめる | `vendor/tmswp/core/rules/hooks.md` |
| テンプレートパーツを扱う | `vendor/tmswp/core/rules/view.md` |
| 独自ブロックを追加する | `vendor/tmswp/core/rules/blocks.md` |
| 公式拡張を導入する | `vendor/tmswp/core/rules/extensions.md` |

## 構成

- `functions.php`: FWブートのみ(`Kernel::boot(KernelConfig::load(__DIR__ . '/tmswp-kernel.php'))`)。ロジックを書かない。
- `tmswp-kernel.php`: Kernelの構成(`KernelConfig::theme()`)を返す。拡張(`extensions`)はここに書く。WordPressを起動しない`tms-acf`(tmswp/acf-bridge)も読むため、WordPressの関数を呼ばない。
- `index.php` など: WordPress標準テンプレート階層。
- `parts/`: テンプレートパーツ。
- `app/`: PHPクラス。名前空間は`SampleSite\`。テンプレートの前処理クラスは`app/Page/`に置き、テンプレートの先頭で呼ぶ。
- `blocks/`:独自ブロックのソース。`npm run build`で`build/blocks/`にビルドする。
- `build/blocks/`:ブロックのビルドの出力。Kernelが登録する。コミットする。
- `tmswp-discovery.php`:探索結果ファイル。`app/`と`build/blocks/`を変えたら`composer discover`(このテーマのwp-envで`wp tmswp discover`を実行する)で作り直し、コミットする。
- `acf-json/`・`app/Acf/`: ACFのフィールドグループと、`composer acf:sync`(`vendor/bin/tms-acf sync`)の生成物。両方を同じコミットに入れる。
- `dev/`: wp-envの開発用のファイル(サイトの初期データとメールの記録)。テーマの実行時には読まない。

レシピのコード例の名前空間`App\`は、このテーマでは`SampleSite\`に読み替える。

## 必須コマンド

```bash
composer qa
composer analyse
composer fix
composer acf:sync
composer discover
composer test
npm run build
```

このテーマでは`composer test`はユニットテストのみを指す。
`composer qa`は探索結果ファイルの照合(`wp tmswp discover --check`)を含むため、このテーマのwp-env(`composer wp:start`)を起動してから実行する。
`npm run build`はブロックをビルドし、続けて探索結果ファイルを作り直す。

## 禁止事項

- `vendor/` 配下を編集しない。
- `functions.php` にロジックを足さない。
- `tmswp-discovery.php`と`build/blocks/`と`app/Acf/`を手で編集しない。
- `register_post_type` / `register_taxonomy` / `register_post_meta` / `add_role` を直接呼ばない。
- `$wpdb` を直接使わない。独自テーブルは `Model` を使う。
- `template_include` でテンプレート階層を迂回しない。
- `__call` / `__callStatic` / `extract()` を使わない。
- 出力は必ず `esc_html()` / `esc_attr()` / `esc_url()` でエスケープする。

## 完了条件

実装後は `composer qa` を通し、必要に応じてWordPress上で表示・保存・CLI実行を確認する。
