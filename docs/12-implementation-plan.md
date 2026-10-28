# 12. ロードマップ(v0.1.0〜v0.9.0) — TMStack WordPress Framework

## 目的

本書は、TMStack WordPress Framework(以下 TMSWP)を v0.1.0 から v0.9.0 まで版ごとに整備していく計画を定める。本書が確定させるのは次のとおり。

- 版の構成と各版の目的・依存関係
- 各版のタスク(対象ファイル領域・完了条件・検証コマンド)
- 各版の受け入れ条件と、全版に共通する進め方
- タスクと版の並列化の方針

本書は「何をどの版で・どの順に作るか」だけを定める。「何をどう設計するか」は設計文書 docs/00〜11 が正準であり、本書と設計文書が矛盾した場合は**設計文書を優先**する。ただし §1 に挙げた設計方針のうち、まだ設計文書に反映されていないものは、担当タスクが設計文書を改訂するまで本書が根拠となる。

## スコープ(対象)

monorepo `tmswp` 全体。すなわち:

- `packages/core`(tmswp/core)
- `packages/thumbnail` / `packages/settings-page` / `packages/acf-bridge` / `packages/ja-toolkit`
- `starters/theme`(tmswp/starter-theme)/ `starters/plugin`(tmswp/starter-plugin)
- `examples/sample-site`
- リポジトリルートの QA 基盤・CI・配布ワークフロー・文書

## 依存文書

設計文書 docs/00〜11 のすべて。設計判断(DR)は `00-overview.md` §4、エラー方針は同 §5.6、用語は同 §7 を参照する。リリース手順は `RELEASING.md`、各版の受け入れ記録は `acceptance/v0.x.y.md` に置く。

---

# 0. 全版に共通する進め方

## 0.0 出発点

本計画は、設計文書 docs/00〜11 と、5 パッケージ・2 スターター・examples/sample-site の現在の実装を出発点とする。各版では、設計文書を正準として実装を設計どおりに直し、設計側の問題は設計文書を改訂して解消する。

## 0.1 作業手順

- 作業はブランチ`rewrite/v0`で行い、1タスクごとにレビューを経てコミットする。コミットメッセージは日本語で、変更内容がひと目で分かる具体的な表現にする。`main`は初期のコミットだけを持ち、`rewrite/v0`の`main`への反映は、プルリクエスト#1(`rewrite/v0`→`main`)のマージで、ユーザーの確認を経て行う(v0.9.0-T4)。
- タグ付けとリリースはメンテナが `RELEASING.md` の手順で行う。
- コードを変えるタスクは、対応する docs/00〜10 と rules/ の改訂も同じタスクで行う。設計側の問題が見つかった場合は、設計文書の改訂案をそのタスクで示し、合意のうえで同じタスクで反映する。
- 破壊的変更では、旧名・deprecated コメント・移行分岐を残さない。0.x の間は後方互換を保証しない。
- 各版の着手時、その版で触るディレクトリについて、PHP 8.3 の構文(型付きクラス定数、`#[\Override]` 等)の採用と、WordPress 7.0 未満向けの分岐の削除をタスクの中で行う。
- 各タスクは「対象ファイル領域」に挙げたファイルだけを変更する。対象外のファイルを変える必要が生じた場合は、タスクの範囲を見直してから着手する。

## 0.2 全タスク共通の完了条件

タスクごとの完了条件に加えて、次を満たすこと。

- `composer lint`・`composer analyse`・`composer test:unit` が通る(v0.1.0 の T1〜T5 の途中段階と、T5 より前に行う T7 を除く。T7 は T5 で消える tools/build-l10n.php の analyse の 2 件だけを許容する。各タスクに明記する)。
- v0.1.0 の T4 以降は `composer test:integration` も通る。
- 変更したファイルに TODO の書き置き、`markTestSkipped` / `markTestIncomplete`、未実装の分岐が無い。
- rules/ を変えた場合は `php tools/extract-rules-code.php && php tools/verify-rules-code.php` が通る。

## 0.3 全版に共通する受け入れ条件

各版の受け入れ条件に加えて、次を満たすこと。

- その版のすべてのタスクが完了条件を満たしている。
- integrationを含む`composer qa`がCIで通る(v0.1.0以降)。CIでは、`composer qa`の各段をジョブに分けて実行する。integrationの段(`test:integration`)は4セル(PHP 8.3と8.5 × WordPress 7.0と最新版)で、lint・analyse・`discover:check`等のほかの段は別のジョブで実行する(ジョブの分け方は`10-quality.md` §5.1)。そのため、この条件は、同じコミットでCIの全ジョブが成功することで確かめる。
- `composer monorepo:validate` と、rules/ のコード例の抽出・検査が通る。
- 受け入れ記録を `docs/acceptance/v0.x.y.md` に書く。

## 0.4 並列化の規則

- 同じファイル(docs を含む)を触るタスクは並列にしない。並列にできる組は各版の「並列化」に示す。
- 設計文書の改訂を伴うタスクは、その文書の節を自分の対象ファイル領域に含める。並列に置いたタスクどうしは、担当する文書ファイルも重ならない。文書の改訂を伴わないタスクは、その旨を対象ファイル領域に書く。
- 同じ文書ファイルを節で分担すれば並列にできる箇所(v0.4.0 の docs/05、v0.6.0 の docs/09、v0.7.0 の docs/08)も、本計画では逐次にする。
- examples/sample-site と starters/ は v0.8.0 で作り直す。それより前の版では、削除した API を参照する箇所の除去(v0.5.0 のテンプレート前処理の置き換え)と、ログ出力先の指定(v0.2.0)だけを直す。

## 0.5 版をまたいで共有するファイルの調整

v0.3.0・v0.4.0・v0.5.0 は v0.2.0 の後に並列に進められる。v0.6.0(v0.3.0 の後)は v0.4.0・v0.5.0 と、v0.7.0(v0.4.0 と v0.6.0 の後)は v0.5.0 と時期が重なりうる。次のファイルは複数の版のタスクが触るため、表の順で逐次にする(先行するタスクの完了後に次のタスクが着手する)。

| ファイル | 触るタスク(この順で逐次) |
|---|---|
| packages/core/src/Kernel/Kernel.php | v0.3.0-T3 → v0.5.0-T1 → v0.5.0-T4 |
| docs/02-kernel.md | v0.3.0-T3 → v0.5.0-T1 → v0.5.0-T4 |
| docs/00-overview.md | v0.4.0-T1(DR-07)→ v0.5.0-T1(DR-19)→ v0.6.0-T1(DR-06) |
| packages/core/tests/Support/(WpdbTestDouble 等) | v0.4.0-T6 → v0.5.0-T6 |
| packages/core/phpstan/(extension.neon を含む) | v0.5.0-T1 → v0.5.0-T3 |
| phpstan.neon.dist | v0.5.0-T1 → v0.6.0-T3(どちらが先でもよいが同時に触らない) |
| docs/10-quality.md | v0.4.0-T6(§8.1)と v0.5.0-T1(§3A)(どちらが先でもよいが同時に触らない) |
| packages/core/rules/extensions.md | v0.6.0-T1 → v0.7.0-T5(版の依存で逐次) |
| packages/core/rules/acf.md | v0.2.0-T3 → v0.2.0-T6 → v0.6.0-T1 → v0.6.0-T7 |
| tests/fixtures/(fixture のテーマ・プラグインと探索結果ファイル) | v0.3.0〜v0.5.0 で fixture を変えるタスク(同時に触らない。fixture を変えたタスクが探索結果ファイルを再生成する) |

## 0.6 版の依存関係

- v0.1.0 → v0.2.0。v0.2.0 は v0.3.0〜v0.7.0 の前提。
- v0.3.0・v0.4.0・v0.5.0 は互いに独立で並列にできる(共有ファイルは §0.5 の順で調整する)。
- v0.6.0 は v0.3.0 の後。v0.7.0 は v0.2.0・v0.4.0・v0.6.0 の後(v0.7.0 の受け入れ条件が acf-bridge を含む 4 拡張の起動 API の統一を求めるため)。
- v0.8.0 は v0.2.0〜v0.7.0 の後。v0.9.0 は v0.8.0 の後。

```
v0.1.0 → v0.2.0 ─┬→ v0.3.0 ─→ v0.6.0 ─┐
                 ├→ v0.4.0 ───────────┼→ v0.7.0 ─┐
                 └→ v0.5.0 ───────────┴──────────┴→ v0.8.0 → v0.9.0
```

## 0.7 カバレッジの基準

- unit と integration を合算した行カバレッジ(testingを含む6パッケージのsrc)で判定する(DR-12、`10-quality.md` §4.8)。WordPress に依存するクラスは、モックを多用したユニットテストではなく、実物の WordPress での統合テストで確かめる。
- 閾値は composer.json の `test:coverage:check` の引数の 1 か所で定義し、次の表と一致させる。v0.1.0 は実測の合算値(58.34%)の小数点以下を切り捨てた値とし、v0.2.0〜v0.9.0 は v0.9.0 の 85% まで均等に引き上げる(整数に丸める)。

| 版 | 閾値(合算の行カバレッジ) |
|---|---|
| v0.1.0 | 58% |
| v0.2.0 | 61% |
| v0.3.0 | 65% |
| v0.4.0 | 68% |
| v0.5.0 | 72% |
| v0.6.0 | 75% |
| v0.7.0 | 78% |
| v0.8.0 | 82% |
| v0.9.0 | 85% |

- 各版の受け入れ確認のタスク(各版の最後のタスク)は、composer.json の閾値を、その版の値と完了済みの版の値のうち最大のものにし、CI の `coverage` ジョブで判定が通ることを確かめる。この閾値の変更は、受け入れ確認のタスクの対象ファイル領域に含む。ただしv0.9.0では、タグ`v0.9.0`のcomposer.jsonに閾値を入れるため、受け入れ確認のタスク(T5)ではなく、公開の前のT3で上げる。
- パッケージ別の判定は v0.8.0-T6 で加える(パッケージ別の基準値はその版で決める)。v0.8.0-T6で、基準値をtestingを含む6パッケージ(core・acf-bridge・settings-page・thumbnail・ja-toolkit・testing)とも80%に決めた(composer.jsonの`test:coverage:check`の`--package`の引数で定義する)。
- 版の完了の順が版番号の順と異なる場合(§0.6)も閾値は下げない。たとえば v0.5.0 が v0.3.0 より先に完了した場合、v0.3.0 の受け入れ確認では、合算カバレッジが v0.3.0 の値(65%)以上であることを確かめ、閾値は v0.5.0 の値(72%)のまま保つ。

---

# 1. この計画で取り入れる設計方針

各版のタスクは、設計文書に加えて次の方針に従う。各項の「反映先」に挙げた設計文書は、その方針を担当するタスクが改訂する。

次の方針は v0.1.0 で `00-overview.md` に反映済みであり、各版でコードをこれに揃える。

- エラー方針表(`00-overview.md` §5.6)。設定の誤りは起動時に常に例外、実行時のデータ取得失敗は debug 時は例外・本番は Kernel のロガー(`$kernel->logger()`)に `error` で記録したうえで既定値、データが無いことはエラーにしない。
- `exit` 禁止の例外(`00-overview.md` §3 原則1)。PRG のリダイレクト直後と、設定ページ保存後の `wp_safe_redirect()` 直後に限る。
- ブロックテーマ・ACF Blocks 向けコード生成・マルチサイトは対象外(`00-overview.md` §2.2)。

## 1.1 対応範囲と開発環境

- 対応範囲は PHP 8.3〜8.5、WordPress 7.0 以上(DR-02)。CI の unit は PHP 8.3 と 8.5、integration は「PHP 8.3 と 8.5」×「WP 7.0 と最新版」の 4 セルで、統合テストのテストライブラリは各セルの WP の版に揃える(DR-12)。
- wp-envのDBはMariaDBに固定されるため、MySQL 8.4 LTSと9.7 LTSでの統合テストは、CIのservicesで立てたMySQLにwp-envのcliから接続するジョブ(2セル)で行う(v0.9.0-T3a)。
- `@wordpress/env` は package.json で版を固定し、ローカルに入ったものだけを呼ぶ。コマンド名 `wp-env` を npx 等で名前解決する呼び出しは、第三者のパッケージやグローバルの同名コマンドを実行しうるので使わない。
- 反映先: `00-overview.md` DR-02、`01-architecture.md` §3・§5、`10-quality.md` §1〜§5、AGENTS.md。

## 1.2 ACF の検証

- 利用者は ACF Pro を使う前提とする。CI では wordpress.org の無料版 ACF(advanced-custom-fields)で確認する。
- Pro限定機能(repeater・flexible_content・gallery・clone等)のテストは、ACF Proのzipのパスを環境変数`TMSWP_ACF_PRO_ZIP`で渡したときだけ、手元で`composer test:integration:acf-pro`で走らせ、その結果を記録する(隔離したwp-envで走らせる。docs/10 §4.9)。実物のACF Proでの最終確認はv0.9.0で行う。
- 偽の ACF 関数を定義するテストは置かない。
- 反映先: `09-acf-bridge.md` §14、`10-quality.md` §4、.wp-env.json。

## 1.3 想定する運用環境

TMSWP の主な利用形態は、1 サイト用のテーマ(必要に応じてプラグインを併用)である。運用環境として次を想定する。

- デプロイは FTP。本番環境へのデプロイに GitHub Actions や rsync は使わない。本番で WP-CLI は使える。
- CDN は使わない。ページキャッシュ(WP Super Cache 等)は導入されうる。
- Web サーバーは Apache。外部ストレージは使わない。
- 独自テーブル(Model)を使うことがある。

## 1.4 探索結果のビルド時生成

- Discovery の探索結果は、ビルド時に探索コマンドでファイルとして生成してコミットする。CI はそのファイルが最新かを照合し、古ければ失敗する(acf-bridge の生成物と同じ扱い)。
- 本番(debug=false)では探索結果ファイルが無ければ起動時にエラーにする。app/ の走査は debug 時だけ行う。
- 反映先: `02-kernel.md` §4・§6、`10-quality.md` §5。担当: v0.2.0-T1。

## 1.5 マイグレーションの実行方法

- 独自テーブルのマイグレーションは `wp tmswp migrate` で実行する。起動時・`admin_init`・ajax・cron で自動適用はしない(FTP のアップロード途中の状態でテーブルを変更しないため)。
- テーブル定義のハッシュが DB と食い違う場合は、権限のある管理者に管理画面で通知する。
- 反映先: `06-model.md` §5、rules/model.md。担当: v0.5.0-T4・T6。

## 1.6 Kernel ごとの拡張登録と版照合

- 拡張パッケージ(acf-bridge・settings-page・thumbnail・ja-toolkit)は `KernelConfig` 経由で Kernel ごとに登録する。拡張はこの入口だけで有効化される。テーマとプラグインの併用を可能にするためである。
- 起動時に `Kernel::VERSION` と Composer の installed.php を照合し、同じサイトで TMSWP の版が食い違っていれば検出する。
- スターター(プラグイン)の名前空間は、create-project 時にプラグインごとに自動生成する。
- 反映先: `01-architecture.md` §3.3、`02-kernel.md`、`08-extensions.md`、`09-acf-bridge.md`、rules/extensions.md。担当: v0.2.0-T6(コア側)、v0.6.0-T1(acf-bridge)、v0.7.0-T1〜T3(他の拡張)、v0.8.0-T2(スターター)。

## 1.7 debug の判定

- debug の判定は `KernelConfig::$debug` に一本化する(既定値は `WP_DEBUG` の真偽)。拡張は、自分を登録した Kernel の debug に従う。`WP_DEBUG` を直接参照して分岐する箇所は残さない。
- 反映先: `02-kernel.md` §2、`08-extensions.md`、`09-acf-bridge.md`。担当: v0.2.0-T5・T6、v0.6.0-T1、v0.7.0-T1〜T3。

## 1.8 ログのチャネルと psr/log・psr/container の非互換

- ログのチャネルを Kernel ごとに分け、呼び出し元の Kernel で出力先を決める(`Log::set` の後勝ちを無くす)。
- 他のプラグイン(または WP-CLI の phar)が先に読み込んだ psr/log・psr/container が非互換な場合は、起動時に検出して明確なエラーにし、利用条件を packages/core/README.md と `01-architecture.md` に記す。WP-CLI の phar は psr/container 1.0 を含み、これが先に読み込まれると `Container` の宣言と合わずに致命的エラーになる(wp-cli.yml の require で autoload を早く登録した場合や、phar 側の interface を読み込む先行プラグインがある場合)。
- 反映先: `01-architecture.md`、`02-kernel.md`、`07-support.md` §5、packages/core/README.md。担当: v0.2.0-T6。

## 1.9 メタ・設定の使い分け

- 単純な値(文字列・数値・真偽・日付・単一画像 ID 等)は必ず `#[MetaSchema]` で宣言し、アプリは `MetaBag` だけを使う。ACF の画面で編集する場合も `#[UseMetaStorage(AcfMetaStorage::class)]` で保存先を切り替えるだけにする(ACF に完全依存させないため、`UseMetaStorage` と `AcfMetaStorage` を維持する)。
- ACF 固有の複合型(repeater・flexible_content・group・gallery・relationship)は acf-bridge の生成アクセサで扱い、その案件は ACF に依存するものと割り切る。
- 入力検証が重要なシステム設定(メールの宛先、API キー等)は settings-page で作る。フロントに出すコンテンツ設定(画像、繰り返し項目等)は ACF のオプションページで作る。
- 反映先: `04-meta-options.md`、`08-extensions.md`、`09-acf-bridge.md`、rules/meta.md・options.md・acf.md。担当: v0.3.0-T1、v0.6.0-T2・T7、v0.7.0-T1。

## 1.10 テンプレート前処理の明示呼び出し

- テンプレート前処理の actions/ 規約(ActionRouter、View 変数バッグ、`tms_var()`、関連する PHPStan ルール 2 本と `tms-check`)を廃止する。テンプレートの先頭で型付きクラスを明示的に呼び、readonly DTO を受け取る方式(例: `$vm = ContactPage::handle();`)に置き換える。
- 反映先: `00-overview.md` DR-19、`02-kernel.md`、`05-form.md`、`07-support.md`、`10-quality.md` §3A、rules/。担当: v0.5.0-T1。

## 1.11 独自ブロックの自動登録

- React(@wordpress/scripts)で作る独自ブロックを自動登録する仕組みをフレームワークに設ける。
  - ソースは `<theme>/blocks/<slug>/`(1 ブロック 1 ディレクトリ、入れ子可)、ビルド出力は `build/blocks/`。
  - 探索コマンド(§1.4)が `build/blocks/**/block.json` も走査し、同じ探索結果ファイルに出力する。実行時は `register_block_type()` で登録する。
  - 動的描画は block.json の `"render": "file:./render.php"`。ブロック専用のフックは `#[AsHook]` クラスで書く。
  - 投稿タイプごとの制限は `PostTypeDefinition` の `allowedBlocks()` で宣言し、`allowed_block_types_all` に反映する。カテゴリは `KernelConfig` で登録する。
  - 名前の重複や未登録のカテゴリはビルド時(探索コマンド)のエラーにする。npm のビルドと探索コマンドはスクリプトで連結する。
- 反映先: `02-kernel.md`、`03-entities.md`、rules/blocks.md(新設)。担当: v0.8.0-T1。

## 1.12 フォームの標準機能

- フォームは次を標準で備える。ウィザード形式(4 画面以上)は対象外とする。
  1. 確認画面の無いフォームも含め、使い捨ての送信トークンで二重送信を防ぐ。確認画面ありの場合は ConfirmToken の jti に一本化する。
  2. フォームのあるページを自動でページキャッシュから外す(`nocache_headers()` と `DONOTCACHEPAGE`)。
  3. スパム対策(Turnstile、honeypot 等)を差し込む仕組みと、その手本コード。
  4. メール送信の手本となるレシピ rules/form-mail.md。
