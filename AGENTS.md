# TMSWP 開発ルール

このリポジトリは TMStack WordPress Framework 本体の開発用 monorepo です。実装は `docs/12-implementation-plan.md` のロードマップに従い、版とタスクの順に進めます。

- 対応範囲は PHP 8.3〜8.5、WordPress 7.0 以上、MySQL 8.4 以上・MariaDB 10.11 以上とする。
- `tmswp/core` の runtime 依存は `psr/log` と `psr/container` のみに限定する。
- 旧 iwf2b 互換のシム、別名、移行用 API は追加しない。
- すべての PHP ファイルに `declare(strict_types=1)` を置き、公開 API は型宣言を必須とする。
- 品質確認は `composer lint` / `composer analyse` / `composer test:unit` / `composer test:integration` / `composer qa` を使う。
- fixture の app/ を変えたら、探索結果ファイル(`tmswp-discovery.php`)を `composer discover:build` で作り直してコミットする。照合は `composer discover:check`(`composer qa` に含まれ、wp-env が必要)。
- wp-env は `npm ci` で入れたローカルの `@wordpress/env`(package.json で版を固定)を `composer wp:start` / `wp:stop` / `test:integration` 経由で使う。直接呼ぶときは `node node_modules/@wordpress/env/bin/wp-env` とパスで指定する。`npx` や npm の `exec` / `run` でコマンド名 `wp-env` を解決する呼び出しは、第三者のパッケージやグローバルの同名コマンドを実行しうるので使わない。
- 版のすべてのタスクを終えたら、その版の受け入れ条件の確認結果を `docs/acceptance/v0.x.y.md` に記録する。

## このファイルの読者

このファイルは、フレームワーク本体を開発・修正するAIエージェントと人(コントリビュータ)が読む。
利用側のプロジェクト(テーマ・プラグイン)の開発規約は`starters/*/AGENTS.md`と`packages/*/rules/`にある。混同しない。
規約はAGENTS.mdだけに置き、`CLAUDE.md`等のエージェント固有の規約ファイルは置かない(docs/12 §1.18)。

## アーキテクチャの要約

- 対応範囲とコアのruntimeの依存は、上の開発ルールのとおり(`psr/log` ^3・`psr/container` ^2)。
- 規約による自動の探索: 利用側の`app/`にPHP 8のAttributeを付けたクラス(投稿タイプ・タクソノミー・ロール・オプショングループ・Model・フック)を置くだけで、Kernelが探索して登録する。独自ブロックは`build/blocks/`以下の`block.json`を探索して登録する。フォームは探索の対象ではなく、利用側が`FormDefinition`で定義し、前処理クラス等で明示的に作って使う。探索の結果は、探索コマンド(`wp tmswp discover`)が探索結果ファイルに書き、コミットする。本番(debugが偽)は探索結果ファイルだけを読み、debugのときだけ`app/`と`build/blocks/`を毎回走査する。
- ビューはWordPress標準のテンプレート階層を壊さない(絶対原則)。テンプレートの前処理は、テンプレートの先頭で前処理クラスを明示的に呼ぶ。
- 外部プラグイン(ACF・CF7等)に依存するコードはコアに置かない(ACFは`packages/acf-bridge`だけ)。拡張は利用側の`KernelConfig`の`extensions`で、Kernelごとに登録する。
- 配布: このmonorepoで開発し、`.github/workflows/split.yml`がmainとタグを8つの読み取り専用リポジトリ(`tmstack-io/tmswp-*`)へ同期し、Packagistに`tmswp/*`の名前で登録する。全パッケージを同じ版で出す(lockstep。SemVer、0.xの間はマイナーで破壊的変更を許す)。Packagistにはv0.9.0から公開している。

## パッケージ構成

