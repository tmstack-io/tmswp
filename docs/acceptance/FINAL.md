# FINAL acceptance

## 実装内容

- `examples/sample-site` を `tmswp/starter-theme` から作成し、全公式パッケージをローカル path repository で導入した。
  - `tmswp/core`
  - `tmswp/acf-bridge`
  - `tmswp/csv-import`
  - `tmswp/ja-toolkit`
  - `tmswp/settings-page`
  - `tmswp/thumbnail`
- sample-site に以下の代表実装を追加した。
  - `ProductPost`: CPT / `#[MetaSchema]` / 価格・SKUアクセサ
  - `ProductCategoryTax`: カスタム分類
  - `InquiryModel`: 独自テーブル
  - `ContactForm`: 入力→確認→完了フォーム、ファイルアップロード、問い合わせ保存
  - `SiteOption`: `#[AsOptionGroup]` backed enum
  - `SiteSettingsPage`: `tmswp/settings-page`
  - `ProductImporter`: `#[AsImporter('products')]`
  - `acf-json/`: ACF生成fixture
  - `front-page.php` / `archive-product.php` / `single-product.php` / `taxonomy-product_category.php` / `page-contact.php`
- `functions.php` で `Kernel::boot()` 直後に公式拡張を明示登録する形を確認した。
- `packages/core/rules/extensions.md` に公式拡張のブート、CSV、設定ページ、サムネイル、ACFの最小例を追加した。
- `TempFileStore::store()` のMIME判定順を修正した。移動後の一時ファイルに `finfo` をかけてPHP Warningが出る問題を、回帰テストで固定した。
- `starter-plugin` の `bin/setup.php` を修正した。作成先ディレクトリ名が `plugin` の場合は本体ファイルのリネーム元/先が同一になるため、ファイル存在を確認して終了し、リネーム元欠落は明示エラーにした。
- スターターと sample-site の `.gitignore` に `/.phpunit.result.cache` を追加し、PHPUnit結果キャッシュを成果物から除外した。

## 検証

- `composer qa`: OK
  - php-cs-fixer: `Found 0 of 282 files that can be fixed`
  - PHPStan: `No errors`
  - Unit: `OK (225 tests, 954 assertions)`
  - Integration: `OK (12 tests, 129 assertions)`
- `composer monorepo:validate`: OK
- `php tools/extract-rules-code.php && php tools/verify-rules-code.php`: OK
  - rules内PHP例を `php -l` で検査。
  - 抽出した `App\` クラス例をPHPStan解析し `No errors`。
- `composer qa --working-dir=examples/sample-site`: OK
  - php-cs-fixer: `Found 0 of 18 files that can be fixed`
  - PHPStan: `No errors`
  - actions規約検査: `No errors`
  - PHPUnit: `OK (1 test, 2 assertions)`
- starter create-project検証: OK
  - `starter-theme`: ローカル path repository から生成し、`composer install && composer qa` が成功。
  - `starter-plugin`: 作成先ディレクトリ名 `plugin` で生成し、`composer install && composer qa` が成功。
  - `starter-plugin`: 作成先ディレクトリ名 `my-plugin` で生成し、本体ファイルが `my-plugin.php` にリネームされ、`plugin.php` が残らないことを確認。
- wp-env sample-site検証: OK
  - `npx wp-env start`: OK (`http://localhost:8890`)
  - `wp theme activate sample-site`: OK
  - `wp tmswp migrate`: OK (`sample_inquiries migrated/current`)
  - `wp tmswp acf sync`: OK (`AcfManifest.php`, `ProductFields.php`, `ThemeSettingsOptions.php`)
  - `wp tmswp acf check`: OK (`ACF bridge is in sync`)
  - `wp tmswp csv import products wp-content/themes/sample-site/data/products.csv`: OK (`total=3 processed=3 imported=3 skipped=0 failed=0`)
  - `wp post list --post_type=product`: 3件作成を確認。
  - `wp term list product_category`: 2分類作成を確認。
  - `curl -I http://localhost:8890/product/`: `HTTP/1.1 200 OK`
  - `curl -I http://localhost:8890/product/sample-camera/`: `HTTP/1.1 200 OK`
  - `curl -I http://localhost:8890/contact/`: `HTTP/1.1 200 OK`
  - Playwright: トップ、製品アーカイブ、製品個別、問い合わせページの表示を確認。
  - Playwright: 問い合わせフォームの入力→確認画面、添付ファイル名表示まで確認。
  - curl multipart POST: 問い合わせフォームの入力→確認→完了まで `confirm_http=200` / `complete_http=200`、完了メッセージ表示を確認。
  - `wp eval`: `sample_inquiries` 最新行に `customer_name=山田 太郎` / `email=yamada@example.com` / `attachment_id=8` / `attachment_name=contact-upload.txt` を確認。
  - `wp post list --post_type=attachment`: `contact-upload` が `text/plain` 添付ファイルとして登録されることを確認。
  - `docker logs --since 5m`: 修正後のフォームE2Eでは `HEAD /contact/ 200`、`GET /contact/ 200`、`POST /contact/ 200`、`POST /contact/ 200` のみ。PHP Warning/Fatalなし。

## 残確認

- GitHub公開後の外部工程は未実施。pushはユーザーが手動で実施する前提。
  - remote push / tag push
  - GitHub Actions実行結果確認
  - Packagist公開・更新確認

---

# 2026-07-05 再編: R1〜R5 横断

## 実装内容

- R1: 日本特化 Form プリセットを core から外し、カナは ja-toolkit、日付 select は rules レシピへ移した。
- R3: core 既定言語を en にし、日本語翻訳ファイルと textdomain ロードを追加した。
- R4: `Thumbnail::url()` の WP_DEBUG 時再送出を実装した。
- R5: `ContentImageFallback` を削除し、`tmswp/post/thumbnail_fallback` の app 側レシピへ移した。

## 検証

- 対象ユニット: `composer test:unit -- --filter 'EntityDefinitionTest|FormValidationTest|FormRuntimeTest|FormViewAndFileTest|KernelTest|JaToolkitTest|KanaTextFieldTest|L10nTest'` 成功。
- `composer qa:fast`: 成功。
- `composer monorepo:validate`: 成功。
- rules コード例検査: `php tools/extract-rules-code.php && php tools/verify-rules-code.php` 成功。
- sample-site 再インストール後 QA:
  - `composer reinstall tmswp/core tmswp/ja-toolkit tmswp/thumbnail`: 成功。
  - `composer qa` (`examples/sample-site`): 成功。

## 残確認

- `composer test:integration` は現行 `npx wp-env` の案内だけで実テストが走らなかったため、現行コマンドとして `npm exec -- @wordpress/env start` を試行したが、Docker イメージビルド中に `/var/cache/apt/archives` の空き容量不足で失敗した。統合テスト(wp-env)は未完了。