- `onComplete` で起きた例外はエラー方針(`00-overview.md` §5.6)に従う。debug 時は例外を投げる。本番では Kernel のロガー(`$kernel->logger()`)に `error` で記録し、利用者には汎用の送信失敗エラーをフォームに出す。このとき JTI は消費せず、再送できる状態にする。
- 反映先: `05-form.md`、rules/form.md・form-mail.md。担当: v0.4.0-T1・T5。

## 1.13 ソースの文言とラベル言語の既定

- ソースの文言(gettext のソース文字列)は英語のままとする。
- CPT / タクソノミーのラベル言語の既定は `KernelConfig` でまとめて指定できるようにし、スターターの既定は日本語にする。
- 反映先: `02-kernel.md` §2、`03-entities.md` §2.2、rules/。担当: v0.2.0-T3・T6、v0.8.0-T2。

## 1.14 Model の範囲

- Model は不具合の修正だけを行う。トランザクション・upsert・一括 insert 等の新機能は、必要になるまで追加しない。
- どの Kernel にも属さない Model クラスの接続を `fromGlobal()` へ暗黙に切り替える経路は廃止して例外にする。Kernel の外(uninstall.php 等)では `useConnection()` で接続先を明示する。
- 反映先: `06-model.md`、rules/model.md。担当: v0.5.0-T2・T3。

## 1.15 thumbnail の REST API 前提

- thumbnail は REST API が使えることを利用条件とする。自分の REST ルートが無効化されていることを起動後に検出したら、管理画面で管理者に通知する。REST 以外の経路は作らない。
- 反映先: `08-extensions.md` §1、packages/thumbnail/README.md。担当: v0.7.0-T2。

## 1.16 配布

- 各パッケージを read-only split で配布し、Packagist に登録する(DR-11)。ライセンスは GPL-2.0-or-later。CI と split には GitHub Actions を使う。
- 開発はmonorepo(`tmstack-io/tmswp`)で行う。公開時はGitHub Actionsで`tmstack-io/tmswp-{パッケージ名}`の読み取り専用リポジトリ8つ(tmswp-core、tmswp-acf-bridge、tmswp-settings-page、tmswp-thumbnail、tmswp-ja-toolkit、tmswp-testing、tmswp-starter-theme、tmswp-starter-plugin)へ自動で同期する。Packagistの名前は`tmswp/*`のまま。
- 読み取り専用にする方法は、Issue と Wiki の無効化、説明文での monorepo への案内、誤って送られたプルリクエストの自動クローズと案内である。アーカイブは使わない(同期が書き込めなくなるため)。
- スターターは `composer create-project tmswp/starter-theme` 等で作る。
- 名前空間のプレフィックス化(strauss 等)は行わない。
- 反映先: `01-architecture.md` §3〜§4、`10-quality.md` §5〜§6、`RELEASING.md`。担当: v0.8.0-T3、v0.9.0-T4a(リリースの道具とsplit.yml)、v0.9.0-T4(公開)。

## 1.17 導入できる版

- 利用者のプロジェクトへの導入は、0.x 系の最後の版(本計画では v0.9.0)から行う。1.0.0 は導入先で安定運用を確認してから出す。
- 導入できる版の条件は次の 5 つ。
  1. 既知の critical / high の不具合がすべて解消している。
  2. 全マトリクスで `composer qa`(integration を含む)が通る。
  3. 実物の ACF Pro で確認済みである。
  4. 想定する用途の機能(フォーム、設定ページ、サムネイル、日本語対応、ACF、ブロック)が examples/sample-site で動く。
  5. unit と integration の合算の行カバレッジが 85% 以上である(§0.7)。
- 本計画全体の完了条件は次の 3 つ。
  1. integration を含む `composer qa` がすべて通る。
  2. examples/sample-site が実際の WordPress 上で動く。
  3. 利用者のプロジェクトから `composer require` で導入できる。
- 担当: v0.9.0。

## 1.18 文書の置き場

- 用語は `00-overview.md` §7、設計判断は DR として `00-overview.md` §4 に置く。用語集や ADR の別ファイルは作らない。
- 規約は AGENTS.md だけに置き、`CLAUDE.md` 等のエージェント固有の規約ファイルは置かない。
- レシピは各パッケージの rules/ だけに置き、設計文書には複製しない(`11-ai-docs.md` §1.2・§7)。

---

# 2. 版ごとの計画

## v0.1.0 — 基盤の健全化(品質ゲートを本当に動かす)

### 目的

品質ゲート(lint・analyse・unit・integration)を全マトリクスで実際に動かし、以後の版がこれで検証できる状態にする。あわせて、文書を正準として使える状態に整える。

### 依存する版

なし。

### タスク

#### v0.1.0-T1: 開発基盤の更新(下限・静的解析・wp-env・CI)