| パス | composerの名前 | 名前空間 | 役割 |
|---|---|---|---|
| packages/core | tmswp/core | `TMSWP\Core\` | Kernel・Container・エンティティの定義・メタ・オプション・Form・Model・Log・View・Support・ブロックの登録 |
| packages/thumbnail | tmswp/thumbnail | `TMSWP\Thumbnail\` | 署名付きのURLのサムネイル(WP_Image_Editor) |
| packages/settings-page | tmswp/settings-page | `TMSWP\SettingsPage\` | 管理画面の設定ページ(Formの層を使う) |
| packages/acf-bridge | tmswp/acf-bridge | `TMSWP\AcfBridge\` | acf-jsonからの型付きアクセサの生成・`AcfMetaStorage`・`tms-acf` |
| packages/ja-toolkit | tmswp/ja-toolkit | `TMSWP\JaToolkit\` | 日本語サイト向けの機能(すべてのモジュールがオプトイン) |
| packages/testing | tmswp/testing | `TMSWP\Testing\` | テストの支援コード(`UnitTestCase`・`IntegrationTestCase`・テストダブル。利用側はrequire-devで使う) |
| starters/theme | tmswp/starter-theme | `TmswpStarterTheme\`(create-projectで、スラッグから作った名前空間に書き換える) | create-projectで使うテーマの雛形 |
| starters/plugin | tmswp/starter-plugin | `TmswpStarterPlugin\`(同上) | create-projectで使うプラグインの雛形 |

上の8つが読み取り専用リポジトリへの同期の対象である。`examples/sample-site`(テーマのスターターから作ったサンプルのテーマ。同期しない)は、主な機能を実物のWordPressで動かす例で、専用のwp-env(ポート8882)と`composer qa`を持つ。

設計の正本は`docs/00〜11`である(用語は`00-overview.md` §7、設計判断はDRとして同§4)。`docs/`に定義の無い公開APIを作る場合は、同じ変更で設計書を改訂する。

## 必須コマンド

```bash
composer qa                 # lint → analyse → acf:check:fixtures → discover:check → test。これが通らない変更はコミットしない(wp-env・Dockerが必要)
composer qa:fast            # lint → analyse → test:unit。Docker不要。実装中の反復はこちら
composer test               # test:unit → test:integration(wp-envが必要: composer wp:start)
composer test:unit          # ユニットテスト(PHPUnit 11・Brain Monkey。WordPress非依存。Docker不要)
composer test:integration   # 統合テスト(wp-envのcliコンテナのPHPUnit 9.6。wp-envが必要: composer wp:start)
composer test:e2e           # E2E(Playwright。tests/E2E/)。sample-siteのwp-env(ポート8882)が必要: cd examples/sample-site && composer wp:start && composer wp:setup。初回はnode node_modules/@playwright/test/cli.js install chromium
composer test:coverage      # ユニット・統合の合算カバレッジの計測と閾値の判定(wp-envをcomposer wp:start:coverageで起動しておく)
composer analyse            # PHPStan level max(baselineなし)
composer lint               # コードスタイルの検査(php-cs-fixerのdry-run)
composer fix                # コードスタイルの自動修正
composer discover:build     # fixtureのテーマ・プラグインの探索結果ファイルを作る(wp tmswp discover --kernels=2。wp-envが必要)
composer discover:check     # fixtureの探索結果ファイルが最新かを照合する(wp tmswp discover --check --kernels=2)
composer acf:check:fixtures # fixtureのacf-bridgeの生成物を、WordPressを起動しないtms-acf check --strictで照合する
composer i18n:build         # 翻訳ファイル(packages/*/languages/)をソースから作り直す(wp-envが必要)
composer monorepo:validate  # パッケージ間の版の制約の整合を検査する
composer wp:start           # wp-envを起動する(http://localhost:8888)。止めるのはcomposer wp:stop
php tools/extract-rules-code.php && php tools/verify-rules-code.php   # packages/*/rules/のコード例の抽出と検査
```

- PHPStanは**level max・baselineなし**で運用する。エラーをbaselineや`@phpstan-ignore`で握りつぶす変更は受け入れない。ignoreがどうしても要る場合(WordPressの関数のstubの不備等)は、理由のコメントを付け、変更の説明に書く。
- テストは2層である。ユニット層(`packages/*/tests/Unit/`。WordPress非依存)と統合層(`packages/*/tests/Integration/`。wp-envとWordPressのテストライブラリ)。ルートの`tests/Unit/`はtools・bin・スターターのsetup.php等を、`tests/Integration/`はスターターから作ったテーマ・プラグインの同時起動を確かめる。テストは`src/`の構成をミラーした場所に置く。
- 新しいクラスにはユニットテストを足す。WordPressの関数に触れる処理は薄い境界のクラスに寄せ、その内側をユニットテストできるようにする。WordPressに依存するクラスは、モックを多用したユニットテストではなく、統合テストで確かめる。
- カバレッジは、ユニットと統合を合算した行カバレッジで判定する。全体の閾値は版ごと(`docs/12-implementation-plan.md` §0.7)、パッケージ別の閾値は6パッケージ(core・acf-bridge・settings-page・thumbnail・ja-toolkit・testing)とも80%で、どちらも`composer.json`の`test:coverage:check`の引数の1か所で定義する。
- `packages/*/rules/`を変えたら、上のrulesの抽出と検査を通す。
- サンプルサイトは`cd examples/sample-site && composer wp:start && composer wp:setup && composer qa`で確かめる(手順は`examples/sample-site/README.md`)。スターターから作ったプロジェクトは、`tools/ci/create-starter-project.sh`(CIのStarter Projectsのジョブと同じ手順)で作って確かめる。

## CI

`.github/workflows/qa.yml`は、すべてのプルリクエストとmainへのpushで次のジョブを実行する。外部アクションはSHAで固定し、runnerは`ubuntu-24.04`に固定する(`ubuntu-latest`を使わない)。

| ジョブ | 内容 |
|---|---|
| Coding Standards | PHPStanのbaselineのファイルが無いことの確認と`composer lint`(PHP 8.3) |
| Static Analysis | `composer analyse`と`composer acf:check:fixtures` |
| Monorepo Validate | `composer monorepo:validate` |
| Rules Code Examples | `packages/*/rules/`のコード例の抽出と検査(`tools/extract-rules-code.php`・`tools/verify-rules-code.php`) |
| Unit | `composer test:unit`(PHP 8.3と8.5) |
| Integration | `composer test:integration`(PHP 8.3と8.5 × WordPress 7.0と最新版の4セル) |
| Integration (MySQL・MariaDB) | servicesのMySQL 8.4 LTS・9.7 LTSとMariaDB 10.11 LTS(3セル。PHP 8.3・WordPress 7.0)に、wp-envのcliから`WP_TESTS_DB_HOST`で接続した統合テスト。接続先の版を`SELECT VERSION()`で確かめる |
| Coverage | 翻訳ファイルと探索結果ファイルの照合、合算カバレッジの閾値の判定(PHP 8.3・WordPress最新版) |
| Sample Site QA | `examples/sample-site`の`composer qa`(専用のwp-env) |
| Starter Projects | スターターからcreate-projectで作ったテーマ・プラグインの`composer qa`と、テーマのスターターのブロックのビルドと探索結果ファイルの照合 |
| Split Packages Check | `tools/ci/split-check.sh`。8つのパッケージをsplit相当で取り出し、配布物(`git archive`)の中身と、packages/*の6つの単独の`composer install && composer test`を確かめる |

## コーディング規約

- `declare(strict_types=1);`はすべてのPHPファイルに置く(上の開発ルール)。コードスタイルはPER Coding Style 2.0と`.php-cs-fixer.dist.php`の規則に従う。
- クラスは`final`にする。継承させる拡張点(`PostType`・`Taxonomy`・`UserRole`・`Model`・`Hook`の各基底)は`abstract`で宣言する。オプショングループはbacked enumで、基底クラスを持たない。`Form`は`final`で、利用側は`FormDefinition`で定義して`new Form(...)`で作る(継承しない)。
- プロパティ・引数・戻り値のすべてに型を宣言する。`mixed`は境界(WordPressのAPI・`$_POST`等)に限り、内側ですぐに絞り込む。
- **禁止**: `__call`・`__callStatic`・`__get`・`__set`等のマジックメソッド、`extract()`、`eval()`、グローバル変数の新設、`exit`・`die`(`00-overview.md`の原則1の例外である、PRGのリダイレクトと設定ページの保存の後の`wp_safe_redirect()`の直後を除く)、WordPress標準のテンプレート階層への介入(`template_include`での差し替え)。
- 例外はFWの基底(`TMSWP\Core\Exception\`の`LogicException`・`RuntimeException`・`InvalidArgumentException`)を継承し、マーカーのinterface`TmswpExceptionInterface`を実装する(`tests/Unit/ExceptionHierarchyTest.php`が確かめる)。素の`\RuntimeException`等をthrowしない。エラーの扱いは`00-overview.md` §5.6(設定の誤りは起動時に常に例外、実行時のデータの取得の失敗はdebugなら例外・本番ならKernelのロガーに記録して既定値)に従う。
- WordPressの関数の直接の呼び出しは、各パッケージの境界のクラス(例: `Meta\WpMetaStorage`・`Model\Connection`)に閉じ込める。
- コアの名前空間は`TMSWP\Core\`。コアの名前空間がDiscoveryの走査の対象外である前提を崩さない。
- i18n: テキストドメインは`tmswp`。ソースの文言(gettextのソースの文字列)は英語で書き、`__('...', 'tmswp')`の形で渡す。
- コメント・docblockは日本語でよい。識別子は英語にする。

## 変更の手順

1. 該当するパッケージの設計書(`docs/`)を読み、APIの契約を確かめる。ロードマップのタスクとして行う場合は、`docs/12-implementation-plan.md`のそのタスクの「対象ファイル領域」と完了条件に従う。
2. テストを先に書くか、少なくとも変更と同じ変更でテストを足す。
3. `composer qa`を通す。
4. 利用者に見える変更は、`CHANGELOG.md`の先頭の節(見出しはUnreleased)の小見出し(追加・変更・削除・修正・開発)に書く。公開APIのシグネチャの変更・削除等の破壊的変更は、変更・削除に、利用側で直すことと一緒に書く。
5. 公開APIを変えたら、`packages/*/rules/`・`starters/`・`examples/sample-site/`の規約とコード例を追従させる(`grep -rn <旧API> packages/*/rules starters examples/sample-site --exclude-dir=vendor --exclude-dir=node_modules`で追従の漏れを確かめる)。
6. `docs/01-architecture.md` §5.3の表に無いWordPressのAPI(関数・クラス・フック・引数)を新しく使うときは、そのAPIが導入された版がWordPress 7.0以下であることを、WordPressの公式のリファレンス(developer.wordpress.org)の「Since」で確かめる。7.0より後に導入されたAPIは使わない(7.0未満のための分岐やフォールバックも書かない。§5.3)。

## 新しいパッケージを足す手順

正本は`docs/10-quality.md` §6.2である。

1. `packages/<name>/`に、`composer.json`(nameは`tmswp/<name>`、PSR-4は`TMSWP\<PascalName>\` → `src/`、license、requireの`"tmswp/core": "^X.Y"`とPHP・拡張の要件、require-devの`"tmswp/testing": "^X.Y"`、`scripts.test`、`extra.branch-alias`)、`LICENSE`(ルートと同じGPL-2.0-or-later)、`README.md`、`phpunit.xml.dist`、`.gitattributes`(`export-ignore`で`/tests`・`/phpunit.xml.dist`・`/.gitattributes`・`.DS_Store`を外す)と、`src/`・`tests/Unit/`を置く。利用者向けのレシピが要る場合は`rules/`に置き、コアの`rules/extensions.md`にも導入手順を足す。コアに要件を足さない。
2. ルートの`composer.json`の`replace`・`autoload`・`autoload-dev`に手で足す(`monorepo-builder merge`は使わない)。
3. ルートの`phpstan.neon.dist`の`paths`、`phpunit.unit.xml.dist`・`phpunit.integration.xml.dist`のtestsuite、ルートの`composer.json`の`test:coverage:check`の`--package`、`.github/workflows/split.yml`の`matrix`に足す。同期先のリポジトリは、最初の同期の前にmainに初期コミットが要る(`docs/RELEASING.md`の「新しい同期先のリポジトリ」)。
4. `docs/01-architecture.md`・`docs/10-quality.md`・`docs/RELEASING.md`・このファイルのパッケージの一覧と数を改める。
5. パッケージをsplit相当で取り出して`composer install && composer test`が通ること(`docs/RELEASING.md`の「split相当の確認」)と、`composer monorepo:validate`で確かめる。
6. 拡張のパッケージは**コアのAPIだけに依存する**。拡張どうしの依存は原則として作らない(要る場合は変更の説明で合意を取る)。Packagistへの登録はメンテナが行う。

## 禁止事項(コントリビュータ向け)

- コアに外部プラグイン(ACF・CF7・Yoast等)の関数・クラスの参照を足すこと。
- コアのruntimeの依存(`psr/log`・`psr/container`以外)を足すこと。
- `register_post_type`等のWordPressの登録の関数を、`init`等の正規のフックの外で即時に実行すること。
- `packages/*`の間でソースをコピーすること(共有したいコードは、coreのSupportへの昇格を検討する)。
- `starters/`・`packages/*/rules/`・`examples/sample-site/`の規約とコード例を、コアのAPIの変更に追従させずに放置すること。
- `CLAUDE.md`等のエージェント固有の規約ファイルを置くこと。

## リリース

- 手順の正本は`docs/RELEASING.md`である。mainへの反映は、PR(`rewrite/v0`→`main`)のマージで行う。`vendor/bin/monorepo-builder release vX.Y.Z`は、mainで`tools/release/`の2つのワーカーを実行し、リリースの前の検査(mainであること・origin/mainと同じであること・作業ツリーが空であること・タグが無いこと・版の一致・CHANGELOGの`## vX.Y.Z — 題名`の見出し)に通れば、注釈付きのタグを作ってそのタグだけをpushする。mainへのcommit・pushと版の書き換えはしない。`split.yml`がmainとタグを各読み取り専用リポジトリへ同期する(`SPLIT_ACCESS_TOKEN`が空なら失敗する)。
- 版(`Kernel::VERSION`・`tmswp/*`の制約・branch-alias・sample-siteの版・packages/*のrules/とREADME.mdの設計文書へのリンクの版)は、版の最初のタスクで1つのコミットで手で上げる。リリースの後に次の版へ自動で上げない。
- 同期先でプルリクエストを閉じるワークフローの正本は`.github/split/close-pull-request.yml`で、`split.yml`が同期の前に各パッケージの`.github/workflows/`へ写す。packages/*・starters/*には置かない。
- PRのマージとタグ付けは、毎回ユーザーに確認してから行う。