- 対象ファイル領域: composer.json(ルート・packages/*・starters/*・examples/sample-site)、package.json・package-lock.json(新設)、.wp-env.json、phpstan.neon.dist、.php-cs-fixer.dist.php、vendor-bin/、.github/workflows/qa.yml、packages/core/extension.neon(削除し、packages/core/phpstan/extension.neon に統合)、docs/00 DR-02、AGENTS.md(PHP・WP の下限と wp-env の記述)、docs/01 §3.2・§3.5、docs/10 §1〜§2・§4.2・§5
- 完了条件:
  - 全 composer.json の PHP 下限が 8.3、PHPStan の解析範囲が 8.3〜8.5。
  - package.json で @wordpress/env を版固定し、composer の `test:integration`・`wp:start`・`wp:stop` がローカルに入ったものだけを呼ぶ(§1.1)。
  - .wp-env.json に無料版 ACF(advanced-custom-fields)が入っている(§1.2)。
  - qa.yml の unit と integration が「PHP 8.3 と 8.5」×「WP 7.0 と最新版」で動き、テストライブラリが各 WP の版に揃っている。
  - packages/core/composer.json の `ext-mbstring` と PHPStan 拡張の設定ファイル構成が、正準 docs に揃っている。
  - DR-02・AGENTS.md の下限・docs/01・docs/10 の該当記述が §1.1・§1.2 に沿って改訂され、PHPStan 拡張の include パス・branch-alias・解析と整形の対象範囲・vendor-bin のテストライブラリの版指定について、文書間と実装の食い違いが無い。
  - `composer test:integration` が wp-env 上で PHPUnit を起動する(テストの成否は T4 で扱う)。
- 検証コマンド: `composer validate`、`composer monorepo:validate`、`npm ci && npm ls @wordpress/env`、`composer wp:start`、`composer test:integration`、`composer lint`

#### v0.1.0-T2: analyse と unit の修復

- 対象ファイル領域: bin/coverage-check.php、tests/bootstrap-unit.php、packages/core/tests/Support/、packages/core/tests/fixtures/view/、packages/core/tests/Unit/FormViewAndFileTest.php(docs の改訂は伴わない。いずれも既存の仕様にコードを合わせる修正)
- 完了条件:
  - bin/coverage-check.php に PHPStan の `variable.undefined` が無い(tools/build-l10n.php の分は T5 で同ファイルごと無くなる)。
  - ユニット用 bootstrap が WP 定数と偽 wpdb を定義せず、WpdbTestDouble が書き込み失敗(false)を返せる。
  - fixture が WP のクラス・関数をグローバルに定義しない。
  - `composer test:unit` が PHP 8.3 と 8.5 の両方で通る。
- 検証コマンド: `composer analyse`、`composer test:unit`(PHP 8.3 と 8.5 で実行)

#### v0.1.0-T3: 文書の整理

- 対象ファイル領域: docs/00〜12(T1 が改訂した節を除く)、docs/README.md、docs/RELEASING.md、docs/acceptance/、AGENTS.md(ロードマップと受け入れ記録の記述)
- 完了条件:
  - docs/12 が版番号のロードマップ(本書)になり、docs/acceptance/ は `v0.x.y.md` 形式の記録だけを置く場所になっている。AGENTS.md の記述がロードマップに合っている。
  - docs/00 にエラー方針表(§5.6)、マルチサイト非対応とブロックテーマ非対応(§2.2)、設計原則1の `exit` 禁止の例外(PRG のリダイレクト直後と、設定ページ保存後の `wp_safe_redirect()` 直後)がある。
  - docs/11 にレシピの複製が無く、レシピの正本が各パッケージの rules/ であることが明記されている(§1.18)。
  - 文書間の食い違い(テキストドメインのロード、ブート例のクラス名、ヘルパの予約表とファイル名、Hook 基底の正準仕様の所在、パッケージ数、ライセンス表記、廃止済み API や存在しないパスへの言及、rules の検証の出力先)が、正準 docs と実装を基準に解消している。設計を変える項目は改訂案として示した。
  - docs/00〜11 に文書自体の改訂の経緯を書いた記述が無い。
- 検証コマンド: `ls docs/acceptance`、`grep -rnE "M[0-9]-T[0-9]|FINAL\.md|[68] ?パッケージ|docs/tmswp[/]|Preview[M]etaSupport" docs AGENTS.md`(0 件)、`grep -n "MIT" docs/10-quality.md`(0 件)、`composer lint`、`php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.1.0-T4: 統合テスト基盤の作り直し

- 対象ファイル領域: tests/bootstrap-integration.php、tests/fixtures/(新設。fixture のテーマ・プラグイン、`_boot.php`)、phpunit.integration.xml.dist、packages/core/tests/Support/(IntegrationTestCase)、packages/*/tests/Integration/、docs/10 §4(統合テストの記述)
- 完了条件:
  - 統合テストは、fixture のテーマ/プラグインを `_boot.php` で init 前に boot する正規の経路で動き、共通ヘルパは IntegrationTestCase にある。
  - 偽の ACF 関数が無く、無料版 ACF が有効な状態での最小の統合テストが通る(acf-bridge の網羅は v0.6.0-T6)。
  - 既存の統合テストを新しい経路に移し、全セルで通る。
- 検証コマンド: `composer wp:start`、`composer test:integration`、`grep -rn "function get_field\|function acf(" packages/*/tests`(0 件)

#### v0.1.0-T5: 翻訳ビルドの置き換え

- 対象ファイル領域: tools/build-l10n.php(削除)、packages/*/languages/、composer.json(ルートの i18n スクリプト)、packages/core/tests/Unit/L10nTest.php、packages/core/tests/Unit/KernelTest.php(ローダー部分)、packages/core/tests/Integration/(翻訳の統合テストを新設)、docs/00 DR-17、docs/01 §7
- 完了条件:
  - 翻訳ファイルを `wp i18n make-pot` / `make-mo` / `make-php` で生成し、tools/build-l10n.php が無い。pot 名が docs/01 §7.2 どおり。
  - L10nTest は `WP_Translation_File` 互換の構造とソースから抽出した文字列を照合し、`switch_to_locale('ja')` で訳が返る統合テストが全セルで通る。
  - `composer analyse` のエラーが 0 件。
- 検証コマンド: `composer i18n:build`(置き換え後のスクリプト)、`composer analyse`、`composer test:unit`、`composer test:integration`

#### v0.1.0-T6: 受け入れ確認と記録

- 対象ファイル領域: docs/acceptance/v0.1.0.md
- 完了条件: §0.3 の共通受け入れ条件と、この版の受け入れ条件を確認して記録する。
- 検証コマンド: `composer qa`(CI の 4 セル)、`composer monorepo:validate`、`php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.1.0-T7: 合算カバレッジと外部アクションの固定

- 対象ファイル領域: bin/coverage-check.php、tests/Unit/(新設。bin/ のユニットテスト)、composer.json(ルートの `test:coverage*`・`wp:start:coverage` スクリプト)、phpunit.unit.xml.dist(testsuite `tools`)、phpunit.integration.xml.dist(`<coverage>`)、phpstan.neon.dist(`paths`)、.github/workflows/qa.yml、.github/workflows/split.yml、docs/00 DR-12・§7、docs/01 §2・§4.4、docs/02・03・05・06・07・11 の統合層の表記、docs/10 §1・§2.1・§4・§5・§9・受け入れ基準、docs/12 §0.7・§1.16・§1.17・各版の受け入れ条件・v0.9.0-T4
- 完了条件:
  - unit(ホストの PHPUnit 11 と pcov)と integration(wp-env のコンテナ内の PHPUnit 9.6 と Xdebug)の clover を、bin/coverage-check.php がリポジトリからの相対パスに正規化して行単位で合算し、§0.7 の v0.1.0 の閾値で判定する。閾値は composer.json の 1 か所だけにある。
  - 不正な引数・読めない clover・正規化できないパス(ルートの外、`..` でルートを出る表記)・範囲外の行の属性が exit 2 になり、同じファイルの別表記(`./`、重複したスラッシュ、`../`)が同じ計測対象になることを、tests/Unit のテストで確かめている。
  - qa.yml の `coverage` ジョブ(PHP 8.3 / WordPress 最新版の 1 セル)が合算を判定し、unit のセルは単独でカバレッジを判定しない。
  - qa.yml と split.yml のすべての `uses:` がコミットの SHA で固定され、行末にタグ名のコメントがある(docs/10 §5.3)。
  - split.yml の送り先が `tmstack-io/tmswp-{パッケージ名}` の 7 リポジトリである(§1.16)。
  - docs/10 §1.1・§2.1・§4.3・§4.5・§5.1・§5.2 の完全版と docs/01 §4.4 のワークフローが、実ファイルと一致する。
- 検証コマンド: `composer validate`、`composer lint`、`composer analyse`(T5 で消える tools/build-l10n.php の 2 件を除き 0 件)、`composer test:unit`(PHP 8.3 と 8.5 で実行)、`composer wp:start:coverage && composer test:coverage`、`composer wp:start && composer test:integration`、`grep -nE "uses:" .github/workflows/*.yml | grep -vE "uses: [^@]+@[0-9a-f]{40} # "`(0 件)、`grep -rn "wp-[p]hpunit" docs AGENTS.md`(0 件)

### 並列化

- 順序: {T1 ∥ T2} → T3 → T4 → T7 → T5 → T6
- T1 と T2 はファイル領域が重ならない。T1 は設定ファイル群と、下限・CI・wp-env に伴う文書(docs/00 DR-02、AGENTS.md の下限、docs/01 §3.2・§3.5、docs/10 §1〜§2・§4.2・§5)を担当する。T2 はテスト支援と bin だけを触り、docs を改訂しない。
- T3(残りの文書)は T1 と docs/00・01・10、AGENTS.md を共有するため T1 の後。T4 は T1(wp-env)・T2(tests/Support)・T3(docs/10)の後。T7 は T4(統合テスト基盤と phpunit.integration.xml.dist)の後。T5 は T3(docs/00・01)と T4(統合テスト基盤)の後で、T7 と composer.json・docs/00・01 を共有するため T7 の後。

### 受け入れ条件

1. integration を含む `composer qa` が CI の 4 セルで通り、integration が実際に実行されている(スキップ・空実行でない)。
2. コマンド名 `wp-env` を npx 等で名前解決する呼び出しが、composer スクリプト・CI・文書に残っていない。
3. 日本語訳が WP 7.0 と最新版で実際に表示される(統合テストで確認)。
4. docs/12 がロードマップであり、受け入れ記録が docs/acceptance/v0.1.0.md にある。
5. unit と integration の合算カバレッジが §0.7 の表のこの版の閾値(58%)以上である(CI の `coverage` ジョブ)。composer.json の `test:coverage:check` の閾値は、この版の値と完了済みの版の値のうち最大のものになっている(§0.7)。

---

## v0.2.0 — core 基盤

### 目的

Kernel・Discovery・Container・Log・Support・例外階層を固め、探索結果のビルド時生成(§1.4)、Kernel ごとの拡張登録と版照合のコア側(§1.6)、debug 判定の一本化(§1.7)、ログのチャネル分離(§1.8)、ラベル言語の既定(§1.13)、エラー方針の Kernel 側を実装する。

### 依存する版

v0.1.0

### タスク

#### v0.2.0-T1: 探索コマンドと探索結果ファイル

- 対象ファイル領域: packages/core/src/Kernel/{Discovery,DiscoveryResult,DiscoveredComponent,DiscoveredHookMethod}.php、packages/core/src/Kernel/DiscoveryFile.php(ComponentCache.php を置き換え)、packages/core/src/Kernel/DiscoverCommand.php(新設)、packages/core/src/Kernel/{Kernel,KernelConfig}.php(探索とキャッシュに関わる部分)、packages/core/src/Meta/{MetaSchemaDefinition,MetaSchemaRegistry}.php・packages/core/src/Option/OptionSchemaDefinition.php(使われていない復元用メソッドの削除)、packages/core/tests/Unit/{DiscoveryTest,DiscoveryFileTest,DiscoverCommandTest}.php、packages/core/tests/Unit/{KernelTest,KernelConfigTest}.php(該当部分)、packages/core/tests/Integration/KernelBootIntegrationTest.php、tests/fixtures/(fixture のテーマ・プラグインの探索結果ファイル、boot の引数、_boot.php・_activate.php・_wp-cli.php)、composer.json(ルートの scripts)、.github/workflows/qa.yml、docs/02 §1〜§7・受け入れ基準、docs/10 §1・§4・§5・§8・受け入れ基準、docs/00・docs/01・docs/04(§4.18 を除く)・docs/11・docs/README.md の探索キャッシュの記述、packages/core/rules/post-type.md、AGENTS.md(品質確認の行)、docs/12 §1.8・v0.2.0-T6(psr/container の非互換の検出を加える)
- 完了条件:
  - 探索コマンドが探索結果をファイルに生成し、照合モードで「ファイルが最新でない」ことを検出して非 0 で終わる。
  - 走査時に autoload の Error と `#[AsHook]` の継承違反を明示エラーにし、途中まで追加した登録を残さない。
  - 探索結果ファイルの読み込みは `KernelConfig` の appDir と照合し、本番でファイルが無ければエラー、走査は debug 時のみ。
  - fixture のテーマ・プラグインの探索結果ファイルをコミットし、`composer qa` と CI の qa が照合モードを実行する。探索対象だけを変更して生成物を更新しない場合に qa(CI を含む)が失敗することを確認した。
  - docs/02 §2〜§6 と docs/10 §4.6・§5 が §1.4 の方式に改訂され、docs/00〜11 と rules/ に探索キャッシュ(ComponentCache・`wp tmswp cache clear`・cacheDir)の記述が残っていない。
- 検証コマンド: `composer test:unit`、`composer analyse`、探索コマンドの生成と照合モードの実行、探索対象だけを変えた状態での `composer qa`(失敗すること)

#### v0.2.0-T2: Log・Support・ヘルパ

- 対象ファイル領域: packages/core/src/Log/、packages/core/src/Support/、packages/core/src/functions.php、starters/theme/functions.php・starters/plugin/plugin.php(ログ出力先の行のみ)、packages/core/tests/Unit/{LogTest,SupportTest}.php、docs/07 §2〜§5
- 完了条件:
  - ログのファイル名が推測できず、書き込み失敗は `error_log` へ必ず出て、初回だけ可視化される。ローテーションは自チャネルのファイルだけを数える。例外の previous チェーンを出し、改行をエスケープする。
  - URL 属性に `esc_url` を自動適用するか拒否する。
  - ヘルパに PHPDoc がある。
  - ヘルパを `function_exists` で囲んで定義しておらず、同名の関数が先に定義されている場合に黙ってそちらが使われない(明示エラーになる)。
- 検証コマンド: `composer test:unit`、`composer analyse`

#### v0.2.0-T3: Label・PHPStan 拡張

- 対象ファイル領域: packages/core/src/Label/、packages/core/src/Post/PostTypeDefinition.php・packages/core/src/Tax/TaxonomyDefinition.php(`label()` とラベル組み立ての部分)、packages/core/phpstan/、packages/core/tests/Unit/PHPStan*.php・EntityDefinitionTest.php(ラベル部分)、packages/core/tests/Integration/MetaIntegrationTest.php(メタキーをクラス定数に戻す)、docs/03 §2.2、docs/04 §4.18、`label()` の呼び出し元(packages・starters・examples・docs・packages/core/rules の呼び出しの行)、tests/fixtures/themes/tmswp-fixture-theme/app/ の `label()` の呼び出し元(Post/{MetaPost,ProductPost,StoredPost}.php・Acf/AcfStoredPost.php・Tax/ProductCategoryTaxonomy.php)、packages/core/tests/Unit/MetaSchemaRegistryTest.php(宣言済みメタキーの定数化)、packages/core/tests/Integration/EntityRegistrationIntegrationTest.php(ラベルのキーとコアの照合)、packages/core/tests/Support/PHPStanFixtureAutoloader.php(新設)、tools/verify-rules-code.php(解析の tmpDir の削除)
- 完了条件:
  - 英語ラベルが複数形で、docs/03 §2.2 の全キーを出す。
  - MetaSchemaReflector が ReflectionProvider で実装され、解析中に利用者のコードを実行しない。MetaBag の型拡張が `list<T>` を返す。
  - 統合テストのメタキーをクラス定数で書いても解析が通る。
- 検証コマンド: `composer test:unit`(PHPStan ルール・型推論のテストを含む)、`composer analyse`

#### v0.2.0-T4: Container・例外階層

- 対象ファイル領域: packages/core/src/Container/、packages/core/src/Exception/、packages/core/src/Attribute/{Action,Filter}.php、packages/core/src/Kernel/UnsupportedComponentInitializer.php(削除)、packages/core/src/ 内の例外を投げる箇所と `Container::get()` の呼び出し元、他パッケージの例外(packages/acf-bridge/src/Exception/・packages/thumbnail/src/Exception/)と `Container::get()` の呼び出し元(packages/acf-bridge/src/{AcfBridge,Cli/AcfCommand}.php・packages/settings-page/src/OptionFormBridge.php)、packages/core/tests/Unit/{ContainerTest,ExceptionHierarchyTest,PHPStanTypeInferenceTest}.php、packages/core/tests/fixtures/phpstan/container-type-inference.php、`Container::get()` の戻り値を確かめていたテスト(packages/core/tests/Unit/MetaSchemaRegistryTest.php・packages/core/tests/Integration/{OptionIntegrationTest,ModelIntegrationTest,KernelBootIntegrationTest}.php)、docs/02 §8・§9・受け入れ基準、docs/03 §1.7、docs/04 §4.5・§4.17、docs/06 R4、docs/07 §6、docs/09(パッケージ構成の例外の基底)、docs/README.md(DECISION の一覧)
- 完了条件:
  - 全例外が TMSWP 共通のマーカー interface を実装し、使われていない例外クラスと初期化クラスが無い。
  - `Container::get()` に `@template` の戻り値型があり、呼び出し側の instanceof の定型コードが無い。`has()` と factory 例外のラップが仕様どおり。
- 検証コマンド: `composer test:unit`、`composer analyse`、`grep -rn "Unsupported\|StorageException" packages/core/src`(0 件)

#### v0.2.0-T5: Kernel の起動とフック呼び出し

- 対象ファイル領域: packages/core/src/Kernel/{Kernel,KernelConfig,KernelContext}.php、packages/core/src/Kernel/{HookMethodKind,DiscoveredHookMethod,Discovery,DiscoveryFile}.php(フックメソッドの種類と、`#[Filter]` の登録時の引数の数。探索結果ファイルの schema を 4 にする)、packages/core/src/Hook/、packages/core/phpstan/(`Kernel::reset()` のルール。src/{KernelResetCallChecker,KernelResetOutsideTestRule,KernelResetStaticCallableOutsideTestRule,KernelResetMethodCallableOutsideTestRule}.php・extension.neon)、packages/core/tests/Unit/{KernelTest,KernelConfigTest}.php、packages/core/tests/Unit/DiscoverCommandTest.php(boot が使う `$wp_version`・`$wp_actions` の用意)、packages/core/tests/Unit/{DiscoveryTest,DiscoveryFileTest}.php(フックメソッドの種類)、packages/core/tests/Unit/{PHPStanKernelResetOutsideTestRuleTest,PHPStanKernelResetStaticCallableOutsideTestRuleTest,PHPStanKernelResetMethodCallableOutsideTestRuleTest}.php・packages/core/tests/fixtures/phpstan/kernel-reset-rule.php・packages/core/tests/Support/PHPStanFixtureAutoloader.php(ルールのテスト)、packages/core/tests/Integration/KernelBootIntegrationTest.php、tests/fixtures/themes/tmswp-fixture-theme/(app/Hook/CoercionProbeHook.php と探索結果ファイル)、tests/fixtures/plugins/tmswp-fixture-plugin/tmswp-discovery.php(schema 4 で作り直し)、.php-cs-fixer.dist.php(`native_function_invocation` から `call_user_func_array` を除外)、docs/02 §2・§3・§4・§5・§6・§7・受け入れ基準、docs/00〜11 の `WP_DEBUG` を直接参照する記述(担当の版が決まっているものを除く。docs/06 §5・docs/11)、docs/01 §5.2・§5.3(`wp_trigger_error()` の用途)、docs/08(SettingsPageInitializer の例外の扱いの記述 2 か所)
- 完了条件:
  - フックメソッドを `call_user_func_array` で呼び、数値文字列を `int` 引数に渡すフックが通る(統合テスト)。
  - Kernel が探索結果ファイルから起動し、フックメソッドの失敗をエラー方針(`00-overview.md` §5.6)で隔離する。WP 7.0 未満を明示エラーで拒否する。
  - debug の判定が `KernelConfig::$debug` に一本化され、既定値は `WP_DEBUG` の真偽から取る(§1.7)。
  - docs/00〜11 に残る `WP_DEBUG` の直接参照の記述を、担当の版が決まっているもの(DR-19 と View 関連は v0.5.0-T1、acf-bridge は v0.6.0-T1、settings-page・thumbnail・ja-toolkit は v0.7.0-T1〜T3)以外すべて `KernelConfig::$debug` による判定に揃えている。
  - キャッチアップがライフサイクル順で、rewrite の flush で毎リクエストのクエリが増えず、無効化時にルールが残らない。`Kernel::reset()` の本番での使用を禁じる PHPStan ルールがある。
- 検証コマンド: `composer test:unit`、`composer test:integration`、`composer analyse`

#### v0.2.0-T6: 拡張登録・版照合・ラベル言語の既定

- 対象ファイル領域: packages/core/src/Kernel/{Kernel,KernelConfig}.php、packages/core/src/Log/(Kernel ごとのチャネル)、packages/core/src/Post/PostTypeDefinition.php・packages/core/src/Tax/TaxonomyDefinition.php(ラベル言語の既定の参照部分)、packages/core/rules/meta.md・rules/acf.md(例)、packages/core/README.md(利用条件)、docs/00・docs/03・docs/11(例)、docs/01 §3.3、docs/02 §3.2、docs/07 §5、packages/core/src/Kernel/{ExtensionInterface,InstalledVersionCheck,PsrInterfaceCheck}.php(新設)、packages/core/src/Log/CallerFile.php(新設。Log の呼び出し元の判定)、packages/core/src/functions.php(`tms_log()`)、packages/core/src/Post/{PostType,PostTypeInitializer}.php・packages/core/src/Tax/{Taxonomy,TaxonomyInitializer}.php(`register()` に Kernel を渡す)、packages/core/tests/Unit/{KernelTest,EntityDefinitionTest,LogTest}.php、packages/core/tests/Unit/{KernelExtensionTest,InstalledVersionCheckTest,PsrInterfaceCheckTest,LogFacadeTest,CallerFileTest}.php(新設)、packages/core/tests/Support/{RecordingExtension,RecordingLogger}.php(新設)、packages/core/tests/fixtures/psr/(新設。古い psr の interface)、packages/core/tests/Integration/KernelBootIntegrationTest.php、packages/thumbnail/tests/Unit/ThumbnailTest.php(Log のグローバルの差し替え API の削除に伴う変更)、tests/fixtures/_wp-cli.php(コメント)、tests/fixtures/themes/tmswp-fixture-theme/{functions.php,parts/log-probe.php}・tests/fixtures/plugins/tmswp-fixture-plugin/{tmswp-fixture-plugin.php,templates/log-probe.php}、starters/theme/functions.php・starters/plugin/plugin.php・examples/sample-site/functions.php(ログの設定の行)、docs/01 §6・§9・§10(ヘルパのガードの記述)・§7.1(ラベル言語)、docs/02 §2・§3・受け入れ基準・利用例、docs/04・docs/09(日本語ラベルの例)、docs/11 §8.4・§9.3(ログの出力先)、docs/01 §4.5(リリース手順の `Kernel::VERSION` の更新)、docs/12 v0.7.0-T1・T2・v0.9.0-T4(完了条件の追加)
- 完了条件:
  - `KernelConfig` で拡張を Kernel ごとに登録でき、拡張がこの入口だけで有効化される仕組みがある(各拡張の移行は v0.6.0・v0.7.0)。
  - `Kernel::VERSION` があり、起動時に installed.php と照合して食い違いを検出する(統合テスト)。
  - 拡張は自分を登録した Kernel の debug を受け取る(§1.7)。
  - ログのチャネルが Kernel ごとに分かれ、呼び出し元の Kernel で出力先が決まる(`Log::set` の後勝ちが無い)。他のプラグイン(または WP-CLI の phar)が先に読み込んだ psr/log・psr/container の非互換を起動時に検出して明確なエラーにし、利用条件を packages/core/README.md と docs/01 に記している(§1.8)。
  - ラベル言語の既定を `KernelConfig` で指定でき、例がそれに合っている(§1.13)。
- 検証コマンド: `composer test:unit`、`composer test:integration`、`php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.2.0-T7: 受け入れテストの網羅

- 対象ファイル領域: packages/core/tests/Unit/{KernelTest,ContainerTest,DiscoveryTest,DiscoveryFileTest,DiscoverCommandTest}.php、packages/core/tests/Integration/(Kernel 関連)
- 完了条件: docs/02 の受け入れ基準の 1 項目ごとにテストがあり、全セルで通る。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.2.0-T8: 受け入れ確認と記録

- 対象ファイル領域: docs/acceptance/v0.2.0.md
- 完了条件: §0.3 の共通受け入れ条件と、この版の受け入れ条件を確認して記録する。
- 検証コマンド: `composer qa`(CI の 4 セル)

### 並列化

- 順序: {T1 ∥ T2} → T3 → T4 → T5 → T6 → T7 → T8
- T1 と T2 はファイル領域が重ならない(Kernel/ の Discovery 系・qa.yml・ルートの composer.json・tests/fixtures・docs/00・01・02・04・10・11/Log・Support・docs/07)。T3 は docs/04 を T1 と共に触るため T1 の後に置く。T5 の phpstan/(`Kernel::reset()` のルール)は T3 の後なので重ならない。
- T4 は src 全体の例外を触るため単独。T5・T6 は Kernel.php を共有するため逐次。

### 受け入れ条件

1. 本番相当(debug=false)で、探索結果ファイルが無いと起動時にエラーになり、ファイルがあれば走査しない(統合テスト)。探索結果ファイルが最新でないと qa が失敗する(§1.4)。
2. テーマとプラグインの 2 つの Kernel が同じサイトで起動でき、TMSWP の版の食い違いを起動時に検出する(統合テスト)。
3. docs/02 の受け入れ基準がすべてテストで確認されている。
4. unit と integration の合算カバレッジが §0.7 の表のこの版の閾値(61%)以上である(CI の `coverage` ジョブ)。composer.json の `test:coverage:check` の閾値は、この版の値と完了済みの版の値のうち最大のものになっている(§0.7)。

---

## v0.3.0 — エンティティ・メタ・オプション・検索

### 目的

Post/Tax/User/Query/Meta/Option の WP との結合部の欠陥を直し、WpBridge を廃止し、Query 層を enum で書き直し、メタ・設定の使い分け(§1.9)のコア側(`#[MetaSchema]` と `MetaBag` を単純な値の唯一の経路にする)を実装する。

### 依存する版

v0.2.0

### タスク

#### v0.3.0-T1: メタ・オプション

- 対象ファイル領域: packages/core/src/Meta/、packages/core/src/Option/、packages/core/src/Attribute/{MetaSchema,OptionSchema,UseMetaStorage,WithMetaRevisions,AsOptionGroup}.php、packages/core/tests/Unit/{MetaBagTest,MetaSchemaDefinitionTest,MetaSchemaRegistryTest,OptionRepositoryTest,ValueCasterTest}.php、docs/04、packages/core/rules/meta.md、rules/options.md
- 完了条件:
  - メタの書き込みでバックスラッシュと JSON 文字列が往復する(統合テスト)。option の null は `set` で拒否し、永続化しない。
  - 登録の拒否・非公開定数・enum ケース以外への `#[OptionSchema]`・書き込み失敗・型の不一致が例外になる。`'all'` のマジック文字列が無い。
  - docs/04 と rules に §1.9 の使い分け(単純な値は `#[MetaSchema]` と `MetaBag`、ACF の画面で編集する場合は `#[UseMetaStorage(AcfMetaStorage::class)]`)が書かれている。
- 検証コマンド: `composer test:unit`、`composer test:integration`、`php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.3.0-T2: エンティティ定義とファサード

- 対象ファイル領域: packages/core/src/Post/(PostQuery.php・PostTypeInitializer.php を除く)、packages/core/src/Tax/(TermQuery.php・TaxonomyInitializer.php を除く)、packages/core/src/User/(UserQuery.php・UserRoleInitializer.php を除く)、packages/core/tests/Unit/EntityDefinitionTest.php、docs/03 §1〜§6・§8〜§9・§11、packages/core/rules/post-type.md、rules/taxonomy.md
- 完了条件:
  - Thumbnail が `Support\Html` の属性レンダラを使い、存在しない builtin のアダプタ先は一律に例外、ロールの差分が通知される。
  - キャッチアップ時も builtin taxonomy の再試行が走り、実行時のデータ不整合がエラー方針(`00-overview.md` §5.6)どおりの例外になる。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.3.0-T3: メタ登録の boot 時への移動

- 対象ファイル領域: packages/core/src/Kernel/Kernel.php(エンティティ登録部分)、packages/core/src/{Post,Tax,User}/*Initializer.php、packages/core/src/Meta/MetaSchemaRegistry.php、探索コマンド(メタスキーマを探索結果に含める部分)、docs/02 §7、docs/04 §4.9
- 完了条件:
  - `registerEntity` が `Kernel::boot` 内で探索結果ファイル経由で行われ、init 前の `meta()` が使え、app の `#[Action('init')]` から `meta()` が使える(統合テスト)。
  - `UseMetaStorage` と `WithMetaRevisions` が探索結果ファイル経由でも保持される。PostType → UserRole の順が保証される。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.3.0-T4: WpBridge の廃止

- 対象ファイル領域: packages/core/src/Entity/WpBridge.php(削除)、WpBridge の呼び出し箇所(Post/PostType.php、Post/*Definition.php、Tax/Taxonomy.php、Tax/TermQuery.php、Tax/*Definition.php、User/UserQuery.php、User/UserRole.php、User/*Definition.php、Meta/MetaSchemaRegistry.php)、docs/03(toArgs の記述)
- 完了条件: WpBridge が無く、各 `toArgs()` が stubs と同じ配列形の PHPDoc を返し、WP 関数を直接呼んで PHPStan level max を通る。
- 検証コマンド: `composer analyse`、`grep -rn "WpBridge" packages`(0 件)、`composer test:unit`、`composer test:integration`

#### v0.3.0-T5: Query 層の書き直し

- 対象ファイル領域: packages/core/src/Query/、packages/core/src/Post/PostQuery.php、packages/core/src/Tax/TermQuery.php、packages/core/src/User/UserQuery.php、packages/core/src/Post/PostType.php(terms)、packages/core/src/Tax/Taxonomy.php(url)、packages/core/src/Entity/EntityReference.php、packages/core/tests/Unit/EntityQueryTest.php、docs/03 §1.3・§1.5・§7・§10・§13
- 完了条件:
  - エンティティクエリ 3 種が Compare / CastType / SortOrder を受け取り、QueryValidator が無い。
  - 空の IN 指定で 0 件が返り、存在しない FQCN が例外になる。
  - docs/03 の `WP_Error` の扱いを `00-overview.md` §5.6 の方針表に揃え(debug 時は例外、本番は Kernel のロガーに `error` で記録したうえで既定値)、実装もそれに従う。
  - noPaging / offset / page<1 / perPage=0 / 未知の operator / posts_per_page 未指定の扱いが仕様として明示され、テストで固定されている。
- 検証コマンド: `composer test:unit`、`composer test:integration`、`grep -rn "QueryValidator" packages`(0 件)

#### v0.3.0-T6: 受け入れテストと rules

- 対象ファイル領域: packages/core/tests/Integration/(エンティティ・メタ・オプション)、packages/core/tests/Unit/(Entity・Query・Meta)、packages/core/rules/{post-type,taxonomy,meta,options,user-role}.md
- 完了条件:
  - docs/03・04 の受け入れ基準 1 項目につき 1 テストがあり、boot は init 前に行う。
  - rules/ に設計で指定された事項が揃い、rules/user-role.md がある。
  - 対象領域(エンティティ・メタ・オプション・検索)を再点検して、件数のみ判明している軽微な不具合(2 件)を特定し、すべて対処した。
- 検証コマンド: `composer test:integration`、`php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.3.0-T7: 受け入れ確認と記録

- 対象ファイル領域: docs/acceptance/v0.3.0.md
- 完了条件: §0.3 の共通受け入れ条件と、この版の受け入れ条件を確認して記録する。
- 検証コマンド: `composer qa`(CI の 4 セル)

### 並列化

- 順序: {T1 ∥ T2} → T3 → T4 → T5 → T6 → T7
- T1 と T2 はファイル領域が重ならない(Meta・Option と docs/04/Post・Tax・User と docs/03)。
- T3 は MetaSchemaRegistry と Initializer、T4・T5 は Post・Tax・User・Meta を横断するため逐次。T3 の Kernel.php と docs/02 は §0.5 の順で他の版と調整する。

### 受け入れ条件

1. エンティティ・メタ・オプションの受け入れ基準がすべて統合テストで確認されている(init 前に boot)。
2. WpBridge と QueryValidator が無く、エンティティクエリと Model のクエリが同じ enum を使う。
3. 単純な値の読み書きの経路が `#[MetaSchema]` と `MetaBag` に一本化され、docs/04 と rules に書かれている(§1.9)。
4. unit と integration の合算カバレッジが §0.7 の表のこの版の閾値(65%)以上である(CI の `coverage` ジョブ)。composer.json の `test:coverage:check` の閾値は、この版の値と完了済みの版の値のうち最大のものになっている(§0.7)。

---

## v0.4.0 — Form

### 目的

Form のユーザー入力に起因する致命的エラー、ファイルのライフサイクル、ログ、レシピを直し、フォームの標準機能(§1.12。使い捨ての送信トークン、ページキャッシュからの除外、スパム対策の差し込み口、メール送信の手本)を実装する。

### 依存する版

v0.2.0

### タスク

#### v0.4.0-T1: 状態機械・送信トークン・キャッシュ・スパム対策

- 対象ファイル領域: packages/core/src/Form/{Form,ConfirmToken,ConfirmTokenResult,TokenStatus,TokenSigner,SubmitToken,SubmitTokenResult,JtiStore,JtiClaim,SpamGuardInterface,FormDefinition,FormResult,FormAction,FormStep}.php、packages/core/tests/Unit/{FormRuntimeTest,ConfirmTokenTest,FormDefinitionTest}.php、docs/05(§handle・§二重送信・§ConfirmToken・§JtiStore・テンプレート前処理の節)、docs/00 DR-07
- 完了条件:
  - 確認画面の有無に関係なく使い捨ての送信トークンで二重送信を防ぎ、確認画面ありの場合は ConfirmToken の jti に一本化されている(§1.12 の 1)。
  - フォームのあるページで `nocache_headers()` と `DONOTCACHEPAGE` が自動で付く(§1.12 の 2)。スパム対策を差し込む仕組みがあり、Turnstile と honeypot の手本コードがある(§1.12 の 3)。
  - JtiStore の DB エラーは例外、CSRF 失敗・改ざん・再送拒否・ファイル消失・onComplete の例外が Log に残る。
  - onComplete で起きた例外は、debug 時は例外として投げられ、本番は Kernel のロガー(`$kernel->logger()`)に `error` で記録されて汎用の送信失敗エラーがフォームに出て、JTI が消費されず再送できる(統合テスト)。
  - docs/05 のテンプレート前処理の節が明示呼び出し方式(§1.10)だけになっている。ウィザード形式(4 画面以上)は対象外と明記されている。
- 検証コマンド: `composer test:unit`、`composer test:integration`、`composer analyse`

#### v0.4.0-T2: リクエスト取り込みと描画

- 対象ファイル領域: packages/core/src/Form/{FormRequest,FieldView,FormHtml,OptionView,Field,FormDefinition}.php、packages/core/src/Form/Form.php(open・取り込み(bind)・確認画面の値処理)、packages/core/tests/Unit/FormViewAndFileTest.php(描画部分)・FormRuntimeTest.php(取り込み)・FormDefinitionTest.php、packages/core/tests/Integration/FormIntegrationTest.php(受け入れ条件3)、packages/core/languages、docs/05(§FormRequest・§Field・§FormDefinition・§Form::open・§bind()・§FieldView・§確認画面・§FormHtml)
- 完了条件:
  - 数値キーや配列のファイル項目の POST で例外が出ず、フィールドエラーになる。
  - `open()` の action と `input()` の id が反映され、上書きできない属性の指定は例外。パスワード項目が再描画・確認画面・トークンに出ない。確認画面での表示の指定がある。
  - FormHtml が `function_exists` で `esc_html` の有無を確かめて別のエスケープに切り替える分岐を持たず、`esc_html()` を直接呼ぶ。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.4.0-T3: ファイル

- 対象ファイル領域: packages/core/src/Form/{TempFileStore,StoredFile,UploadedFile}.php、packages/core/src/Form/Form.php(確認画面なしのフローのファイル受け渡し)、docs/05(§UploadedFile/StoredFile/TempFileStore)
- 完了条件:
  - フロントの onComplete から `toAttachment` が成功する(統合テスト)。
  - 未消費の StoredFile が Complete 後に消え、WP-Cron で `gc()` が定期実行される。確認画面の有無で onComplete が受け取る型と操作が揃っている。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.4.0-T4: 検証とルール

- 対象ファイル領域: packages/core/src/Form/{Validator,Messages,ValidationContext}.php、packages/core/src/Form/Rule/、packages/core/src/Form/Composite/、packages/core/tests/Unit/FormValidationTest.php(ルール部分)、docs/05(§Validator・§Rule・§CompositeField)
- 完了条件: Composite のパーツと単一チェックボックスが検証され、メッセージの解決が Messages に一本化され、docs/05 の CompositeField の節が実装どおり。Messages と Rule/ に、`function_exists` で WordPress の関数(`apply_filters`・`size_format` 等)の有無を確かめて黙って別の処理に切り替える分岐が無い。
- 検証コマンド: `composer test:unit`

#### v0.4.0-T5: フォームのレシピ

- 対象ファイル領域: packages/core/rules/form.md、packages/core/rules/form-mail.md(新設)
- 完了条件: form.md が 13 項目・3 画面・PRG(明示呼び出し方式。リダイレクト直後の `exit` は `00-overview.md` 原則1の例外として書く)の完全な例になり、form-mail.md(§1.12 の 4)がある。両方のコード例が抽出・検査を通る。
- 検証コマンド: `php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.4.0-T6: 受け入れテスト

- 対象ファイル領域: packages/core/tests/Unit/Form*.php、packages/core/tests/Integration/(Form を新設)、packages/core/tests/Support/(WpdbTestDouble 等)、packages/core/tests/Integration/EntityFacadeIntegrationTest.php(サムネイルの解決のテスト)、docs/10 §8.1(Form の行と 21b)
- 完了条件:
  - docs/05 の受け入れ基準の各項目にテストがあり、nonce・TempFileStore の実 IO・toAttachment・JtiStore の実 DB が統合テストで確認されている。fixture のソース文字列が英語(§1.13)。
  - docs/10 §8.1 の Form の行(5・7・9〜18)の移植先が実在するテストのファイルを指し、その行の「移植する仕様」がテストにある。21b(サムネイルの解決)の「GD 画像生成 + WP ファクトリの作法を踏襲」が、テストの実際の作り方と一致している(v0.3.0 の時点では、これらの Form の行の移植先のファイルが無く、21b の EntityFacadeIntegrationTest は GD で画像を生成していない)。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.4.0-T7: 受け入れ確認と記録

- 対象ファイル領域: docs/acceptance/v0.4.0.md
- 完了条件: §0.3 の共通受け入れ条件と、この版の受け入れ条件を確認して記録する。
- 検証コマンド: `composer qa`(CI の 4 セル)

### 並列化

- 順序: T1 → T2 → T3 → T4 → {T5 ∥ T6} → T7
- T1〜T4 は docs/05 を共有し、T1〜T3 は Form.php も共有するため逐次。
- T5(rules/)と T6(tests/ と docs/10 §8.1)はファイル領域が重ならない。T6 の tests/Support は v0.5.0-T6 と、T6 の docs/10 は v0.5.0-T1 と、§0.5 の順で調整する。

### 受け入れ条件

1. 確認画面の無いフォームでも、リロードと二重クリックで onComplete が再実行されない(統合テスト)。
2. フォームのあるページのレスポンスに nocache のヘッダと `DONOTCACHEPAGE` が付く(統合テスト)。
3. ユーザー入力(数値キー、配列のファイル、未知のトークン項目)で致命的エラーにならない。
4. form.md と form-mail.md のコード例が抽出・検査を通る。
5. unit と integration の合算カバレッジが §0.7 の表のこの版の閾値(68%)以上である(CI の `coverage` ジョブ)。composer.json の `test:coverage:check` の閾値は、この版の値と完了済みの版の値のうち最大のものになっている(§0.7)。

---

## v0.5.0 — Model・Migrator・テンプレート前処理

### 目的

Model の不具合を直し(§1.14)、Migrator を `wp tmswp migrate` 中心に作り直し(§1.5)、テンプレート前処理を明示呼び出し方式(§1.10)に置き換える。

### 依存する版

v0.2.0

### タスク

#### v0.5.0-T1: テンプレート前処理の置き換え

- 対象ファイル領域: packages/core/src/View/{ActionRouter,View,NotFound}.php(削除)、packages/core/src/functions.php(tms_var)、packages/core/phpstan/src/Actions/(削除)、packages/core/phpstan/extension.neon、packages/core/bin/tms-check(削除)、packages/core/src/Kernel/Kernel.php(ActionRouter の登録)、packages/core/tests/(View・ActionRouter・NoOutputRule・NoSet404Rule・tms-check のテスト)、starters/*/{actions,phpstan-actions.neon,composer.json}、examples/sample-site/{actions,phpstan-actions.neon,page-contact.php,composer.json}、packages/core/rules/{actions,view}.md、明示呼び出し方式のレシピ(rules/ に新設)、phpstan.neon.dist(actions 関連の設定)、docs/00 DR-19・§5.2・§7(View 関連)、docs/02(ActionRouter の登録)、docs/07 §1A、docs/10 §3A
- 完了条件:
  - ActionRouter・View 変数バッグ・`tms_var`・NoOutputRule・NoSet404Rule・tms-check とそのテスト・設定・文書が無い。
  - DR-19 と View 関連の記述(docs/00 の DR-19・モジュール表・用語集、docs/02・07・10)を明示呼び出し方式に置き換え、`WP_DEBUG` の直接参照を残さない(判定は登録元 Kernel の `KernelConfig::$debug`)。
  - テンプレートの先頭で型付きクラスを呼び readonly DTO を受け取る方式のレシピが rules/ にあり、抽出・検査を通る。sample-site の page-contact.php がこの方式になっている。
  - packages/core/rules/actions.md が、旧来の `actions/<template>.php` と `tms_var()` の方式を説明しておらず、明示呼び出し方式(§1.10)と食い違わない(v0.4.0 の時点では、actions.md が旧来の方式のままで、v0.4.0 の rules/form.md・form-mail.md と docs/05 の明示呼び出し方式と食い違う)。
- 検証コマンド: `grep -rnE "ActionRouter|tms_var|tms-check|NoOutputRule|NoSet404Rule|phpstan-actions" packages starters examples docs`(0 件)、`composer analyse`、`composer test:unit`、`php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.5.0-T2: Connection と QueryBuilder の不具合修正

- 対象ファイル領域: packages/core/src/Model/{Connection,QueryBuilder,SqlQuery}.php、packages/core/tests/Unit/ModelLayerTest.php(Connection・QueryBuilder 部分)、docs/06 §1・§4
- 完了条件:
  - `insert` / `update` / `delete` の値が docs/06 の値の正規化規約(R3)で正規化され、NULL を代入でき、`value()` / `pluck()` が既存の SELECT 句を保ち、grouped count が ORDER BY / LIMIT / OFFSET を破棄する。
  - `%%`・プレースホルダの個数・tableForWrite・whereIn・pluck の欠損が仕様どおりか例外になる。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.5.0-T3: Model ファサードと TableDefinition と PHPStan 拡張

- 対象ファイル領域: packages/core/src/Model/{Model,TableDefinition,Identifier,ModelInitializer}.php、packages/core/phpstan/src/(Model 用の拡張を新設)、packages/core/phpstan/extension.neon、packages/core/tests/Unit/PHPStan*.php(Model 部分)、docs/06 §2・§3
- 完了条件:
  - `rowClass()` の既定が stdClass で、`find()` / `first()` が行を返し、Model に行用のコンストラクタを置く使い方が封じられている。
  - Model のシグネチャが docs/06 どおり。予約語のカラム名と collation だけの指定が宣言時に例外になる。`as()` / `rowClass()` の戻り値型が PHPStan で絞られる。
  - どの Kernel にも属さない Model クラスの接続解決は `fromGlobal()` に切り替わらず例外になり、Kernel の外では `useConnection()` で明示する。docs/06 からこの暗黙の切り替えの記述が消えている(§1.14)。
- 検証コマンド: `composer test:unit`、`composer test:integration`、`composer analyse`

#### v0.5.0-T4: Migrator の作り直し

- 対象ファイル領域: packages/core/src/Model/{Migrator,MigrationResult,MigrationStatus,MigrateCommand}.php、packages/core/src/Kernel/Kernel.php(Migrator の配線と、フックのメソッドの失敗のログ)、packages/core/tests/Integration/ModelIntegrationTest.php(Migrator 部分)、packages/core/tests/Unit/KernelStorageTest.php(`$wpdb` の無い boot)、packages/core/tests/Unit/KernelTest.php(フックのメソッドの失敗のログ)、docs/06 §5・§7(Kernel 統合契約の `listen()` の呼び出し)、docs/02(マイグレーションの契機)
- 完了条件:
  - `wp tmswp migrate` で適用し、dbDelta の後に `lastError()` と残った差分を確認し、失敗した場合はハッシュを更新しない。
  - テーブル定義のハッシュが DB と食い違うと、権限のある管理者に `admin_notices` で通知する。起動時・admin_init・ajax・cron で自動適用しない(§1.5)。
  - `action` が enum で、シグネチャと出力が docs/06 どおり。
  - docs/06 §5・§7 の `listen()` のシグネチャと呼び出しの記述が実装と一致している(v0.2.0 の時点では、文書が `listen(bool $isTheme, ?string $pluginFile = null)`、実装が `listen(Kernel $kernel)`)。
  - Migrator が `WP_DEBUG` を直接参照せず、`listen()` に渡した Kernel の `KernelConfig::$debug` で判定する(§1.7)。対象: packages/core/src/Model/Migrator.php の 161 行(v0.2.0 の時点の行)。
  - Kernel がフックのメソッドの例外を本番で記録するとき、ログの文脈に例外のオブジェクト・メッセージ・トレースを入れず、v0.4.0 の Form と同じく、例外のクラス名と例外が投げられた場所(ファイル:行)だけを入れる(previous はクラス名だけ)。フックのメソッドはアプリのコードで、例外のメッセージに利用者の入力値や秘密が入りうるためである。対象: packages/core/src/Kernel/Kernel.php の 613〜618 行(`'exception' => $exception`。v0.4.0 の時点の行)。
  - WordPress なしのユニットテストで Kernel を boot するときに、グローバルの `$wpdb` を要さない。v0.3.0 の時点では、boot の中の Migrator の配線(Kernel.php の `registerModelMigrator()`)が `Connection::fromGlobal()` を解決するため、アプリのユニットテストは `$wpdb` の代役を置く必要がある(rules/meta.md・options.md の「テスト」の例)。`$wp_version`(WordPress 7.0 未満の拒否)と `$wp_actions`(発火済みのフックの判定)は boot の仕様として残す。
- 検証コマンド: `composer test:integration`、`node node_modules/@wordpress/env/bin/wp-env run cli wp tmswp migrate`、`composer test:unit`

#### v0.5.0-T5: Template

- 対象ファイル領域: packages/core/src/View/{Template,TemplateLocator}.php、packages/core/tests/Unit/(Template 部分)、docs/07 §1
- 完了条件: Template が include するファイルからクラスの private にアクセスできず、`Template::part` が `get_template_part` 系のフックを発火する。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.5.0-T6: Model のテストとレシピ

- 対象ファイル領域: packages/core/tests/Unit/ModelLayerTest.php、packages/core/tests/Support/WpdbTestDouble.php、packages/core/tests/Integration/ModelIntegrationTest.php、packages/core/rules/model.md
- 完了条件: Model・Migrator の受け入れ基準を網羅し、WpdbTestDouble が実 wpdb と同じ振る舞い(オブジェクト値の扱い等)をし、model.md に設計で指定された事項と `wp tmswp migrate` による実行方法(§1.5)がある。
- 検証コマンド: `composer test:unit`、`composer test:integration`、`php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.5.0-T7: 受け入れ確認と記録

- 対象ファイル領域: docs/acceptance/v0.5.0.md
- 完了条件: §0.3 の共通受け入れ条件と、この版の受け入れ条件を確認して記録する。
- 検証コマンド: `composer qa`(CI の 4 セル)

### 並列化

- 順序: {T1 ∥ T2} → {T3 ∥ T5} → T4 → T6 → T7
- T1 と T2 はファイル領域が重ならない(View・phpstan・Kernel.php・starters・sample-site・docs/00・02・07・10/Model/ の Connection・QueryBuilder と docs/06)。
- T3 は T1(phpstan/)と T2(docs/06)の後。T5(View/ の Template と docs/07)は T1 の後で、T3 と重ならない。T4 は T3(docs/06)の後。
- T1 と T4 の Kernel.php・docs/02、T1 の docs/00・docs/10 は §0.5 の順で他の版と調整する。

### 受け入れ条件

1. 空でないテーブルで `find()` / `first()` / `get()` が行を返し、DateTime・enum・NULL が正しく保存される(統合テスト)。
2. マイグレーションは `wp tmswp migrate` だけで適用され、食い違いは管理者に通知され、自動適用されない。
3. actions/ 規約の痕跡(コード・設定・テスト・文書)が無い。
4. unit と integration の合算カバレッジが §0.7 の表のこの版の閾値(72%)以上である(CI の `coverage` ジョブ)。composer.json の `test:coverage:check` の閾値は、この版の値と完了済みの版の値のうち最大のものになっている(§0.7)。

---

## v0.6.0 — acf-bridge

### 目的

生成アクセサが型どおりの値を返し、PHPStan level max を通り、実物の ACF で動くようにする。メタ・設定の使い分け(§1.9)の ACF 側(AcfMetaStorage の修正)と、Kernel ごとの拡張登録(§1.6)の acf-bridge 側を実装する。

### 依存する版

v0.3.0(v0.2.0 を含む)

### タスク

#### v0.6.0-T1: Kernel ごとの登録と CLI

- 対象ファイル領域: packages/acf-bridge/src/{AcfBridge,BridgeConfig}.php、packages/acf-bridge/src/Cli/、packages/acf-bridge/src/Manifest/、packages/core/src/Meta/MetaSchemaRegistry.php・packages/core/tests/Unit/MetaSchemaRegistryTest.php(外部管理の申告の引数と、重複の例外のメッセージ)、packages/core/rules/extensions.md(acf-bridge の登録例)、docs/09 §1.4(パッケージ構成の例外)・§2・§5.4・§7・§8.2・§9、docs/00 DR-06、docs/04 と packages/core/rules/{meta,acf}.md(ACF で編集する単純な値の「v0.6.0 までの境目」の説明)
- 完了条件:
  - acf-bridge が `KernelConfig` 経由で Kernel ごとに登録され、テーマとプラグインの両方で使える(統合テスト)。CLI に `--app=` がある。save_json が登録元の acf-json に向く。
  - acf-bridge の debug による分岐が、登録した Kernel の debug に従う(§1.7)。
  - E101 の静的検査が Generator の managedKeys と共通で、options の post_id を実行時に決めず、黙殺の経路がエラー方針どおりになっている。BridgeConfig の入力を検証する。
  - ACF が無効なときの扱い(管理キーのマーク、CLI のオプションページの解決)がエラー方針(`00-overview.md` §5.6)に沿って docs/09 に定められ、`function_exists` で黙って処理を飛ばす経路が残っていない。
  - docs/09 §1.4 のパッケージ構成の図の `Exception/` が、packages/acf-bridge/src/Exception/ の例外クラスと一致している(v0.2.0 の時点では、AcfBridge・BridgeConfig・AcfCommand が投げる `AcfBridgeRuntimeException` が図に無い)。
  - ACF のフィールドグループで管理するキーを、`#[MetaSchema]` と `#[UseMetaStorage(AcfMetaStorage::class)]` で宣言できる(§1.9)。外部管理の登録(`markExternallyManaged` 系)と衝突せず、`DuplicateMetaKeyException` にならない(統合テスト)。
  - docs/04 の「メタ・設定の使い分け」と rules(meta.md・acf.md・extensions.md)の「v0.6.0 までの境目」の説明を、v0.6.0 の完了後の形に改め、docs/09 §7.3・§8.2 の重複検出と推奨の記述、`00-overview.md` DR-06 の「PHP 手書き宣言との同一キー重複はエラー」をこの契約に揃えている。
  - 重複として例外にする場合、その例外のメッセージが docs/09 §7.3 のとおり、衝突したキー・(ObjectType, サブタイプ)・宣言クラスに加えて、`#[MetaSchema]` の定数名と ACF のフィールドグループの key を含む(v0.3.0 の時点では、外部管理の申告の API がグループの key を受け取らないため、定数名とグループの key を含まない)。
  - CLI(packages/acf-bridge/src/Cli/AcfCommand.php)が、WP-CLI の API を `['WP_CLI', ...]` の callable を組み立てて呼ばず、core の DiscoverCommand・MigrateCommand と同じく `WP_CLI::` で直接呼ぶ(v0.3.0 の時点では、`halt()` と `callWpCli()` が callable を組み立てて呼んでいる)。
- 検証コマンド: `composer test:unit`、`composer test:integration`、`php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.6.0-T2: 実行時アクセス(AcfFieldAccess と AcfMetaStorage)

- 対象ファイル領域: packages/acf-bridge/src/{AcfFieldAccess,AcfMetaStorage,AcfTypes}.php、packages/acf-bridge/tests/Integration/AcfBridgeIntegrationTest.php、packages/acf-bridge/tests/Integration/AcfMetaStorageContractTest.php(新設)、tests/fixtures/themes/tmswp-fixture-theme/(ACF のフィールドグループ)、docs/09 §6・§8・§10
- 完了条件:
  - 未設定の正規化と形の不一致(debug は例外、本番は Kernel のロガーに `error` で記録)が区別され、gallery は要素単位で検査される。
  - `has()` がメタの存在で判定し、AcfMetaStorage の getAll / setAll / delete が WpMetaStorage と同じ意味になる。DateTime が `wp_timezone()` で往復する。
  - AcfMetaStorage が docs/04 §4.5 の実装者への規約(書き込みの戻り値は「書き込んだか」で、同じ値なら false。データベースのエラー等の失敗は例外。`null` は保存しない)を満たし、packages/core/tests/Support の `MetaStorageContractTestCase`(データベースに依存しない契約は trait `MetaStorageContract`)による契約テストを、実物の ACF の統合テストで通す(v0.3.0 の時点では、この規約を満たしていない)。
  - 実物の ACF で、ACF 管理外・管理下の両方のキーについて、MetaBag 経由の削除と `has()` が正しく動くことを統合テストで確認する。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.6.0-T3: 生成コードの型と正規化

- 対象ファイル領域: packages/acf-bridge/src/Generate/{TypeMapper,TypeMapping,Generator,GeneratorContext,AccessorDefinition,NameConverter}.php、packages/acf-bridge/src/AcfMetaStorage.php(acfId 変換)、packages/acf-bridge/tests/fixtures/expected-app/、packages/acf-bridge/tests/GeneratedApp/、phpstan.neon.dist(excludePaths)、docs/09 §5・§6
- 完了条件:
  - 全 31 型の組み合わせの fixture から生成したコードが PHPStan level max を通り、生成物を手書きで代用した箇所が無い。
  - 複合型は入れ子を含めて正規化され、オブジェクト型は要素ごとに instanceof で検査され、get / update / has が field key を使う。
  - `of(0)` 等の ID<1 が例外になり、予約語と helper 名の衝突が生成時エラーになる。
- 検証コマンド: `composer analyse`、`composer test:unit`、`composer test:integration`

#### v0.6.0-T4: acf-json の読み込みと解決

- 対象ファイル領域: packages/acf-bridge/src/Json/、packages/acf-bridge/src/Mapping/、packages/acf-bridge/src/Generate/{CloneResolver,Report,TargetBucket,GeneratedFile}.php、packages/acf-bridge/bin/(tms-acf を新設)、packages/acf-bridge/composer.json(bin)、docs/09 §3・§4・§11
- 完了条件:
  - jsonDirs の並び順で先勝ちになり、配列形式の JSON と非配列の JSON が報告され、LocationResolver が正しい候補に解決し、post_template に対応する。
  - cloneの展開(保存名・ラベル・cloneのcloneの名前・管理キー)を実物のACF Proで確認して揃える。cloneが合成するkeyの定義はメモリ上だけにありACFに登録されないため、生成アクセサ(KEY_定数)と管理キーは元のkeyと保存名を使う。docs/09にある未実装の機能(I002、checkのdiff、W002、E101の文言、型エイリアス)がある。
  - WP を起動しない `vendor/bin/tms-acf` の sync / check があり、page_template の単一ターゲットはテンプレート別のクラスになる。
  - location の block は非対応と明記され、独自ブロックは §1.11 の方式を案内している。
- 検証コマンド: `composer test:unit`、`vendor/bin/tms-acf check`(fixture で実行)、`composer test:integration`

#### v0.6.0-T5: プレビュー時の ACF 値

- 対象ファイル領域: packages/acf-bridge/src/PreviewAcfSupport.php、packages/acf-bridge/tests/Unit/PreviewAcfSupportTest.php、docs/09 §8.3
- 完了条件: 実物の ACF でプレビュー値の標準保存の有無と nonce の消費順を確認して記録し、その結果に応じて PreviewAcfSupport を削除するか、nonce を消費しない検査とプレビューの autosave に限定した形に直している。残す場合は、ACF の関数が無いときに `function_exists` で黙って保存を飛ばす経路の扱いがエラー方針に沿って決まっている。
- 検証コマンド: `composer test:integration`(プレビュー保存の統合テスト)

#### v0.6.0-T6: acf-bridge のテスト

- 対象ファイル領域: packages/acf-bridge/tests/、docs/09 §14(テスト方針)
- 完了条件:
  - 無料版 ACF にある型について、正規化、key の解決、マーク、E101、UseMetaStorage が CI の統合テストで確認され、CLI のテストがある。全ユニットテストが UnitTestCase を継承する。
  - マークの振り分け(ACF の location の値 `all` のキーを `markExternallyManagedInAllSubtypes()` に、それ以外のキーを `markExternallyManaged()` に渡すこと)を acf-bridge のテストで確かめる(v0.3.0 の時点では、packages/acf-bridge/src/AcfBridge.php のこの振り分けに acf-bridge 側のテストが無い)。
  - Pro専用の型(repeater、flexible_content、gallery、clone)と他のPro限定機能のテストは、ACF Proのzipのパスを環境変数`TMSWP_ACF_PRO_ZIP`で渡したときだけ手元で走り(`composer test:integration:acf-pro`)、その結果を記録している(§1.2)。実物のACF Proでの最終確認はv0.9.0-T1で行う。
  - 対象領域(acf-bridge)を再点検して、件数のみ判明している軽微な不具合(1 件)を特定し、すべて対処した。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.6.0-T7: acf-bridge の rules と README

- 対象ファイル領域: packages/acf-bridge/rules/、packages/acf-bridge/README.md、packages/core/rules/acf.md
- 完了条件: acf.md と acf-bridge の rules に、flexible の match イディオム、acf-json と生成物の同時コミットと `check --strict`、禁止事項、`value()`、§1.9 の使い分けがあり、コード例が抽出・検査を通る。
- 検証コマンド: `php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.6.0-T8: 受け入れ確認と記録

- 対象ファイル領域: docs/acceptance/v0.6.0.md
- 完了条件: §0.3 の共通受け入れ条件と、この版の受け入れ条件を確認して記録する。
- 検証コマンド: `composer qa`(CI の 4 セル)

### 並列化

- 順序: T1 → T2 → T3 → T4 → T5 → {T6 ∥ T7} → T8
- T1〜T5 は docs/09 を共有するため逐次。T6(tests/ と docs/09 §14)と T7(rules/・README)はファイル領域が重ならない。
- T1 の rules/extensions.md、T3 の phpstan.neon.dist は §0.5 の順で他の版と調整する。

### 受け入れ条件

1. 全 31 型の組み合わせの fixture から生成したコードが PHPStan level max を通る(静的解析)。
2. 無料版 ACF にある型は、CI の統合テストで型どおりの値を返す。
3. Pro専用の型は、ACF Proのzipのパスを環境変数`TMSWP_ACF_PRO_ZIP`で渡した手元の実行(`composer test:integration:acf-pro`)で型どおりの値を返したことが記録されている(実物のACF Proでの最終確認はv0.9.0-T1)。
4. テーマとプラグインの両方で acf-bridge を使える。
5. 偽の ACF を使うテストが無い。
6. unit と integration の合算カバレッジが §0.7 の表のこの版の閾値(75%)以上である(CI の `coverage` ジョブ)。composer.json の `test:coverage:check` の閾値は、この版の値と完了済みの版の値のうち最大のものになっている(§0.7)。

---

## v0.7.0 — settings-page・thumbnail・ja-toolkit

### 目的

3 つの拡張パッケージの不具合を直し、Kernel ごとの拡張登録(§1.6)の各拡張側(`KernelConfig` 経由の登録への移行)を実装する。

### 依存する版

v0.2.0、v0.4.0、v0.6.0

### タスク

#### v0.7.0-T1: settings-page

- 対象ファイル領域: packages/settings-page/src/、packages/settings-page/languages/、packages/core/src/Form/Form.php(applyDefaults の呼び出し側との整合のみ。v0.4.0 の結果に合わせる)、docs/08 §2
- 完了条件:
  - 権限の無いユーザーで管理画面が落ちない(統合テスト)。FW が外枠(open・_step・submit・close)とエラー通知を出し、docs/08 §2.3 の例が実在の API で動く。
  - persist がスキーマの型で検証・キャストし、不一致は例外。gettext のソース文字列が英語で ja 訳があり、`wp_die()` が 403 で返る。
  - `OptionFormBridge::applyDefaults()` が、option の値を Form のフィールドの既定値の形(文字列。multiple の項目は文字列の list)に変換してから `Form::applyDefaults()` に渡し、int・bool の option を結んだ設定ページが例外にならない(統合テスト)。v0.4.0 で `Form::applyDefaults()` は、`handle()` の後の呼び出し(`LogicException`)、フォームに無い項目(`UnknownFieldException`)、文字列以外の値とファイルの項目(`FormConfigException`)を例外にした。v0.4.0 の時点では、`OptionFormBridge::applyDefaults()` が `OptionRepository::get()` の mixed をそのまま渡すため、int・bool の option を結ぶと `FormConfigException` になる(persist の向きのキャストは前の条件)。
  - docs/08 §2 の例が、v0.4.0 の Form の API の名前だけを使う(v0.4.0 の時点では、テンプレートの例に `renderHiddenFields()` が残っている)。
  - `KernelConfig` 経由の登録になり、`definition()` の評価が init 以降。debug による分岐が登録した Kernel の debug に従う(§1.7)。
  - 拡張の内部のログは、静的な `Log` ではなく、登録した Kernel のロガー(`$kernel->logger()`)に記録する(§1.8)。対象: packages/settings-page/src/OptionFormBridge.php の 36・41・65・70 行(`Log::debug`。v0.2.0-T6 の時点の行)。docs/08 の 555 行(v0.2.0-T6 の時点の行)を、Kernel のロガーへの記録の説明に揃える。
  - 保存後の `wp_safe_redirect()` 直後の `exit` が、`00-overview.md` 原則1の例外の範囲に限られている。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.7.0-T2: thumbnail

- 対象ファイル領域: packages/thumbnail/src/、packages/thumbnail/README.md(利用条件)、docs/08 §1
- 完了条件:
  - 拡大要求と恒久的な失敗が毎回の再生成を起こさず、劣化時の 302 が長期キャッシュされない。生成は一時ファイル+rename とキー単位のロックで行う。
  - アイキャッチ未設定は例外にならず、正常な結果として返る(エラー方針)。出力形式の変更・`%` を含む src・GC・署名の区切りと鍵が仕様どおり。
  - `WP_DEBUG` を直接見る箇所が無く、登録した Kernel の debug に従う(§1.7)。
  - REST API が使えることが利用条件として docs/08 と README に明記され、自分の REST ルートが無効化されていると起動後に検出して管理画面で管理者に通知する(§1.15、統合テスト)。REST 以外の経路は無い。
  - `KernelConfig` 経由の登録になり、制約(外部ストレージ非対応等)が docs/08 に書かれている(§1.3)。
  - 拡張の内部のログは、静的な `Log` ではなく、登録した Kernel のロガー(`$kernel->logger()`)に記録する(§1.8)。対象: packages/thumbnail/src/Thumbnail.php の 44 行(`Log::warning`)、packages/thumbnail/src/RestController.php の 150・158・167 行(`Log::error`)(v0.2.0-T6 の時点の行)。packages/thumbnail/tests/Unit/ThumbnailTest.php の暫定の Kernel の起動(`bootKernelContainingThumbnail()`。packages/thumbnail をルートにして静的な `Log` を記録させる形)をやめる。docs/08 の 145・195・861 行(v0.2.0-T6 の時点の行)を、Kernel のロガーへの記録の説明に揃える。
  - `tms_thumb` を `function_exists` で囲んで定義しておらず、同名の関数が先に定義されている場合に黙ってそちらが使われない(明示エラーになる)。
  - ログの文脈に例外のオブジェクト・メッセージ・トレースを入れず、v0.4.0 の Form と同じく、例外のクラス名と例外が投げられた場所(ファイル:行)だけを入れる(previous はクラス名だけ)。利用者の入力値や秘密が本番のログに出るのを防ぐためである。対象: packages/thumbnail/src/Thumbnail.php の 44 行(`['exception' => $exception]`。v0.4.0 の時点の行)。
  - CLI(packages/thumbnail/src/Cli/ThumbCommand.php)が、WP-CLI の API を `['WP_CLI', ...]` の callable を組み立てて呼ばず、core の DiscoverCommand・MigrateCommand と同じく `WP_CLI::` で直接呼ぶ(v0.3.0 の時点では、callable を組み立てて呼んでいる)。
- 検証コマンド: `composer test:unit`、`composer test:integration`(実画像の生成)

#### v0.7.0-T3: ja-toolkit

- 対象ファイル領域: packages/ja-toolkit/src/、packages/ja-toolkit/languages/、packages/ja-toolkit/tests/Unit/KanaTextFieldTest.php、docs/08 §3
- 完了条件:
  - KanaTextField だけを使っても訳が出る(統合テスト)。KanaTextField のメッセージが遅延解決され、正規表現と正規化が直り、弾かれるケースのテストがある。
  - JapaneseSlug が許可リストの投稿タイプだけを対象にし、format を検証し、一意性を再確認する。全角スペースだけの検索の扱いが決まっている。
  - `KernelConfig` 経由の登録になり、登録が原子的。debug による分岐が登録した Kernel の debug に従う(§1.7)。
  - `function_exists` で WordPress の関数(`did_action` 等)の有無を確かめて検査を黙って飛ばす分岐が無い。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.7.0-T4: 拡張パッケージのテスト

- 対象ファイル領域: packages/settings-page/tests/、packages/thumbnail/tests/、packages/ja-toolkit/tests/(KanaTextFieldTest.php を除く)
- 完了条件: docs/08 の受け入れ基準(CSRF、権限の拒否、PRG、REST の実応答、CLI、二重登録、FullwidthSearchNormalizer 本体)がユニット・統合テストで確認され、`/tmp` のハードコードが無い。
- 検証コマンド: `composer test:unit`、`composer test:integration`

#### v0.7.0-T5: 拡張の登録例と rules

- 対象ファイル領域: packages/core/rules/extensions.md
- 完了条件: extensions.md の登録例が `KernelConfig` 経由の方式で、サムネイルの例が許可されたキーだけを使い、コード例が抽出・検査を通る。
- 検証コマンド: `php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.7.0-T6: 受け入れ確認と記録

- 対象ファイル領域: docs/acceptance/v0.7.0.md
- 完了条件: §0.3 の共通受け入れ条件と、この版の受け入れ条件を確認して記録する。
- 検証コマンド: `composer qa`(CI の 4 セル)

### 並列化

- 順序: T1 → T2 → T3 → {T4 ∥ T5} → T6
- T1〜T3 は docs/08 を共有するため逐次。T4(各パッケージの tests/)と T5(rules/extensions.md)はファイル領域が重ならない。
- T5 の rules/extensions.md は v0.6.0-T1 の後(版の依存で逐次)。

### 受け入れ条件

1. 4 つの拡張(acf-bridge を含む)の起動 API が `KernelConfig` 経由の登録に統一され、旧 API が残っていない。
2. 管理者以外のユーザーでの管理画面、アイキャッチ未設定の投稿、KanaTextField の単独利用が統合テストで正常に動く。
3. unit と integration の合算カバレッジが §0.7 の表のこの版の閾値(78%)以上である(CI の `coverage` ジョブ)。composer.json の `test:coverage:check` の閾値は、この版の値と完了済みの版の値のうち最大のものになっている(§0.7)。

---

## v0.8.0 — ブロック・スターター・配布導線・AI 向け文書

### 目的

独自ブロックの自動登録(§1.11)を作り、スターターと配布物と examples/sample-site と AI 向け文書を、v0.2.0〜v0.7.0 で確定した API に合わせて作り直す。

### 依存する版

v0.2.0〜v0.7.0

### タスク

#### v0.8.0-T1: 独自ブロックの自動登録

- 対象ファイル領域: packages/core/src/Block/(新設)、packages/core/src/Kernel/(Kernelのブロックの登録、KernelConfigのブロックカテゴリ、Discovery・DiscoveryFile・DiscoveryResult・DiscoverCommand等の探索系によるblock.jsonの走査と探索結果ファイルへの出力)、packages/core/src/Post/PostTypeDefinition.php(`allowedBlocks()`)、packages/core/src/Post/PostType.php(`allowed_block_types_all`への反映)、packages/core/tests/(ブロック関連)、tests/fixtures/(ブロックを含む fixture テーマ。tests/fixtures/themes/tmswp-fixture-theme/のblocks/とbuild/blocks/、fixtureのテーマとプラグインの探索結果ファイル`tmswp-discovery.php`)、docs/02、docs/03、packages/core/rules/blocks.md(新設)
- 完了条件:
  - 探索コマンドが `build/blocks/**/block.json`(入れ子可)を走査して同じ探索結果ファイルに出力し、実行時に `register_block_type()` で登録する(統合テスト)。
  - 名前の重複と未登録のカテゴリが探索コマンドのエラーになる。`allowedBlocks()` が `allowed_block_types_all` に反映される。カテゴリを `KernelConfig` で登録できる。
  - ブロックの探索結果も v0.2.0-T1 の照合(§1.4)に含まれ、fixture テーマの block.json だけを変更して生成物を更新しない場合に qa(CI を含む)が失敗する。
  - ブロックテーマと ACF Blocks 向けのコード生成が対象外であることと、npm のビルドと探索コマンドの連結方法が rules/blocks.md にある。
- 検証コマンド: `composer test:unit`、`composer test:integration`、`php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.8.0-T2: スターター

- 対象ファイル領域: starters/(.php-cs-fixer の設定を除く。starters/*/composer.jsonのlicenseを含む)、docs/11 §5(スターターのAGENTS.mdの文案)・§6.2(レシピのコード例の名前空間)・§10(§10.1の手順と§10.2のsetup.phpの全文)、ルートの.wp-env.json(スターター由来のプロジェクトのマウント)、tests/Integration/(新設。スターター由来のプラグイン2本とテーマの同時起動の統合テスト)、phpunit.integration.xml.dist(tests/Integration/のtestsuiteの追加)、docs/10 §4.5・§4.7(phpunit.integration.xml.distと.wp-env.jsonの完全版)
- 完了条件:
  - setup.php がスラッグから名前空間を生成し、composer.json の psr-4・name(`local/<slug>`)、本体ファイル、app/、AGENTS.md を書き換え、post-create-project-cmd を除去して自己削除し、次の手順を案内する(docs/11 §10.2)。
  - スターターのラベル言語の既定が日本語(§1.13)。テーマスターターに blocks/ と、npm のビルドと探索コマンドの連結がある(§1.11)。探索結果ファイルの生成がビルド手順にあり、各スターターの探索結果ファイル(`tmswp-discovery.php`)を生成してコミットしている。setup.php が名前空間を書き換えた後に探索結果ファイルを作り直す(または作り直す手順を案内する)(§1.4)。
  - スターター由来のプラグイン 2 本とテーマを同じサイトで起動できる(統合テストまたは wp-env 上の確認)。
  - スターターの `composer qa` が探索結果ファイルの照合(§1.4)を含む。CLAUDE.md を置いていない(§1.18)。
  - docs/11 §10のsetup.phpの記述(§10.2の全文と説明)を、starters/theme/bin/setup.phpとstarters/plugin/bin/setup.phpの実物に合わせ、両者が一致している。v0.6.0の時点では、docs/11 §10.2の全文はスラッグの正規化・対話での入力・composer.jsonのnameの`local/<slug>`への書き換え・自己削除を含むが、starters/theme/bin/setup.phpとstarters/plugin/bin/setup.phpはどれも行わず、プレースホルダの置き換え(プラグインは本体ファイルの名前の変更も)だけを行う。
  - docs/11 §10.1が、setup.phpがスラッグから名前空間を生成してcomposer.jsonのpsr-4・本体ファイル・app/・AGENTS.mdを書き換えること(§1.6)を述べ、「名前空間の置換は行わない」とする記述が無い。v0.7.0の時点では、docs/11の891行が「名前空間の置換は行わない(テーマスターターは`App\`、プラグインスターターは`PluginApp\`固定が既定)」としており、§1.6と逆である。
  - starters/theme/composer.jsonとstarters/plugin/composer.jsonのlicenseがGPL-2.0-or-later(packages/*と同じ)であり、setup.phpを実行して作ったプロジェクトのcomposer.jsonのlicenseが`proprietary`である。docs/11 §10がこのlicenseの書き換えを述べている。starters/theme・starters/pluginにはLICENSEのファイルを置かず、licenseはcomposer.jsonのフィールドだけで示す(LICENSEのファイルを置くのはpackages/*だけ。v0.8.0-T3)。v0.7.0の時点では、両スターターのcomposer.jsonのlicenseは`proprietary`で、starters/theme/bin/setup.phpとstarters/plugin/bin/setup.phpはlicenseを書き換えない。
- 検証コマンド: スターターから create-project 相当で 2 つ生成して `composer qa`、wp-env 上でフロントが 200 を返すことの確認

#### v0.8.0-T3: 配布物の整備

- 対象ファイル領域: packages/*/composer.json、packages/*/phpunit.xml.dist(新設)、packages/*/.gitattributes(新設)、テスト支援コード(packages/core/tests/Support の切り出し先)、packages/core/tests/Support/UsesTempDirectories.php(一時ディレクトリの隔離。切り出す場合は切り出し先)、CHANGELOG.md(新設)、monorepo-builder.php、docs/01(§3.1のスターターの名前空間、§8.1の641行付近の`App\`の共存の記述を含む)、docs/10 §5〜§6、docs/RELEASING.md、ルートのcomposer.json(autoload-dev。テスト支援コードの切り出しに合わせる)、phpunit.unit.xml.dist、docs/10 §1.1・§4.3(ルートのcomposer.jsonとphpunit.unit.xml.distの完全版)、starters/*/composer.json(`composer monorepo:validate`に要る`tmswp/core`の制約の整合)、packages/*/LICENSE(新設)
- 完了条件:
  - 各パッケージを単独で clone して `composer install && composer test` が通る(require-dev と phpunit の設定がある)。export-ignore で tests や fixture が配布物に入らない。.DS_Store が混入しない。
  - 受け入れ条件2(各パッケージを単独でテストできる)を、split相当の手順で確かめている。各パッケージをsplit相当で一時ディレクトリに取り出し(`git subtree split --prefix=packages/<pkg>`のコミットのcloneなど、export-ignoreを適用しない取り出し方)、tmswp/coreを要るパッケージでは、同じくsplit相当で取り出したcoreを、その一時ディレクトリのcomposer.jsonに足したpathリポジトリ(`versions`に`Kernel::VERSION`の版)で解決して、`composer install && composer test`が成功する。tmswp/coreはPackagistに未公開のためこの手順にし、リポジトリのpackages/*/composer.jsonにはpathリポジトリを足さない。
  - packages/core/src/に.DS_Storeを置いた状態で、pathリポジトリ(`symlink: false`)でpackages/*を入れた一時のプロジェクトで`composer install`を実行し、`find vendor/tmswp -name .DS_Store`が0件である(Composerのpathリポジトリの写しが従うのは、各パッケージの.gitattributesのexport-ignoreだけのため、除外はexport-ignoreで行う。composer.jsonの`archive.exclude`は`composer archive`の生成の設定で、pathリポジトリの写しには効かない)。v0.7.0の時点では、packages/core/src/・packages/acf-bridge/src/・packages/ja-toolkit/src/等に.DS_Storeがあり(.gitignoreの対象のためリポジトリには無い)、examples/sample-siteのpathリポジトリの写しに入る。
  - packages/*とstarters/*のcomposer.jsonの`tmswp/core`の制約と、packages/core/composer.jsonの`branch-alias`が、`Kernel::VERSION`の版を満たす形で揃い、`composer monorepo:validate`が成功する。v0.7.0の時点では、`Kernel::VERSION`は`0.2.0`(packages/core/src/Kernel/Kernel.phpの36行)だが、packages/core/composer.jsonのbranch-aliasは`0.1.x-dev`で、4つの拡張と2つのスターターの`tmswp/core`の制約は`^0.1`(0.2.0を満たさない)である。
  - CHANGELOG.md があり、docs/01 のスターターの名前空間の記述が §1.6 どおり。docs/01の641行(v0.7.0の時点)の、同じサイトの複数のコンポーネントがそれぞれ`App\`を使っても各vendor/のオートローダが自身の`App\`を解決するため衝突しないとする記述が無く、同じ名前のクラスはオートローダの先勝ちで後のものが隠れることと、プラグインの名前空間はcreate-projectで生成すること(§1.6)を述べている。
  - packages/*のそれぞれにGPL-2.0-or-laterのLICENSEがあり、split相当で取り出した各パッケージにLICENSEが入っている。v0.7.0の時点では、LICENSEはリポジトリのルートにだけあり、split.ymlが送るpackages/*のディレクトリには無い。
  - docs/RELEASING.mdの手順が、`vendor/bin/monorepo-builder release`の実際の動作と一致する。v0.7.0の時点では、monorepo-builder.phpのリリースのワーカー(TagVersionReleaseWorker・PushTagReleaseWorker・PushNextDevReleaseWorker)により、手順3の`monorepo-builder release`がmainへのpushとタグのpushを自ら行うが、docs/RELEASING.mdはそれを書かずに手順4で`git push origin main --tags`を指示している。mainへのpushとタグ付けは毎回ユーザーに確認するため(v0.9.0-T4)、どの手順でpushが起きるかを手順に書く。
  - テストの一時ディレクトリを、テストごとに専用の親ディレクトリの下に作り、`KernelConfig` が `dirname(appDir)` に書く探索結果ファイル(`tmswp-discovery.php`)もその中に収まる。テストの後に、探索結果ファイルを含めて片付ける。PHP 8.3 と 8.5 の unit を並列に実行しても干渉しない(v0.2.0 の時点では、UsesTempDirectories が `sys_get_temp_dir()` の直下に一時ディレクトリを作るため、探索結果ファイルが `sys_get_temp_dir()` の直下に書かれて残り、並列の実行で `KernelTest::testInitializerWithoutInterfaceThrowsInProduction` が 1 件エラーになる)。
- 検証コマンド: 各パッケージを split 相当で取り出して `composer install && composer test`、`git archive HEAD packages/<pkg> | tar -t`(tests 等が含まれない)、`composer monorepo:validate`、PHP 8.3 と 8.5 の `vendor/bin/phpunit -c phpunit.unit.xml.dist` の並列実行(どちらも成功し、実行後の `sys_get_temp_dir()` の直下に `tmswp-discovery.php` が無い)

#### v0.8.0-T4: sample-site の作り直し

- 対象ファイル領域: examples/sample-site/(tmswp-kernel.php(新設)と、wp-envでsample-siteを動かす設定のexamples/sample-site/.wp-env.json(新設)を含む。sample-siteのテーマを有効にする環境は、fixtureのテーマを有効にして統合テストを走らせるルートの.wp-env.jsonの環境と分け、別のポートにする。tools/acf-pro-integration.shがACF Proの環境をポートで分けているのと同じ扱い)
- 完了条件:
  - sample-site が想定する用途の機能(フォーム、設定ページ、サムネイル、日本語対応、ACF、ブロック)を備え、README が sample-site 用で、lock と path リポジトリの扱いが決まり、`composer qa` が通り、wp-env 上で動く。探索結果ファイル(ブロックを含む)をコミットし、sample-site の `composer qa` が照合する(§1.4)。
  - sample-siteの依存が今のpackages/*から解決され、`composer validate`がlockの古さを報告しない(lockをコミットしない扱いにする場合は、lockを作る手順がREADMEにある)。v0.5.0の時点では、examples/sample-site/composer.lockは.gitignoreの対象(`/composer.lock`)でコミットされておらず、手元に残るlockの更新日は2026-07-05で、`composer validate`が「The lock file is not up to date with the latest changes in composer.json」を出す。lockのtmswp/core 0.1.0は`php: >=8.2`と`ext-mbstring`を要求し、今のpackages/core/composer.json(`php: >=8.3`、ext-mbstringなし)と食い違う。pathリポジトリが`symlink: false`のため、vendor/tmswp/*(.gitignoreの対象)もその時点のpackages/*の写しで、今のpackages/core/srcと一致しない。
  - sample-siteのcomposer scriptsが、acf-bridgeの生成と照合に`vendor/bin/tms-acf`(`tms-acf sync`・`tms-acf check`。docs/09 §5.1)を使い、`wp tmswp acf`の呼び出しが残っていない。v0.6.0の時点では、examples/sample-site/composer.jsonのscriptsの`acf:check`・`acf:sync`が`wp tmswp acf check`・`wp tmswp acf sync`のままである。
  - sample-siteが、thumbnail・settings-page・ja-toolkitを`KernelConfig`の`extensions`で登録し(docs/08のパッケージ共通規約の登録API)、旧APIの呼び出しが残っていない(`grep -rnE "Thumbnail::register|SettingsPages::register|JaToolkit::enable" examples/sample-site --exclude-dir=vendor`が一致なし)。v0.7.0の時点では、examples/sample-site/functions.phpが`extensions`にacf-bridgeだけを渡し、18〜22行で`Thumbnail::register()`・`SettingsPages::register()`・`JaToolkit::enable([...])`を静的に呼んでいる。v0.7.0では、`Thumbnail`・`SettingsPages`の`register()`は`ExtensionInterface`のインスタンスのメソッド(引数は`Kernel`)で、`JaToolkit`に`enable()`は無い。
  - examples/sample-site/composer.jsonのpathリポジトリの`versions`の`tmswp/core`の版が`Kernel::VERSION`と一致し、requireの制約がその版を満たし、wp-env上でsample-siteのKernelが`InstalledVersionCheck`の照合で例外にならずに起動する(フロントが200を返す)。v0.7.0の時点では、examples/sample-site/composer.jsonの52行の`versions`が`"tmswp/core": "0.1.0"`で、packages/core/src/Kernel/Kernel.phpの36行の`Kernel::VERSION`は`'0.2.0'`のため、installed.phpの`pretty_version`(0.1.0)と食い違い、起動時に`InstalledVersionCheck::assertInstalledVersions()`(Kernel.phpの138行)が`KernelException`を投げる。requireの`tmswp/core`の制約`^0.1`も0.2.0を満たさない。
  - examples/sample-site/tmswp-kernel.phpが`KernelConfig`を返し、functions.phpがそれを`KernelConfig::load()`で読んで起動し、sample-siteのルートで`vendor/bin/tms-acf check`が成功する(tms-acfはルートの`tmswp-kernel.php`の`KernelConfig`からacf-bridgeの設定を読む。packages/acf-bridge/src/Cli/TmsAcf.php)。v0.7.0の時点では、examples/sample-site/tmswp-kernel.phpが無く、functions.phpの7〜16行が`KernelConfig::theme()`を直接組み立てている。
  - examples/sample-site/.wp-env.jsonの環境とルートの.wp-env.jsonの環境を同時に起動でき、sample-siteのwp-envはルートのnode_modulesの`@wordpress/env`をパスで呼ぶ(コマンド名`wp-env`で解決しない)。
- 検証コマンド: `cd examples/sample-site && composer qa`、wp-env 上での表示確認

#### v0.8.0-T5: テスト構成の整理

- 対象ファイル領域: packages/*/tests/Unit/、docs/10 §8(移植対応表と新規テスト必須リストのテストのパス)
- 完了条件:
  - テストが src をミラーして配置され、docs/10 §8.1・§8.2 の対応表のデータプロバイダが移植されている。
  - docs/10 §8.1の移植先の列のテストのパスが、配置を直した後の実在のテストファイルを指す。v0.7.0の時点では、§8.1は移植先を`packages/core/tests/Unit/FormRuleTest.php`・`SupportTest.php`等のsrcをミラーしないファイル名で示している。
- 検証コマンド: `composer test:unit`

#### v0.8.0-T6: 開発基盤の残り

- 対象ファイル領域: tools/verify-rules-code.php、tools/extract-rules-code.php、bin/coverage-check.php、.github/workflows/qa.yml、.github/workflows/split.yml、.php-cs-fixer.dist.php、starters/*/.php-cs-fixer*、package.json・package-lock.json(@wordpress/env の版と、npm のインストールスクリプトの扱い)、docs/10 §1.1(package.json の写しの版)・§4.8(カバレッジゲート)・§5(ワークフローの完全版)、docs/01 §4.4(ワークフロー)、packages/core/src/Kernel/DiscoverCommand.php(照合したKernelの数)、tests/fixtures/_wp-cli.php(`theme_roots`に左右されないfixtureの読み込み)、tools/(PHPStanのワーカーごとのメモリの最大量を測る手順。新設)、docs/10 §2.2(計測の手順と上限の扱い)、composer.json(`analyse`)・phpstan.neon.dist(上限を超えそうな場合の解析の分割)、docs/10 §3(php-cs-fixerの設定の完全版と補足規律)・§9(開発環境セットアップ手順)、tests/Unit/CoverageCheckTest.php(閾値の判定の境界)、packages/core/tests/Unit/(DiscoverCommandの照合したKernelの数のテスト。T5の配置に従う)
- 完了条件:
  - rules の検査が全パッケージの rules/ を対象にし、docs/11 §6.3 どおりの検証をする。sample-site と starters の `composer qa` が CI にある。
  - starters/pluginのphp-cs-fixerの設定(starters/plugin/.php-cs-fixer.dist.php)が、setup.phpの実行の前と後のどちらでも、実在するファイルだけを参照する。v0.6.0の時点では、starters/plugin/.php-cs-fixer.dist.phpが`__DIR__ . '/plugin.php'`をappendしているが、setup.phpが本体ファイルを`<slug>.php`に名前を変えるため、スラッグが`plugin`以外のプロジェクトでは無いファイルを参照する。
  - tools/extract-rules-code.php が、namespace 付きのコード例のクラス名を `final readonly class`・`readonly class` の宣言からも取り出す(v0.4.0 の時点では、クラス名の正規表現が `final class`・`class`・`enum` だけに一致し、`final readonly class` のコード例は「クラス名がありません」の例外になる。v0.4.0 の rules/form-mail.md は `final class` と `public readonly` のプロパティで回避している)。
  - unit と integration の合算カバレッジが、6パッケージ(testingを含む)全体に加えてパッケージ別にも判定され(パッケージ別の基準値はこの版で決める)、docs/10 §4.8 がその方式に改訂されている。php-cs-fixer の設定が docs/10 §3.2・§7 項目4 どおり。
  - qa.yml と split.yml の外部アクションが Node.js 24 で動く版になり(CI に Node.js 20 の非推奨の警告が出ない)、SHA で固定されている(docs/10 §5.3)。外部アクションの実行時に出る Node.js の非推奨の警告(DEP0040 の `punycode`、DEP0169 の `url.parse()`。v0.2.0 の時点では、actions/setup-node・actions/cache・actions/upload-artifact・ramsey/composer-install の実行時に全ジョブで出ている)が、更新後の版で出ないことを確かめる。最新の版でも出る場合は、そのアクションと版を受け入れ記録に書く。
  - CI の `npm ci` の警告を解消する。v0.2.0 の時点では、`npm ci` を実行するジョブ(Integration の 4 セルと Coverage)で、`npm warn deprecated` の @octokit/webhooks-types@7.6.1・glob@10.5.0 と、fs-ext-extra-prebuilt@2.2.7 のインストールスクリプトが allowScripts で許可されていないという `npm warn install-scripts` が出ている(いずれも @wordpress/env 11.17.0 の依存)。@wordpress/env の更新か、インストールスクリプトの扱いの決定で解消し、最新の版でも出る警告は、そのパッケージと版を受け入れ記録に書く。全ジョブの runner の版が固定され、`ubuntu-latest` を使っていない。docs/10 §5 と docs/01 §4.4 のワークフローの完全版が実ファイルと一致する。
  - `composer discover:check`が、開発用サイトのsite transient `theme_roots`の状態に左右されず、照合したKernelの数(fixtureのテーマとプラグインの2つ)を確かめる。v0.5.0の時点では、fixtureのテーマの置き場(tests/fixtures/themes)を含まない`theme_roots`が開発用サイトに残っていると、fixtureのテーマが見つからずテーマのKernelが起動しないため、2つのKernelのうちプラグインの1つだけを照合して成功する(照合したKernelの数を確かめていない)。このtransientは、`--require=tests/fixtures/_wp-cli.php`の無いWP-CLIの実行や、開発用サイトへの通常のリクエストの後に、fixtureのテーマの置き場を含まない形で作られうる。
  - PHPStanのワーカーごとのメモリの最大量を測る手順を用意し、各版の受け入れ記録でその値と`composer analyse`の`--memory-limit`に対する余裕を記録する。上限を超えそうなら、解析の分割などで対処する。v0.5.0の時点では、ワーカーのメモリの最大量はCIと同じ4ワーカー・キャッシュなしで約1040MiB(1GiBを超える)で、当時の上限の1G(1024MiB)に達してCIのStatic Analysisが失敗したため、上限を2Gにした。packages/core/tests/Integration/とpackages/core/tests/Support/が入るジョブのワーカーが最も重い。
  - docs/10 §3.1のFinderの完全版と§3.2の補足規律が互いに一致し、.php-cs-fixer.dist.phpの実物とも一致する。v0.7.0の時点では、§3.1のFinder(docs/10の374行・380行)がstarters/の全体を対象にして`core/src/Exception`を除外するのに対し、§3.2(458行)はstartersの対象を`app/`のみとしている。
  - CIに、各スターターをcreate-project相当で作るジョブがある。スターターから一時ディレクトリにプロジェクトを作ってsetup.phpを非対話で実行し(tmswp/coreなどはpathリポジトリで解決する)、作ったプロジェクトに`{{`が残っていない(`grep -rn "{{" <プロジェクト> --exclude-dir=vendor --exclude-dir=node_modules`が一致なし)こと、プラグインの本体ファイルが`<slug>.php`に名前が変わり`plugin.php`が無いこと、そのプロジェクトの`composer qa`が成功することを確かめる。v0.7.0の時点では、qa.ymlにスターターのジョブは無く、starters/theme・starters/pluginの`{{`はsetup.phpの実行の前の状態でコミットされている。
  - CIで、テーマスターターのblocks/をNodeでビルドし(`npm ci`とビルドのスクリプト)、その出力から探索結果ファイルを照合する(§1.4・§1.11)。
  - bin/coverage-check.phpが、合算のカバレッジを丸めた値ではなく丸める前の値で閾値と比べ、閾値をわずかに下回る値(たとえば閾値78に対する77.995%)を不合格にする(tests/Unit/CoverageCheckTest.phpで固定する)。docs/10 §4.8の手順もそれに合わせる。v0.7.0の時点では、bin/coverage-check.phpが`round(..., 2)`で小数2桁に丸めた値を`$coverage < $minimum`で比べるため、77.995%は78.00%として閾値78を満たす。
  - docs/10 §9の開発環境セットアップ手順が、ルートのcomposer.jsonの`config.allow-plugins`と一致する。v0.7.0の時点では、composer.jsonの`allow-plugins`が`bamarni/composer-bin-plugin`をすでに許可している(133行)が、docs/10 §9の手順2はallow-pluginsの確認にyesと答えるよう案内し、トラブルシューティングの表はallow-pluginsでbamarniを拒否した場合を挙げている。
- 検証コマンド: `composer lint`、`php tools/extract-rules-code.php && php tools/verify-rules-code.php`、`composer test:coverage`、`grep -n "ubuntu-latest" .github/workflows/*.yml`(0 件)

#### v0.8.0-T7: rules・AGENTS.md・README の全面整備

- 対象ファイル領域: packages/*/rules/、packages/*/README.md、AGENTS.md、README.md、starters/*/AGENTS.md、starters/*/CLAUDE.md・examples/sample-site/CLAUDE.md(削除)、docs/11 §1.4・§12.1(CLAUDE.mdとAGENTS.mdの記述)、docs/11 §5・§6.2・§8・§9のうち、名前空間の自動生成(§1.6)・独自ブロック(§1.11)・CLAUDE.mdを置かないこと(§1.18)に合わせて要る箇所(スターターのAGENTS.mdの文案、レシピのコード例の名前空間、starter-themeとstarter-pluginのファイルツリーと全文)
- 完了条件:
  - rules/・AGENTS.md・README が v0.2.0〜v0.8.0 で確定した API を反映し、docs/11 が定める項目(配置決定木、コーディング規約、デバッグ節、探索結果ファイルの扱い等)を備え、コード例が抽出・検査を通る。CLAUDE.md を置いていない(§1.18)。
  - packages/core/rules に次の注意がある(v0.3.0 の時点では無い)。builtin の `post` のクラスに `#[WithMetaRevisions]` を付けるには、定義で `supports('revisions')` を指定する(`MetaSchemaRegistry` は定義の supports を検査するため、WordPress が `post` に付ける revisions では足りない)。attachment のアダプタのクラスの `query()` は、`status('inherit')` を指定しないと添付ファイルを返さない(PostQuery の既定の `post_status` は `publish`)。
  - rules/meta.md・options.md の「テスト」の例が、v0.5.0-T4 の後の boot に要るグローバルだけを置いている(v0.3.0 の時点では `$wpdb` の代役も置いている)。
  - docs/11 §12.1のCLAUDE.mdとAGENTS.mdの項目が実態と一致している。v0.5.0の時点では、§12.1(とdocs/11 §1.4、本計画§1.18)はCLAUDE.mdをmonorepo・スターターのどこにも置かないとするが、starters/theme・starters/plugin・examples/sample-siteに、`@AGENTS.md`の1行だけのCLAUDE.mdがコミットされている(monorepoのルートには無い)。また、§12.1はルートのAGENTS.mdを§4の内容とするが、ルートのAGENTS.mdは§4の全文案ではなく、monorepoの開発ルールの12行である。
- 検証コマンド: `php tools/extract-rules-code.php && php tools/verify-rules-code.php`

#### v0.8.0-T8: 受け入れ確認と記録

- 対象ファイル領域: docs/acceptance/v0.8.0.md
- 完了条件: §0.3 の共通受け入れ条件と、この版の受け入れ条件を確認して記録する。
- 検証コマンド: `composer qa`(CI の 4 セル)、sample-site と starters の qa(CI)

### 並列化

- 順序: T1 → T2 → T3 → T4 → T5 → T6 → T7 → T8
- T2・T3・T4は逐次にする。T2とT3は、starters/*/composer.json(T2のlicense、T3の`tmswp/core`の制約の整合。monorepo-builder.phpがpackages/とstarters/を`composer monorepo:validate`の対象にする)、tests/とphpunitの設定(T2のtests/Integration/とphpunit.integration.xml.dist、T3のテスト支援コードの切り出し先とphpunit.unit.xml.dist)、docs/10(T2の§4.5・§4.7、T3の§1.1・§4.3・§5〜§6)が重なる。T4はT2(ルートの.wp-env.jsonの環境と分けるsample-siteのwp-envの設定、スターターと同じ`tmswp-kernel.php`の形)とT3(sample-siteのpathリポジトリの版とrequireが、T3で揃える`tmswp/core`の制約と`branch-alias`に依存する。pathリポジトリの写しから.DS_Storeを除くのもT3)の結果を使う。T2とT4はT1のブロック登録を使うためT1の後。
- T5とT6も逐次にする。DiscoverCommandのテスト(T5がpackages/core/tests/Unit/の配置をsrcのミラーに直し、T6が照合したKernelの数の検査のテストを足す)と、docs/10(T5の§8、T6の§1.1・§2.2・§3・§4.8・§5・§9)が重なる。T5はT3(テスト支援の配置)、T6はT2・T4(CIに入れる対象)とT3(docs/01・docs/10 §5)の後。
- T7 は全 API の確定後に行う。T7のstarters/*/AGENTS.md・CLAUDE.mdはT2(starters/)、examples/sample-site/CLAUDE.mdはT4(examples/sample-site/)、docs/11 §1.4・§12.1・§8・§9はT2(docs/11 §10と同じファイル)、docs/11 §5・§6.2はT2の対象ファイル領域と重なるが、T7はT2・T4の完了後に着手するため並列にならない。
- T6のpackages/core/src/Kernel/DiscoverCommand.phpとtests/fixtures/_wp-cli.phpはT1(探索コマンド、tests/fixtures/)の対象ファイル領域と重なるが、T6はT1の完了後に着手する。

### 受け入れ条件

1. 独自ブロックがビルド → 探索 → 登録の流れで動き、名前の重複と未登録のカテゴリがビルド時エラーになる。
2. 各パッケージが単独でテストでき、配布物にテストや fixture が含まれない。
3. スターター由来のプラグイン 2 本とテーマが同じサイトで動く。
4. sample-site と starters の `composer qa` が CI で通る。
5. unit と integration の合算カバレッジが §0.7 の表のこの版の閾値(82%)以上である(CI の `coverage` ジョブ)。composer.json の `test:coverage:check` の閾値は、この版の値と完了済みの版の値のうち最大のものになっている(§0.7)。

---

## v0.9.0 — 導入できる版

### 目的

導入できる版の5条件(§1.17)を満たすことを確認し、公開して、利用者のプロジェクトから`composer require`で導入できる状態にする。

### 依存する版

v0.8.0

### タスク

タスクは、下の並列化の順序で並べる。T1〜T5の番号は、v0.9.0の計画の初版の番号のままにする(T0・T0b・T0c・T3a・T4aは、計画の補正で足したタスク)。

#### v0.9.0-T0: 計画の補正

- 対象ファイル領域: docs/12(§0.1・§1.1・§1.16と、この版の節)
- 完了条件:
  - この版の節が、版の準備(T0b)・v0.8.0からの引き継ぎ(T0c)・MySQL 8.4 LTSと9.7 LTS(T3a)・リリースの道具(T4a)のタスクと、T1〜T5の補正を含む。
  - v0.8.0の受け入れ記録(docs/acceptance/v0.8.0.md)の6章の引き継ぎ13項目を、次の表のとおりタスクに振り分けている。
  - §0.1の作業ブランチの記述が実態(作業は`rewrite/v0`で行い、`main`は初期のコミットだけを持つ)と一致する。
- 検証コマンド: `git diff HEAD --stat -- docs/12-implementation-plan.md`(T0の差分。並行のT0bの変更が作業ツリーにあるため、対象を限って確かめる。ほかのファイルの差分は、T0bの対象ファイル領域のものであることを照合する)、本書の中で`main`を検索し、作業ブランチを`main`とする記述が残っていないこと

| 引き継ぎの項目(docs/acceptance/v0.8.0.mdの6章) | 担当 |
|---|---|
| 1. スターターから作ったプロジェクトの`composer qa`がテストを1件も実行しない | T0c(a) |
| 2. スターターとsample-siteのphpunit.xml.distの設定が揃っていない | T0c(b) |
| 3. CHANGELOG.mdの見出しの形が揃わない | T0b(Unreleasedを`## v0.8.0 — 題名`に閉じる)、T4a(リリースの前の検査で見出しの形を確かめる)、T4(リリースの準備のコミットで`## v0.9.0 — 題名`にする) |
| 4. `TagVersionReleaseWorker`がmainへのpushの失敗を握りつぶす | T4a(標準のワーカーを外す) |
| 5. `npm audit`の残り | T3(E2Eの依存を足した後に測り直す)、T5(記録) |
| 6. `UsesTempDirectories`に専用のユニットテストが無い | T0c(c) |
| 7. v0.8.0-T1の確かめていない点 | 1つ目(未登録のカテゴリを探索コマンドを通して確かめるテスト)はT0c(d)。2つ目(fixtureのblock.jsonを実際に変えた`discover:check`の失敗)は、同じ経路をDiscoverCommandTest::`testCheckFailsWhenOnlyBlockJsonChanged`が確かめているため、対応しない。3つ目(metadataHashが空白・改行とrender.php・index.jsの変更を検出しない)は、完了条件の違反ではないため対応しない。2つ目と3つ目は、T5が受け入れ記録に扱いを書く |
| 8. CIのユニットテストのログにテストの名前が出ない | T2(`--testdox`) |
| 9. CIと手元でアサーションの数が4件違う | T0c(e) |
| 10. ルートのlockのPHPStanが2.2.2 | この版では上げないため対応しない。申し送りをT5が受け入れ記録に残す |
| 11. スターターの`tmswp-kernel.php`の`new SimpleLogger()` | 記録だけにする(v0.8.0の判断のまま)。T5が受け入れ記録に残す |
| 12. sample-siteのREADMEのサムネイルのURLの説明 | T2 |
| 13. ACF Proでのsample-site・E2E・公開・85% | T1・T2・T4・T3(全体の閾値を85にする) |

#### v0.9.0-T0b: 版の準備

- 対象ファイル領域: packages/core/src/Kernel/Kernel.php(`Kernel::VERSION`)、packages/*/composer.json・starters/*/composer.json(`tmswp/*`の制約とbranch-alias)、examples/sample-site/composer.json(requireとpathリポジトリの`versions`)、examples/sample-site/README.md(版の記述)、packages/*/README.md(版の例)、docs/01・docs/02・docs/08・docs/11(版の例)、CHANGELOG.md、手元のnode_modules(コミットしない)
- 完了条件:
  - `Kernel::VERSION`が`0.9.0`、packages/*・starters/*・examples/sample-siteの`tmswp/*`の制約が`^0.9`、packages/*のbranch-aliasが`0.9.x-dev`、sample-siteのpathリポジトリの`versions`が`0.9.0`である。これらを1つのコミットで変えている(`Kernel::VERSION`と制約を別のコミットで変えると、間のコミットでStarter ProjectsとSample Site QAのジョブが落ちるため)。
  - docs/01・02・08・11とpackages/*/README.md・examples/sample-site/README.mdの版の例が、上の版と一致する(`git grep -nE '\^0\.[1-8]\b|0\.[1-8]\.x-dev' -- docs/01-architecture.md docs/02-kernel.md docs/08-extensions.md docs/11-ai-docs.md 'packages/*/README.md' examples/sample-site/README.md`が一致なし)。
  - CHANGELOG.mdの今のUnreleasedの節を`## v0.8.0 — ブロック・スターター・配布導線・AI向け文書`に閉じ、新しいUnreleasedの節を開いている。冒頭の説明から、`monorepo-builder`がCHANGELOGを書き換えるとする記述が無く、リリースの準備のコミットで見出しを手で`## vX.Y.Z — 題名`に改めると書いている。
  - 手元のnode_modulesの`@wordpress/env`を、`npm ci`でpackage-lock.jsonの版(12.0.0)に揃えている(v0.8.0の後の時点では、手元のnode_modulesは11.17.0のまま)。
- 検証コマンド: `composer monorepo:validate`、`composer qa:fast`、`grep '"version"' node_modules/@wordpress/env/package.json`(12.0.0)、`cd examples/sample-site && composer update 'tmswp/*' && composer wp:start && composer qa`

#### v0.9.0-T0c: v0.8.0からの引き継ぎ

- 対象ファイル領域: starters/*/phpunit.xml.dist、starters/*/tests/Unit/Hook/(新設。例のユニットテスト)、starters/*/bin/setup.php(tests/の名前空間の書き換えが要る場合)、starters/*/AGENTS.md、examples/sample-site/phpunit.xml.dist、docs/10 §7、docs/11 §5(スターターのAGENTS.mdの全文案)・§10.2(setup.phpの全文。setup.phpを変えた場合)、tests/Unit/StarterSetupTest.php、tools/ci/create-starter-project.sh(要る場合)、packages/testing/tests/Unit/UsesTempDirectoriesTest.php(新設)、packages/core/tests/Unit/Kernel/DiscoverCommandTest.php。(e)・(f)の原因が分かって直す場合は、直すファイルを対象ファイル領域に足してから着手する(§0.1)
- 完了条件:
  - (a) starters/theme・starters/pluginのphpunit.xml.distに`failOnEmptyTestSuite="true"`がある。各スターターのtests/Unit/Hook/に、Brain Monkeyだけで書いた例のユニットテストが1つずつある(`app/Hook/ThemeSetupHook.php`・`app/Hook/PluginSetupHook.php`を対象にし、`tmswp/testing`を使わない)。スターターからsetup.phpで作ったプロジェクトで、テストの名前空間がスラッグから作った名前空間に置き換わり、`composer test`が1件以上のテストを実行して成功する。
  - (b) starters/*とexamples/sample-siteのphpunit.xml.distが、packages/*/phpunit.xml.distと同じ属性(`failOnRisky`・`failOnWarning`・`failOnDeprecation`・`failOnNotice`・`beStrictAboutOutputDuringTests`・`displayDetailsOnTestsThatTriggerDeprecations`・`displayDetailsOnTestsThatTriggerNotices`・`displayDetailsOnTestsThatTriggerWarnings`)を持つ。docs/10 §7とスターターのAGENTS.md(とdocs/11 §5の全文案)が、この設定と例のテストを述べている。
  - (c) packages/testingの`UsesTempDirectories`に、専用のユニットテストがある(テストごとの親ディレクトリの下に作ること、探索結果ファイルを含めて片付けること)。
  - (d) 未登録のブロックのカテゴリが、探索コマンド(DiscoverCommandTestの階層)を通してエラーになることをテストで確かめている。
  - (e) CIと手元で、ユニットテストのアサーションの数が4件違う(CIは13848、手元は13852)原因を調べている。原因が分かれば直すか、違いが正当である理由を示す。分からなければ、調べた範囲をT5が受け入れ記録に書けるよう、タスクの報告に残す。
  - (f) 実行ごとに、testingのパッケージ別のカバレッジが2行ほど揺れる件(run 40637361238で90.41%、run 40636713298で90.81%)の原因を調べている。扱いは(e)と同じ。
- 検証コマンド: `composer qa:fast`、`bash tools/ci/create-starter-project.sh theme <ディレクトリ> <スラッグ> <表示名>`と`bash tools/ci/create-starter-project.sh plugin <ディレクトリ> <スラッグ> <表示名>`(スラッグと表示名を渡す4引数の形。2引数の形は`--no-scripts`で写すだけでsetup.phpを実行しないため、名前空間の書き換えを確かめられない。例はCIのStarter Projectsのジョブの`client-theme 'Client Theme'`・`shop-tools 'Shop Tools'`)で作ったテーマ・プラグインのそれぞれで、`composer qa`が成功し、テストを1件以上実行すること(`discover:check`は、CIのStarter Projectsのジョブと同じく、作ったプロジェクトを有効にしたwp-envに届く場所で実行する)、`cd examples/sample-site && composer qa`、`vendor/bin/phpunit -c phpunit.unit.xml.dist --filter 'UsesTempDirectoriesTest|DiscoverCommandTest'`

#### v0.9.0-T1: 実物のACF Proでの確認

- 対象ファイル領域: tools/(sample-siteをACF Proで起動する手順のスクリプト。道具にする場合に新設し、使い方はスクリプトの先頭のコメントに書く)。docs/10とexamples/sample-site/README.mdは、並列のT2が触るため、T1では触らない。確認作業で不具合が見つかった場合は、該当パッケージの修正タスクを追加する
- 完了条件:
  - ACF Proのzipのパスを環境変数`TMSWP_ACF_PRO_ZIP`で渡し、v0.6.0で手元確認したPro専用の型とPro限定機能のテストを含むacf-bridgeの統合テストが、実物のACF Proで通る(§1.2)。
  - sample-siteを、同じ`TMSWP_ACF_PRO_ZIP`のACF Proで起動し、sample-siteのACFの機能(製品の詳細の価格と仕様の表示と、管理画面での値の変更の反映)が動く。並列のT2がsample-siteの専用のwp-env(ポート8882)をE2Eで使うため、T1はその環境を使わず、`examples/sample-site/.wp-env.override.json`も使わない。T1は、examples/sample-site/.wp-env.jsonを写した別の設定ファイルをリポジトリの外に作り(`plugins`は`[]`にし、Proを展開したディレクトリを`mappings`で`wp-content/plugins/advanced-custom-fields`に載せる。themes・mappingsのパスは絶対パスにする)、`wp-env --config`と別の`WP_ENV_HOME`と別のポートで、別のプロジェクトとして起動する(tools/acf-pro-integration.shと同じ隔離の方法)。examples/sample-site/のファイルは読むだけで変えない。zipの展開はリポジトリの外で行い、確認の後に、展開したProと作った設定ファイルと、そのwp-envの環境を消す。ライセンスキーは使わない。
  - 手順を道具にするかは担当が判断する。道具にしない場合は、手順をタスクの報告に残し、T5が受け入れ記録に書く。
  - 2つの確認の結果(ACF Proの版、テストの件数と終了コード、sample-siteで確かめた画面)を、T5が受け入れ記録に書けるよう、タスクの報告に残す。
- 検証コマンド: `TMSWP_ACF_PRO_ZIP=<zipのパス> composer test:integration:acf-pro`、ACF Proで起動したsample-siteのwp-env上での表示確認

#### v0.9.0-T2: E2Eの自動化と統合テストの網羅確認

- 対象ファイル領域: ルートのpackage.json・package-lock.json(`@playwright/test`を版の完全一致で追加)、tests/E2E/(新設)、playwright.config(新設)、.github/workflows/qa.yml(Sample Site QAのE2Eの段、UnitとCoverageのユニットの段の`--testdox`)、ルートのcomposer.json(E2Eの実行のスクリプトを足す場合と、`test:coverage:unit`に`--testdox`を渡すためにスクリプトを変える場合)、docs/10 §1.1(package.jsonとcomposer.jsonの写し)・§4(E2Eの節。新設)・§5.1(qa.ymlの完全版)、packages/*/tests/Integration/(網羅の確認で足す統合テスト)、docs/acceptance/v0.9.0.md(新設。網羅の対応表の節)、examples/sample-site/README.md(サムネイルのURLの説明)
- 完了条件:
  - E2Eは`@playwright/test`でルートのtests/E2E/に置き、sample-site(専用のwp-env、ポート8882)に対して走らせる。webServerを使わず(wp-envの起動はcomposerのスクリプトで行う)、workersは1、CIでもretriesは0にする。`@wordpress/e2e-test-utils-playwright`は入れず、ログインとwp-envのcliの呼び出しは小さな補助で書く。
  - E2Eが次の機能を確かめる(sample-siteのREADMEの「確認の方法」の1〜5に対応する)。
    - フォーム: `/contact/`で入力・確認・送信ができ、送信の後に`/contact/thanks/`へ移る。必須の項目が空のときは確認画面へ進まずにエラーを出す。送ったメールが`sample_site_captured_mails`に記録される。
    - 設定ページ: 管理画面の「設定 > Sample Site」で通知先と説明文を保存でき、説明文がトップページに出る。メールアドレスの形でない通知先は保存されずにエラーになる。
    - サムネイル: トップページの製品のカードのアイキャッチのURLから画像が取得できる(生成の前のRESTのURLと、生成の後のキャッシュの静的なURLの両方)。署名を変えたURLは画像を返さない。
    - 日本語対応: 日本語のタイトルの製品のスラッグが`product-<ID>`の形になる。フォームのフリガナのひらがな・半角カナが、確認画面で全角カタカナになる。
    - ACF: 製品の詳細に価格と仕様(ACFの値)が出て、管理画面で値を変えると表示が変わる(CIは無料版のACF)。
    - ブロック: `/blocks/`に`sample-site/latest-products`の製品の一覧が出る。固定ページの編集画面でカテゴリ「Sample Site」からこのブロックを挿入できる。製品の編集画面では、`allowedBlocks()`で許したブロックだけを使える。
  - E2Eが失敗を検出できることを、手元で期待値を一時的に変えて失敗させて確かめている。
  - CIでは、Sample Site QAのジョブ(1セル)にE2Eの段を足す。ブラウザはキャッシュしない。失敗したときだけtraceを成果物として保存する。
  - CIのUnitのジョブとCoverageのユニットの段が`--testdox`で実行し、ログにテストの名前が出る。
  - 網羅の確認: docs/00〜10の「受け入れ基準」の節のチェックリストの項目と、本書の各版(v0.1.0〜v0.9.0)の受け入れ条件を、根拠のテストの名前(クラス::メソッド)に対応させた表を、docs/acceptance/v0.9.0.mdの新しい節に作る。WordPressに依存する項目の根拠は、統合テストかE2Eを必須とする(ユニットテストだけを根拠にしない)。根拠のテストが無い項目は、統合テストかE2Eを足すか、テストでない根拠(文書の確認・静的な検査等)をその理由とともに書く。表の行の数と、数えた受け入れ基準の項目の数が一致する(数え方を節に書く)。
  - examples/sample-site/README.mdの確認の方法の1が、サムネイルのURLは生成の前はRESTのURL(`/wp-json/tmswp/v1/thumb?...`)、生成の後はキャッシュの静的なURL(`/wp-content/uploads/tmswp-thumb/...`)になると述べている。
- 検証コマンド: E2Eの実行コマンド(手元とCI。sample-siteのwp-envを起動した状態)、`composer test:integration`、`composer qa:fast`

#### v0.9.0-T3a: MySQL 8.4 LTSと9.7 LTS

- 対象ファイル領域: .github/workflows/qa.yml(MySQLのジョブ。新設)、packages/core/src/Model/Identifier.php(予約語)、packages/core/src/Model/Migrator.php(MySQL 8.4 LTS・9.7 LTSとの差異への対応が要る場合)、packages/core/tests/Unit/Model/(予約語の追加のテスト)、packages/core/tests/Integration/(予約語の照合と、全型の作成と再適用の照合の統合テスト。新設)、phpunit.integration.xml.dist(新しいテストを分けて実行する場合)、docs/06 §2(予約語の集合の根拠)・§5(Migratorの照合を確かめたDBの版)、docs/10 §4(統合層のDBの構成)・§5.1(qa.ymlの完全版)
- 完了条件:
  - qa.ymlに、servicesでMySQL 8.4 LTSと9.7 LTSのコンテナを立て、wp-envのcliから`WP_TESTS_DB_HOST`(要れば`WP_TESTS_DB_USER`・`WP_TESTS_DB_PASSWORD`・`WP_TESTS_DB_NAME`。tests/wp-tests-config.php)で接続して統合テストを走らせるジョブ(2セル)がある。MySQLのイメージは版のタグで指定し、`latest`を使わない。PHPとWordPressの版は1つに固定し、選んだ版をdocs/10に書く。接続先がそのセルのMySQLの版であることを、ジョブの中で`SELECT VERSION()`等で確かめる(wp-envのMariaDBに接続して成功することを防ぐため)。
  - wp-env 12はDBをMariaDBに固定するため、`http://wordpress/`を叩くテストはこのジョブでも開発用サイトのMariaDBを使う。この制約をdocs/10に書く。
  - Identifier.phpの予約語に、MySQL 8.4のqualify・tablesampleと、9.7のexternal・libraryがある。docs/06 §2の予約語の集合の根拠が、MySQL 8.4 LTSと9.7 LTSを含む。
  - DBの`information_schema.KEYWORDS`の全語(`WORD`の列。MariaDBのこの表には`RESERVED`の列が無いため、`RESERVED`で絞らない)で、クォートしないカラム名での作成を試し、構文エラーになる語がすべて`TableDefinition`の宣言の時点で拒否されることを確かめる統合テストがある。MySQLの2セルとwp-envのMariaDBのすべてで通る。
  - v0.5.0のレビューで行った全型の作成と再適用の照合(組込の全ての列の型と、§2で受け付けるrawColumnの型を作成し、再適用で差分が残らない)が統合テストとしてあり、MySQLの2セルとwp-envのMariaDBのすべてで通る。docs/06 §5の照合を確かめたDBの版が、MySQL 8.4 LTSと9.7 LTSを含む。
  - 違いが見つかった場合は、このタスクでIdentifier.phpかMigrator.phpを直す(この2つを超える変更が要る場合は、対象ファイル領域を見直してから着手する。§0.1)。
- 検証コマンド: `composer test:unit`、`composer test:integration`、CIのMySQLのジョブ(2セル)の成功

#### v0.9.0-T4a: リリースの道具

- 対象ファイル領域: monorepo-builder.php、tools/release/(新設。2つのワーカー)、tests/Unit/(ワーカーのユニットテスト。新設)、ルートのcomposer.json(tools/release/のオートロード)、.github/workflows/split.yml、.github/split/close-pull-request.yml(新設)、packages/*/.gitattributes・starters/*/.gitattributes(8つ)、docs/RELEASING.md、docs/01 §4.3(monorepo-builderの利用範囲)・§4.4(split.ymlの写し)・§4.5(ブランチ・タグ運用とリリース手順)、docs/10 §1.1(composer.jsonの写し)・§5.2(split.ymlの完全版)・§6(monorepo-builderの設定・制約の同期・リリース手順)、AGENTS.md(リリースの節)。外部への書き込み(push・タグ・GitHubの設定)は行わない
- 完了条件:
  - monorepo-builder.phpから標準のリリースのワーカー7つ(SetCurrentMutualDependencies・AddTagToChangelog・TagVersion・PushTag・SetNextMutualDependencies・UpdateBranchAlias・PushNextDev)がすべて外れ、tools/release/の2つのワーカーだけが登録されている。リリースの後に次の版(`^0.10`等)へ自動で上げない。
  - 1つ目のワーカー(リリースの前の検査)は、次のどれか1つでも満たさなければ例外で止まる: 今のブランチが`main`である、`origin/main`と同じコミットである、作業ツリーが空である、そのタグが手元にも`origin`にも無い、`Kernel::VERSION`・packages/*とstarters/*の`tmswp/*`の制約・branch-alias・sample-siteのpathリポジトリの`versions`とrequireがタグの版と一致する、CHANGELOG.mdに`## vX.Y.Z — 題名`の見出しがある。
  - 2つ目のワーカーは、注釈付きのタグを作り、そのタグだけをpushする(`git push --tags`のように手元のすべてのタグを送らない)。gitのコマンドの失敗を握りつぶさず、例外で止まる。mainへのcommit・pushは行わない(mainへの反映はPR(`rewrite/v0`→`main`)のマージで行う)。
  - 2つのワーカーのユニットテストが、一時ディレクトリのgitのリポジトリ(originは手元のbareのリポジトリ)で、成功と、検査の各項目の失敗と、pushの失敗を確かめる。
  - split.ymlで、`SPLIT_ACCESS_TOKEN`が空のとき、mainとタグのpushのジョブが失敗する(成功で終わる今の空実行の段を残さない)。matrixの同期先のリポジトリごとに`concurrency`があり、同じ同期先への同期が並行して走らない(先の実行を取り消さない)。
  - 同期先でプルリクエストを自動で閉じてmonorepoへ案内するワークフローの正本が`.github/split/close-pull-request.yml`にあり、split.ymlが同期の前に各パッケージの`.github/workflows/`へ写す(monorepoのpackages/*・starters/*には置かない)。このワークフローは`pull_request_target`で動き、プルリクエストのコードをcheckoutせず、権限は`pull-requests: write`だけにする。外部アクションを使う場合はSHAで固定する。閉じるときの文面は英語だけとし(ユーザーの決定)、その文面を正本に入れる。
  - packages/*・starters/*の8つの`.gitattributes`に`/.github export-ignore`がある。8つのそれぞれについて、パッケージのディレクトリを写して正本のワークフローを`.github/workflows/close-pull-request.yml`に置いた、split相当の一時のgitのツリーを作り、その`git archive HEAD`(またはそのツリーをpathリポジトリにしてcreate-projectで作った先)に`.github`が入らないことを確かめている。対照として、一時のツリーの`.gitattributes`から`/.github export-ignore`を外すと、archiveに`.github/workflows/close-pull-request.yml`が入ることも確かめている(除外の検査が空振りしないことの確認)。
  - docs/RELEASING.md・docs/01 §4.3〜§4.5・docs/10 §5.2・§6・AGENTS.mdのリリースの節が、新しいワーカーとsplit.yml・mainへの反映の方法(PRのマージ)と一致する。
- 検証コマンド: `composer qa:fast`、`vendor/bin/monorepo-builder release v0.9.0 --dry-run`(登録したワーカーだけが表示され、外部への書き込みが無い)、`grep -nE 'SetCurrentMutualDependencies|AddTagToChangelog|TagVersion|PushTag|SetNextMutualDependencies|UpdateBranchAlias|PushNextDev' monorepo-builder.php`(一致なし)、8つのsplit相当の一時のgitのツリー(正本のワークフローを置いたもの)での`git archive HEAD | tar -t | grep '^\.github'`(一致なし)と、`/.github export-ignore`を外した対照での同じコマンド(一致あり)

#### v0.9.0-T3: 全マトリクスのqaとcritical / highの解消確認

- 対象ファイル領域: composer.json(`test:coverage:check`の全体の閾値)、docs/10 §1.1(composer.jsonの写し)。ほかは確認作業で、不具合が見つかった場合は、該当パッケージの修正タスクを追加する
- 完了条件:
  - 既知のcritical / highの不具合がすべて解消している。
  - composer.jsonの`test:coverage:check`の全体の閾値を85にしている(§0.7。タグ`v0.9.0`に入れるため、公開の前のこのタスクで上げる)。docs/10 §1.1の写しも同じ値にしている。
  - 閾値を85にしたコミットで、CIの全ジョブが成功する。全ジョブは、Coding Standards・Static Analysis・Monorepo Validate・Rules Code Examples・Unit(2セル)・Integration(PHP 8.3と8.5 × WordPress 7.0と最新版の4セル)・Coverage・Sample Site QA(T2のE2Eを含む1セル)・Starter Projects・T3aのMySQLのジョブ(MySQL 8.4 LTSと9.7 LTSの2セル)である。Coverageのジョブの合算カバレッジが85%以上である(§0.7)。
  - Modelの予約語の一覧(packages/core/src/Model/Identifier.php)と、MigratorのdbDeltaの後の実際の構造の照合を、MySQL 8.4 LTSと9.7 LTSでも確かめている(T3aのジョブの成功で確かめる)。v0.5.0の時点では、予約語の一覧(283語)はMySQL 8.0.44とMariaDB 10.11・12.3の実測にもとづき、Migratorの照合もこの3つのDBで確かめた。
  - E2Eの依存を足した後に、ルート・examples/sample-site・starters/themeの`npm audit`を測り直し、件数・重大度・経路を、T5が受け入れ記録に書けるよう、タスクの報告に残す(v0.8.0の時点の残りは12件。docs/acceptance/v0.8.0.mdの6章の5)。
- 検証コマンド: `gh run view <run id>`(全ジョブの成功と、Coverageの合算カバレッジの値)、`npm audit`(ルート・examples/sample-site・starters/theme)

#### v0.9.0-T4: 公開(リポジトリの作成・PRのマージ・split・Packagistへの登録・タグ付け)

- 対象ファイル領域: GitHub上のリポジトリ(monorepoと同期先の8リポジトリ)とmonorepoのSecrets、Packagist、CHANGELOG.md(リリースの準備のコミット)、.github/split/close-pull-request.yml(閉じるときの文面を直す場合)
- 完了条件:
  - 次の順で行っている。各段は前の段を前提にする。
    1. 同期先の8リポジトリ(`tmstack-io/tmswp-core`、`tmswp-acf-bridge`、`tmswp-settings-page`、`tmswp-thumbnail`、`tmswp-ja-toolkit`、`tmswp-testing`、`tmswp-starter-theme`、`tmswp-starter-plugin`)を作成し、IssueとWikiを無効にし、説明文でmonorepoへ案内する。アーカイブしない(§1.16)。
    2. 送り先への書き込み権限だけを持つトークンを発行する。トークンはfine-grained PAT。対象は同期先の8リポジトリ(`tmstack-io/tmswp-*`)だけ。権限はContents: Read and writeとWorkflows: Read and writeだけ(同期先の`.github/workflows/`を更新するためWorkflowsも要る)。
    3. トークンを、monorepoのSecretsに`SPLIT_ACCESS_TOKEN`として登録する(T4aの後のsplit.ymlは、トークンが空だとmainのpushで失敗するため、PRのマージの前に登録する)。
    4. 同期先でプルリクエストを閉じるときの文面が、`.github/split/close-pull-request.yml`の正本のとおりでよいかをユーザーに確かめる。直す場合は、正本を直したコミットを`rewrite/v0`に置く。そのうえで、CHANGELOG.mdのUnreleasedを`## v0.9.0 — 導入できる版`に改めるリリースの準備のコミットを`rewrite/v0`に置き、PR #1(`rewrite/v0`→`main`)をマージする。マージの方法(マージコミット・squash等)もユーザーに確かめる。mainへのpushでsplit.ymlが8リポジトリへ同期し、各リポジトリに`.github/workflows/close-pull-request.yml`が入る。
    5. 同期先に試しのプルリクエストを送り、自動で閉じられ、monorepoへの案内が付くことを確かめる。mainへの反映の後に文面を変えることになった場合は、正本を直したコミットを`rewrite/v0`に置き、ユーザーの確認を経たPR(`rewrite/v0`→`main`)のマージでmainへ反映し、8つの同期先の`.github/workflows/close-pull-request.yml`が新しい文面に同期されたことを確かめてから、試しのプルリクエストを送る。
    6. 8パッケージをPackagistに`tmswp/*`の名前で登録し、GitHubのwebhookで自動で更新されるようにする。
    7. `vendor/bin/monorepo-builder release v0.9.0`で、リリースの前の検査を通したうえで注釈付きのタグ`v0.9.0`を作ってpushし、split.ymlがタグを8リポジトリへ同期し、Packagistに0.9.0が出る。
  - リポジトリの作成・トークンの発行と登録・PRのマージ・試しのプルリクエスト・Packagistへの登録・タグ付けは、それぞれの段の前にユーザーに確認している。
  - リリースの版と`Kernel::VERSION`の一致は、T4aのリリースの前の検査で確かめている(食い違うと、そのタグのtmswp/coreを入れたサイトはbootの時の版の照合で起動しない。§1.6)。
- 検証コマンド: `composer show tmswp/core --available`(Packagistから0.9.0を取得できる)、`gh repo view tmstack-io/<リポジトリ> --json hasIssuesEnabled,hasWikiEnabled,isArchived,description`(8リポジトリで実行)、`gh pr view <試しのPRの番号> --repo tmstack-io/<リポジトリ> --json state`(CLOSED)

#### v0.9.0-T5: 利用者のプロジェクトからの導入確認と受け入れ記録

- 対象ファイル領域: docs/acceptance/v0.9.0.md
- 完了条件:
  - 空のテーマから、Packagistの`composer require tmswp/core`等で導入して起動できる。
  - Packagist経由の`composer create-project tmswp/starter-theme`と`composer create-project tmswp/starter-plugin`でプロジェクトを作り、setup.phpが動き、作ったプロジェクトの`composer qa`が通り、wp-env上で起動する。
  - 導入できる版の5条件と計画全体の完了条件3つ(§1.17)の結果と、T0c(e)・(f)・T1・T3の報告の内容と、v0.8.0からの引き継ぎのうち対応しない項目(T0の表の7の2つ目・3つ目、10、11)の扱いを、受け入れ記録に書いている。
  - 受け入れ記録のコミットは`rewrite/v0`に置き、mainへの反映は、ユーザーの確認を経てPRのマージで行う。
- 検証コマンド: 空のテーマで`composer require tmswp/core tmswp/acf-bridge tmswp/settings-page tmswp/thumbnail tmswp/ja-toolkit`、`composer create-project tmswp/starter-theme <ディレクトリ>`と`composer create-project tmswp/starter-plugin <ディレクトリ>`、wp-env上での起動確認、`composer test:coverage`

### 並列化

- 順序: {T0 ∥ T0b} → T0c → {T1 ∥ T2} → T3a → T4a → T3 → T4 → T5
- T0(docs/12だけ)とT0b(docs/12を触らない)は、ファイル領域が重ならない。
- T0cはT0bの後にする。docs/11(T0bの版の例と、T0cの§5・§10.2)と、スターターの設定(T0bのstarters/*/composer.jsonと、T0cのstarters/*のphpunit・テスト・setup.php)が、同じファイルか同じプロジェクトの検証に関わる。
- T1とT2は、ファイル領域が重ならない。T1はtools/のスクリプトだけを足し、docs/10とexamples/sample-site/README.mdはT2だけが触る。実行の環境も重ならない。T2はsample-siteの専用のwp-env(ポート8882)を使い、T1は別の設定ファイル・別の`WP_ENV_HOME`・別のポートのwp-envを使う(T2のCIのACFは無料版のまま)。
- docs/10は、T0c(§7)・T2(§1.1・§4・§5.1)・T3a(§4・§5.1)・T4a(§1.1・§5.2・§6)・T3(§1.1)が触る。.github/workflows/qa.ymlは、T2(E2Eと`--testdox`)とT3a(MySQLのジョブ)が触る(T4aが触るsplit.ymlは別のファイル)。ルートのcomposer.jsonは、T2(E2Eと`--testdox`のスクリプトを変える場合)・T4a(オートロード)・T3(閾値)が触る。このため、T0c → T2 → T3a → T4a → T3を逐次にする(§0.4)。
- T3は、T2とT3aで足したジョブを含むCIの全ジョブと、T4aで変えたリリースの道具を含む、公開の直前のコミットで確かめるため、T4aの後にする。T4はT4aの道具とT3の確認を前提にし、T5はT4の公開を前提にする。

### 受け入れ条件

1. 導入できる版の5条件(既知のcritical / highの全解消、全マトリクスのqa、実物のACF Proでの確認、想定用途の機能のsample-siteでの動作、合算カバレッジ85%以上)を満たす(§1.17)。全マトリクスのqaは、T3のCIの全ジョブ(MySQL 8.4 LTSと9.7 LTSのジョブとE2Eを含む)の成功で確かめる。
2. 計画全体の完了条件3つ(qa、sample-siteが実際のWordPressで動く、`composer require`で導入できる)を満たす(§1.17)。
3. 1.0.0は導入先での安定運用を確認してから出す(この版では出さない)。
4. unitとintegrationの合算カバレッジが§0.7の表のこの版の閾値(85%)以上である(CIの`coverage`ジョブ)。composer.jsonの`test:coverage:check`の閾値は、この版の値と完了済みの版の値のうち最大のものになっている(§0.7)。
5. 想定用途の機能(フォーム、設定ページ、サムネイル、日本語対応、ACF、ブロック)のE2EがCIのSample Site QAのジョブで通り、docs/00〜10の受け入れ基準と各版の受け入れ条件の網羅の対応表がdocs/acceptance/v0.9.0.mdにある。
6. スターターから作ったプロジェクトの`composer qa`が1件以上のユニットテストを実行し、Packagist経由の`composer create-project`でスターターからプロジェクトを作れる。
7. タグ`v0.9.0`は、リリースの前の検査を通したうえで、注釈付きのタグとしてそのタグだけがpushされている。mainへの反映はPR #1のマージで行い、同期先の8リポジトリは読み取り専用の設定(IssueとWikiが無効、説明文での案内、プルリクエストの自動のクローズ)になっている。
