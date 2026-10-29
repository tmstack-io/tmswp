# 変更履歴

新しい版を上に書く。v0.1.0〜v0.8.0は実装計画(docs/12-implementation-plan.md)の版で、タグを付けて公開していない。各版の詳細は、docs/acceptance/の受け入れ記録にある。
まだリリースしていない変更は、先頭のUnreleasedの節に書く。リリースの準備のコミットで、この節の見出しを手で`## vX.Y.Z — 題名`(下の各版の見出しと同じ形)に改める。

## Unreleased

### 追加

- テーマとプラグインのスターターに、Brain Monkeyだけで書いた例のユニットテスト(`tests/Unit/Hook/ThemeSetupHookTest.php`・`PluginSetupHookTest.php`)を置いた。スターターから作ったプロジェクトの`composer test`が、テストを0件で成功せず、そのまま例のテストを実行する。
- tmswp/testingに`RealTables`を足した。WordPressのテストライブラリ(`WP_UnitTestCase`)の統合テストで、CREATE TABLE・DROP TABLEの一時テーブルへの書き換えを外して実際のテーブルを作り(`RealTables::start($this, $wpdb, <テストが作るテーブルの、接頭辞を除いた名前>...)`)、`tearDown()`で、ロールバックの後に`onTearDown()`で登録した後始末を実行し、宣言したテーブルのうち`start()`の時点に無かったものを名前で1つずつ消してから、COMMITする。DB全体の差分は使わないため、ほかの接続が作ったテーブルと宣言していないテーブルは消さない。ROLLBACK・後始末・DROP・COMMITのどれが失敗しても残りを続け、最後に、起きた順で最初の失敗を投げる。MySQLのSHOW TABLES・SHOW TABLE STATUSは一時テーブルを返さない(MariaDBは11.2から返す)ため、MySQLでMigratorを使う統合テストは、一時テーブルでは`MigrationException`(`table was not created`)になる。DDLは暗黙にコミットするため、DDLより前に書いた行は`onTearDown()`で消す。monorepoの統合テストの基盤の`IntegrationTestCase`には、これをそのテストで使う`useRealTables()`を足した。

### 変更

- スターターの`bin/setup.php`が、作ったプロジェクトに`.github/workflows/close-pull-request.yml`があれば消す。スターターの読み取り専用リポジトリでプルリクエストを閉じるワークフローで、create-projectを`--prefer-source`で実行すると入る。`.github/`のほかのファイルには触れない。スターターの`.gitattributes`に`/.github export-ignore`を足したため、作ったプロジェクトの`.github/`は配布物(`git archive`)に入らない。
- `Kernel::VERSION`を0.9.0にし、packages/*・starters/*・examples/sample-siteの`tmswp/*`の制約を`^0.2`から`^0.9`に、packages/*のbranch-aliasを`dev-main`→`0.9.x-dev`にした。
- スターターとサンプルサイトの`phpunit.xml.dist`に、packages/*と同じ`failOnRisky`・`failOnDeprecation`・`failOnNotice`・`beStrictAboutOutputDuringTests`・`displayDetailsOnTestsThatTrigger*`を足し、スターターには`failOnEmptyTestSuite`も足した。risky・非推奨・Noticeを出すテスト、テスト中の出力、テストが0件のときに`composer test`が失敗する。既存のプロジェクトで同じ扱いにするには、この属性を`phpunit.xml.dist`に足す。
- スターターの`bin/setup.php`が、名前空間を書き換えた後に、プロジェクトの`vendor/bin/php-cs-fixer`(`.php-cs-fixer.dist.php`の設定)で`fix`を実行し、崩れた`use`の並びを整える。php-cs-fixerが失敗するとエラーで止まり(composer.jsonを書く前のため、もう一度実行できる)、`--no-install`で作ってphp-cs-fixerが無い場合は`composer fix`の実行を案内する。
- tmswp/testingの`WpdbTestDouble`の戻り値を実際の`wpdb`と同じ意味にし、代役が決められない形を、黙って値を返さずに`InvalidArgumentException`にした(破壊的な変更)。`update()`・`delete()`と、`query()`で実行するINSERT・UPDATE・DELETE・REPLACEは、新しい`queueAffectedRows()`で入れた行の数を返し、入れていなければ例外になる(これまでは常に1)。`insert()`と、`query()`で実行して行を作るINSERT・REPLACEの`insert_id`は、新しい`queueInsertId()`で入れたIDになり、入れていなければ例外になる(これまでは自動で1つずつ進めた)。0件と失敗のINSERT・REPLACEの`insert_id`は0になる。すべての操作の開始時に、実際の`wpdb`の`flush()`と同じく`rows_affected`を0、`last_error`を空にする(これまでは、DDLや読み取りの後も直前の書き込みの件数が残った)。`get_var()`は空文字を`null`で返す。`query()`はDDLに`true`を返し(これまでは1)、SELECT等の対応しない形と、JtiStoreの形でないINSERT IGNOREと`option_name`のDELETEは例外になる。`get_results()`は、行が無いとき(キューが空)とエラーのときに空の配列を返し(これまでは`null`)、`OBJECT_K`は1列目の値をキーにする。JtiStoreの獲得・解放・掃除と`isClaimed()`の読み取りは、状態を`%i`のテーブルごとに分け、`option_name`の大文字と小文字を区別せず、`isClaimed()`には`queueVar()`のキューではなく獲得済みの状態から答える。獲得の後の`insert_id`は、行を作れば正の値、重複で無視されれば0になる(値そのものはサーバーの自動採番の設定に依存するため、実物との一致は約束しない)。`query()`のDDLは、CREATE・ALTER(単語のRENAME・PARTITION・TABLESPACEを含むALTERは例外)と、単一のテーブルの`TRUNCATE [TABLE]`・`DROP TABLE [IF EXISTS]`、`DROP INDEX`・`DROP VIEW`だけを受け付ける。TRUNCATEとDROP TABLEは、そのテーブルの獲得済みのjtiも消す。ほかのDROP・TRUNCATE(複数のテーブル・データベース名で修飾した名前・`DROP TEMPORARY TABLE`・`DROP DATABASE`・列挙していないDROP)と、SQLのコメント(`/*`・`--`・`#`)を含むDROP・TRUNCATEと、単独の文の`RENAME TABLE`は例外になる。`get_var()`の先頭でない列・行と、`get_row()`の先頭でない行・`OBJECT`・`ARRAY_A`・`ARRAY_N`以外の出力の形は例外になる。利用側では、Model・`Connection`の`update()`・`delete()`・`execute()`の書き込みを通すテストで、実行の前に`$wpdb->queueAffectedRows(<行の数>)`を呼び、`insert()`と行を作る`execute()`のINSERTの前に`$wpdb->queueInsertId(<ID>)`を呼ぶ。`get_results()`の`null`を確かめていたテストは、空の配列に直す。JtiStoreの`isClaimed()`のために`queueVar()`で値を入れていたテストは、その`queueVar()`を外す(獲得した状態がそのまま読まれる)。
- `TableDefinition`のカラム名・インデックス名で拒否する予約語に、MySQL 8.4 LTSの`qualify`・`tablesample`と、9.7 LTSの`external`・`library`を足した(破壊的な変更)。この4語をクォートせずに使うCREATE TABLEは、これらのMySQLで構文エラーになる。この4語をカラム名・インデックス名(`id()`の`$name`、`rawColumn()`、`index()`・`unique()`の`$name`を含む)に使っていたテーブルの定義は、宣言の時点で`SchemaException`になる。利用側では、その列・インデックスを別の名前で宣言し、作成済みのテーブルは手動の`ALTER TABLE ... RENAME COLUMN`等で名前を合わせてから`wp tmswp migrate`を実行する(dbDeltaは列の名前の変更を行わない)。

### 修正

- tmswp/settings-pageで、親が`options-general.php`の設定ページの保存の後に「Settings saved.」の通知が2つ出ていたのを直した。保存の後のリダイレクト先のクエリ変数を`updated=1`から`tmswp-settings-saved=1`に変えた(WordPress本体の`wp-admin/options-head.php`が`updated`を見て同じ通知を出していたため)。どの親のページでも、成功の通知はFWの1つだけになる。この変数は`removable_query_args`に足し、表示の後にアドレスバーのURLから除く。利用側で保存の完了を`$_GET['updated']`で見ていたコードは、`$_GET['tmswp-settings-saved']`に直す。
- tmswp/acf-bridgeで、生成物の`AcfManifest`がすでに持つ管理キーを、`#[UseMetaStorage(AcfMetaStorage::class)]`の無いエンティティが`#[MetaSchema]`で宣言している(生成物をコミットした後に`#[MetaSchema]`を足した)とき、`wp tmswp acf check`・`sync`が、起動時(`init` 6)の申告の`DuplicateMetaKeyException`の致命的エラーで止まり、E101(checkはexit 2、syncは書き込まずにexit 1)を報告できなかったのを直した。この2つのコマンドのプロセスに限り、重複するキーを申告せずに続け、コマンドがE101で報告する。ほかのWP-CLIのコマンド(`wp eval`等)とWP-CLIでないリクエストは、これまでどおり起動時の例外で止まる。
- tmswp/testingの`WpdbTestDouble`の`query()`が、`JtiStore::gc()`の掃除のDELETE(`option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d`)で何も消さずに0を返していたのを、実wpdbと同じく、LIKEのパターン(大文字と小文字を区別しない)に合い期限が時刻より前の獲得済みのjtiを消し、消した件数を返すようにした。これと違う形の`option_name LIKE`のDELETEは、黙って成功せずに`InvalidArgumentException`になる。
- tmswp/testingの`UnitTestCase`(`UsesTempDirectories`)の、テストの後の一時ディレクトリの片付けが、消したファイルとディレクトリの数に比例するメモリを、テストの実行の終わりまで残していたのを直した。Brain Monkeyが読み込むPatchwork(antecedent/patchwork 2.2.4まで)のstream wrapperは、ファイル操作のたびに、stream factoryのresource(1回の操作で約250バイト)を解放せずに残す。片付けは、削除の間だけこのwrapperを、resourceを解放する手順で外してから消し、消した後に付け直す。あわせて、削除の途中の例外(読めないディレクトリ等)で、Patchworkのwrapperが外れたまま後のテストに残り、以後に読み込むファイルがPatchworkの書き換えを受けなくなっていたのも直した。テストの本体が行うファイル操作は、これまでどおりPatchworkを通るため、同じ形でメモリが残る。

### 開発

- リリースの道具を作り直した(D-54)。monorepo-builder.phpから標準のリリースのワーカーをすべて外し(既定のワーカーも`MBConfig::disableDefaultWorkers()`で外す)、`tools/release/`の2つのワーカーだけを登録した。`vendor/bin/monorepo-builder release vX.Y.Z`は、1つ目のワーカーで、mainであること、originにmainのブランチがあり、`git fetch --no-tags origin +refs/heads/main:refs/remotes/origin/main`で取り直した`origin/main`とHEADが同じであること(設定のfetchのrefspecと、手元に残った古い追跡refには頼らない)、作業ツリーが空であること、タグが手元にも`origin`にも無いこと、`Kernel::VERSION`・packages/*とstarters/*の`tmswp/*`の制約とbranch-alias・examples/sample-siteの制約とpathリポジトリの`versions`がタグの版と一致すること、CHANGELOG.mdに`## vX.Y.Z — 題名`の見出しがあることを検査し、満たさない項目をすべて並べて止まる。2つ目のワーカーで、注釈付きのタグを作り、`git push origin refs/tags/vX.Y.Z`でそのタグだけを送る。mainへのcommit・pushと、版の書き換え・次の版への引き上げはしない(mainへの反映はPRのマージで行う)。2つのワーカーのユニットテスト(`tests/Unit/Release/`)は、一時ディレクトリのgitのリポジトリと手元のbareのリポジトリで確かめる。
- split.ymlで、`SPLIT_ACCESS_TOKEN`が空のときにジョブを失敗させ(これまでは同期せずに成功していた)、同期先ごとの`concurrency`(先の実行を取り消さず、後の実行を`queue: max`で待たせる)で同じ同期先への同期を並行させないようにした。同期先に送られたプルリクエストを閉じてmonorepoへ案内するワークフローの正本`.github/split/close-pull-request.yml`を足し、split.ymlが同期の前に各パッケージの`.github/workflows/`へ写す。8つのパッケージの`.gitattributes`に`/.github export-ignore`を足し、このワークフローを配布物に入れない。
- CIに、8つのパッケージをsplit相当で取り出して確かめるSplit Packages Checkのジョブ(`tools/ci/split-check.sh`)を足した。packages/*の6つは配布物(`git archive`)から`tests/`・`phpunit.xml.dist`・`.gitattributes`・`.DS_Store`・`.github/`が外れることと、取り出したcore・testingをpathリポジトリで入れた単独の`composer install && composer test`を、starters/*の2つは配布物から`.github/`・`.DS_Store`・`node_modules/`が外れ、`tests/`と`phpunit.xml.dist`が入ることを確かめる。
- testingの`UsesTempDirectories`の専用のユニットテスト(`packages/testing/tests/Unit/UsesTempDirectoriesTest.php`。テストごとの親ディレクトリの下に作ること、探索結果ファイルを含めて親ディレクトリごと片付けること、作れないときに失敗すること)と、未登録のブロックのカテゴリが探索コマンドのエラーになり、どのKernelの探索結果ファイルも書かないことのテスト(`DiscoverCommandTest`)を足した。`WpdbTestDouble`の掃除のDELETEの単体のテスト(`WpdbTestDoubleTest`)と、実wpdbとの照合(`WpdbTestDoubleParityTest`)を足し、testingのパッケージ別のカバレッジが実行ごとに揺れない(確率的な掃除に当たったときだけ通る行が無い)ようにした。ja-toolkit・settings-pageのソースの検査のテスト(`JaToolkitSourceTest`・`SettingsPageSourceTest`)が、src/の`.php`以外のファイル(`.DS_Store`等)の数だけアサーションを数えていたのを直し、手元とCIでユニットテストのアサーションの数が揃うようにした。
- examples/sample-siteに対するE2E(Playwright。`tests/E2E/`・`playwright.config.mjs`、`composer test:e2e`)を足し、フォーム・設定ページ・サムネイル・日本語対応・ACF・ブロックの画面と応答と、全ページのPHPのエラーの表示とpageerrorが無いことを確かめる。`@playwright/test`をルートのpackage.jsonで1.64.1に固定し、CIのSample Site QAのジョブで、seed(`composer wp:setup`)の後に実行して、失敗したときだけtraceを保存する。
- CIのUnitのジョブとCoverageのジョブのユニットテストを`--testdox`で実行し、ログにテストの名前を出す。
- tmswp/testingに`WpCliPharDeprecations`を足した。本物のWP-CLIのプロセスの標準エラーから、WP-CLIのphar自身が出したPHPの非推奨の行だけを取り除く(WP-CLI 2.12.0に同梱のphp-cli-toolsがPHP 8.5で出す非推奨への対処。WP-CLIがPHP 8.5に対応したら外す)。WP-CLIのエラーの出力を照合する統合テスト(`wp tmswp discover`・`migrate`・`thumb`)が使う。
- docs/00〜10の受け入れ基準とdocs/12の各版の受け入れ条件の454項目を、根拠のテストに対応させた表を`docs/acceptance/v0.9.0.md`に作り、WordPressに依存するのに統合テストの根拠が無かった項目に統合テストを足した(Formの確認・送信トークンの改ざんと期限切れ・エスケープ・一時ファイルの保管と削除の失敗、タームとユーザーのメタの契約と読み取りの失敗、子テーマ、本番の遅延bootのキャッチアップ、フックのメソッドの失敗、拡張の有効化の時の起動、Log、入れ子のルート、`wp tmswp discover`・`migrate`・`thumb`・`acf`のコマンドを本物のWP-CLIで実行する等)。ユニットテストで主張の一部しか確かめていなかった項目(Logの補間・トレース・ローテーション、acf-bridgeのE001・E004・E005・W009・`AcfNotActiveException`の文言、gcの削除の失敗の後の続行、docs/05のシグネチャと実装の照合)にユニットテストを足し、手作業のgrepで確かめていた「〜が無い」の条件(コマンド名での`wp-env`の呼び出し、actions/規約の痕跡、偽のACF、拡張の旧API、acf-bridgeの実行時のファイルの書き込みとacf-jsonの走査、マジックメソッドと`extract()`、予約表に無いグローバル関数、coreのソースの`acf`の文字列、`UnitTestCase`を継承しないユニットテスト、`declare(strict_types=1)`の無い手で書いたPHPファイル、composer.jsonの依存とライセンスとdocs/01 §3.1の表との一致)を、ファイルを走査するユニットテスト(`tests/Unit/RepositoryScanTest.php`・`PackageManifestTest.php`)にした。wp-envをコマンド名で呼ぶことの検査は、E2EのJavaScript(`tests/E2E/`・`playwright.config.mjs`)も対象にする。docs/05のシグネチャ(引数の既定値の値を含む)と実装の照合は、設計書を読むため、分割したパッケージには入らないルートの`tests/Unit/Docs/FormSignatureDocumentationTest.php`に置いた。`phpunit.integration.xml.dist`に`failOnRisky`・`failOnSkipped`・`failOnIncomplete`・`failOnEmptyTestSuite`を足し、統合テストのrisky・skip・incomplete・0件の実行を失敗にした。
- CIに`mysql`のジョブ(MySQL 8.4 LTS・9.7 LTSの2セル。PHP 8.3・WordPress 7.0)を足した。servicesで立てたMySQLをwp-envのネットワークに参加させ、wp-envのcliから`WP_TESTS_DB_HOST`で接続して統合テストを実行する。テストの前に`SELECT VERSION()`で接続先がそのセルのMySQLであることを確かめる。予約語の一覧を接続したDBの`information_schema.KEYWORDS`の全語と照合する統合テスト(`IdentifierReservedWordIntegrationTest`)と、組込の全ての列の型と`rawColumn()`が受け付ける型の作成と再適用で差分が残らないことを確かめる統合テスト(`MigratorColumnTypeIntegrationTest`)を足した。この2つは、wp-envのMariaDBとMySQLの2セルのすべてで走る。`rawColumn()`が受け付ける型の定義は、ユニットテストと統合テストで`packages/core/tests/Support/RawColumnTypes.php`を共有する。Migratorを使う統合テスト(`ModelIntegrationTest`・`MigratorIntegrationTest`)は、MySQLでも通るよう`useRealTables()`で実際のテーブルを作る。DDLの暗黙のコミットで、`ModelIntegrationTest`の1つのテストが作った利用者2人と更新の確認のtransientが次のテストに残っていたのを、そのテストの後始末で消すようにした。`WpdbTestDoubleParityTest`の0件のINSERT ... SELECTは、MySQLが1つの文で同じ一時テーブルを2回参照できないため、空の別のテーブルから選ぶ形にした。スターターの統合テスト(`StarterProjectsIntegrationTest`)が、テストごとに置き場の変わるテーマの写しのpatternのキャッシュ(サイトのtransient`wp_theme_files_patterns-*`)を開発用サイトに残し、実行のたびにoptionが4行増えていたのを、そのテストが作ったテーマの名前の分だけ消すようにした。同じテストが開発用サイトに作るテーマのtheme_mods(`theme_mods_client-theme`等)も、テストの前の状態(無ければその名前の行を消し、あれば値とautoloadを戻す)に戻す。

## v0.8.0 — ブロック・スターター・配布導線・AI向け文書

### 追加

- 独自ブロックの自動登録を足した。探索コマンド(`wp tmswp discover`)とdebugのbootが、テーマ・プラグインのルートの`build/blocks/`以下(入れ子を含み、ソースの`blocks/`は含まない)の`block.json`を走査し、Kernelが`init`の10で`register_block_type()`に登録する。
- ブロックの探索の誤りを例外にした。Kernelの中のブロック名の重複・未登録のカテゴリ・壊れたJSON・nameの形の誤りは`DiscoveryException`、Kernelをまたぐ名前の重複・コア等との重複・探索結果ファイルと食い違うnameは`KernelException`になる。
- `KernelConfig::theme()`・`plugin()`の`blockCategories`(`Block\BlockCategory`)でブロックカテゴリを登録し(重複とコアのスラッグは例外)、`PostTypeDefinition::allowedBlocks()`で投稿タイプごとに使えるブロックを指定できる。allowedBlocksは`allowed_block_types_all`でその投稿タイプにだけ反映し、先に動いたフィルタの制限は広げない(builtinの投稿タイプでは呼べない)。
- 置き場・npmのビルドと探索コマンドの連結・対象外(ブロックテーマとACF Blocks)を書いたpackages/core/rules/blocks.mdを新設した。
- テストの支援コードのパッケージ`tmswp/testing`(packages/testing、名前空間`TMSWP\Testing\`)を新設し、`UnitTestCase`・`IntegrationTestCase`・`UsesTempDirectories`・`RecordingLogger`・`BootsKernelBeforeInit`・`MemoryMetaStorage`・`MemoryOptionStorage`・`StorageExtension`・`MetaStorageContract`・`MetaStorageContractTestCase`・`WpdbTestDouble`を`packages/core/tests/Support`(名前空間`TMSWP\Core\Tests\Support\`)から移した。各パッケージはrequire-devで使う。
- 各パッケージ(packages/*)にLICENSE(GPL-2.0-or-later)・phpunit.xml.dist・.gitattributesと、composer.jsonのrequire-dev・`composer test`を置き、単独で取り出して`composer install && composer test`を実行できるようにした。.gitattributesのexport-ignoreで、各パッケージのtests/・phpunit.xml.dist・.gitattributes・.DS_Storeと、starters/theme・starters/pluginのnode_modules/・.DS_Storeを、配布物とpathリポジトリの写しから外す。
- `wp tmswp discover`に`--kernels=<count>`を足した。起動中のKernelの数が指定と違えば、走査せずにエラーで終わる(起動しなかったテーマ・プラグインを見逃さないため)。
- 拡張パッケージのレシピ(packages/ja-toolkit・settings-page・testing・thumbnailの`rules/*.md`)を置いた。

### 変更

- 探索結果ファイル(`tmswp-discovery.php`)の形式をschema 5にし、`blocks`(name・path・metadataHash)を足した。既存の探索結果ファイルは`wp tmswp discover`で作り直す必要があり、`block.json`だけを変えて作り直さないと`wp tmswp discover --check`が失敗する。
- スターターの`bin/setup.php`を作り直し、スラッグから作ったStudlyCaseの名前空間で、composer.jsonのpsr-4とname(`local/<slug>`)、本体ファイルの名前(`plugin.php`→`<slug>.php`)、app/の名前空間、AGENTS.md、ブロック名を書き換えて、最後に自分を消すようにした。値は`--slug`・`--name`、環境変数`TMSWP_SETUP_SLUG`・`TMSWP_SETUP_NAME`、対話の順に探し、端末が無く値が欠けていればエラーで止まる。
- スターターのpost-create-project-cmdを`setup.php`と`composer dump-autoload`の2つにし、`config.autoloader-suffix`を生成した名前空間にした。同じ版のスターターから作ったプラグイン2本を同じサイトで有効にしても、オートローダのクラスが重複しない。
- スターターのcomposer.jsonのlicenseをGPL-2.0-or-laterにし、`setup.php`が作ったプロジェクトのlicenseを`proprietary`にする。スターターのラベル言語の既定を日本語にした(`tmswp-kernel.php`の`labelLanguage`)。
- テーマのスターターにブロックの例(`blocks/example`)とビルドした出力(`build/blocks`)を置き、package.jsonの`build`でビルドと探索コマンドを続けて実行する。各スターターの探索結果ファイルをコミットし、スターターの`composer qa`に`discover:check`を入れた。
- `KernelConfig`のTMSWPの予約の判定と、Kernelの名前空間の重複・入れ子の判定を、大文字と小文字を区別しない比較にした。
- packages/*とstarters/*の`tmswp/core`の制約を`^0.1`から`^0.2`にし(`Kernel::VERSION`は0.2.0)、各パッケージのbranch-aliasを`dev-main`→`0.2.x-dev`にした。
- フォームがPOSTで送る項目の名前が、WordPressの公開のクエリ変数(`$wp->public_query_vars`に`query_vars`フィルタを通したもの。登録した投稿タイプ・タクソノミーのquery_varを含む)とぶつかると、`Form::open()`と`Form::handle()`がdebugに関係なく`FormConfigException`を投げる。ぶつかる名前の送信は、WordPressがクエリ変数として読み、テンプレートに届く前に404になるためである。ファイルの項目は`{name}__token`を照らす。管理画面(`is_admin()`。admin-ajax.phpを含む)では検査しない。利用側では、`name`・`year`等の公開のクエリ変数と同じ項目名を、`full_name`等の別の名前に変える。rulesとdocs/05の例の項目名も`full_name`にした。
- スターターの`bin/setup.php`が、作ったプロジェクトのcomposer.jsonのdescriptionを`<表示名> (WordPress theme)`(プラグインは`(WordPress plugin)`)にし、テーマのpackage.jsonとpackage-lock.jsonの名前をスラッグにする。
- スターターのphp-cs-fixerの設定を、monorepoと同じ規則(PER Coding Style 2.0と厳密性の規則)にし、`friendsofphp/php-cs-fixer`の制約を`^3.88`にした。プラグインのスターターの設定は、`bin/setup.php`の前後のどちらでも実在するファイルだけを参照する。
- テーマのスターターのpackage.jsonの`allowScripts`で、ビルドと実行に要らない依存のインストールスクリプトを拒否した。
- テーマのスターターとサンプルサイトの`@wordpress/scripts`を、36.1.0から37.0.0に上げた。破壊的な変更は`npm run start`の開発サーバー(webpack-dev-server 6)だけで、独自の`devServer`の設定がある場合に移行が要る。ビルドの出力は変わらない。`npm ci`の非推奨の警告は6件から4件に、`npm audit`は15件から12件に減った。残りは`@wordpress/scripts`の依存によるもの。
- プラグインのスターターの本体ファイルのヘッダーに`Domain Path: /languages`を足した。
- coreと拡張のレシピ(packages/*/rules/)・README、ルート・スターター・サンプルサイトのAGENTS.md・README.mdを、v0.8.0までに確定したAPIに合わせて書き直した。
- examples/sample-siteを、テーマのスターターから作り直した。フォーム・設定ページ・サムネイル・日本語対応・ACF・ブロックの例を置き、`tmswp-kernel.php`の`KernelConfig`で拡張を登録する。専用のwp-env(ポート8882)で動かす。composer.lockはコミットしない。

### 削除

- スターターのCLAUDE.mdを削除した。

### 修正

- 複合フィールドのビュー(`part()`を通さない`FieldView`)の`label()`が`FormConfigException`になり、確認画面が止まる不具合を直した。`label()`は、部品のビューなら部品のラベル、複合フィールドのビューなら複合フィールドのラベルを返す。`labelTag()`・`id()`・`name()`・入力部品・`hidden()`は、これまでどおり`part()`を求める。

### 開発

- スターターから作ったテーマ1つとプラグイン2本が、同じサイトで別々のKernelとして起動し、フロントがHTTP 200を返すことを確かめる統合テスト(tests/Integration/、testsuite starters)を足した。
- テストの一時ディレクトリを、テストごとに専用の親ディレクトリの下に作るようにした。探索結果ファイルが`sys_get_temp_dir()`の直下に残らず、PHP 8.3と8.5のユニットテストを並列に実行しても干渉しない。
- split.yml・docs/RELEASING.mdの同期先を8つ(tmswp-testingを追加)にし、docs/RELEASING.mdのリリース手順を、`monorepo-builder release`が自らmainとタグをpushする動作に合わせた。CHANGELOG.mdを新設した。
- unitとintegrationの合算カバレッジの全体の閾値(composer.jsonの`test:coverage:check`の第1引数)を、v0.8.0の値の82%に上げた(パッケージ別の80%は変えない)。
- ユニットテストを、srcの`<Dir>/<Class>.php`に対応する`tests/Unit/<Dir>/<Class>Test.php`に置き直し、規則ごとのテストとデータプロバイダに分けた。アップロードしたファイルの実体の検査(FileExtension)のテストを足した。
- CIの外部アクションをNode.js 24で動く版にしてSHAで固定し、全ジョブのrunnerを`ubuntu-24.04`に固定した。サンプルサイトの`composer qa`のジョブと、スターターから`composer create-project`で作ったプロジェクトの`composer qa`のジョブを足した。
- `@wordpress/env`を12.0.0にし、ルートのpackage.jsonの`allowScripts`で、要らないインストールスクリプトを拒否した。
- unitとintegrationの合算カバレッジを、全体に加えて、testingを含む6パッケージ別(各80%)にも判定する。`bin/coverage-check.php`は、丸める前の値で閾値と比べる。
- `composer discover:build`・`discover:check`が、fixtureのKernelの数(2)を確かめる。fixtureのWP-CLIの読み込みは、site transientの`theme_roots`に左右されない。
- tools/extract-rules-code.phpが、`final readonly class`等の修飾子の付いたクラス名を読む。PHPStanのワーカーごとのメモリの最大量を測るtools/phpstan-memory.phpを足した。
- coreの訳ファイル(.pot・ja.po)の参照の行番号を、Form.phpの今の行に合わせて作り直した。
- スターターのセットアップのテスト(端末の場合)で、標準出力・標準エラーを、ptyではなくパイプで受け取るようにした。Linuxでは、子プロセスの終了後にptyを読むとEIOになり、PHPがE_NOTICEを出すため、CIのユニットテストでNoticeが出ることがあった。
- ユニットテストの設定(ルートのphpunit.unit.xml.distと各パッケージのphpunit.xml.dist)に、`failOnNotice`と`displayDetailsOnTestsThatTriggerNotices`を足した。Noticeは、テストの失敗になり、中身が表示される。
- フォームの統合テストのHTTPの取得を、WordPressのHTTP API(`wp_remote_get`)に変え、PHP 8.5で非推奨の`$http_response_header`を使わないようにした。

## v0.7.0 — settings-page・thumbnail・ja-toolkit

### 追加

- thumbnailの`RestRouteCheck`が、訪問者からRESTのルートが使えないことを検出して管理者に通知する。REST APIが使えることをthumbnailの利用条件にした。

### 変更

- settings-page・thumbnail・ja-toolkitの入口のクラス(`SettingsPages`・`Thumbnail`・`JaToolkit`)を`ExtensionInterface`の実装にし、`KernelConfig`の`extensions`にインスタンスを渡したKernelでだけ有効にした。1つのインスタンスは1つのKernelにだけ登録でき、同じクラスを同じKernelに2つ渡すと`KernelException`になる。
- settings-page:app/の外のページはコンストラクタに渡し、`admin_menu`で`definition()`を評価する。slugの重複は、起動したすべてのKernelをまたいで検出する。
- settings-page:フォームの外枠(wrap・見出し・通知・`$form->open()`・保存ボタン・`$form->close()`)とエラー通知をFWが描画し、`render()`は項目だけを描く。coreのFormに読み取り専用の`Form::isConfirmEnabled()`を足し、確認画面の有効なフォームは`handle()`の前に`LogicException`にする。
- settings-page:`OptionFormBridge::persist()`はスキーマの型に変換してから保存し、変換できない値と`$only`の未宣言の名前は`LogicException`にして何も保存しない。`SettingsPage::url()`は生のURLを返し、gettextのソース文字列を英語にしてja訳を同梱した。
- thumbnail:登録できるKernelをサイトに1つにし(2つ目と、登録が無い状態の`tms_thumb()`は`LogicException`)、`url()`・`tms_thumb()`は`?string`を返す。画像が無いときはdebugでもnull、引数・オプションの誤りはdebugに関係なく例外、実行時の失敗はdebugなら例外、本番ならKernelのロガーに記録してnullを返す。
- thumbnail:RESTが既存のファイルを返すたびにmtimeを更新し、生成済みのファイルへの302を`public, max-age={redirectMaxAge}`(既定3600秒)にした。`wp tmswp thumb clear`のGCは`--days`より古いファイルだけを消し、キャッシュのディレクトリの印のファイル`.tmswp-thumb-cache`とキャッシュ自身が作ったファイルだけを扱う。
- thumbnail:署名の対象を`serialize([src, w, h, o])`にし、鍵を`wp_salt('auth')`からHKDFで導出する。`ThumbnailConfig`の全項目をコンストラクタで検証し、`cacheDirName`を1つの安全な名前に限った。
- ja-toolkit:モジュールはインスタンスを可変長引数で渡し、モジュールが0個の`new JaToolkit()`でも訳を読み込む。JapaneseSlugは許可リストの投稿タイプだけを対象にし、最悪の長さが200を超えるformatを`LogicException`にする。
- ja-toolkit:全角・半角のスペースだけの検索語は、`is_search()`を真のまま0件にする。KanaTextFieldの違反のメッセージは、新設の`KanaRule`で検証の時点に解決する。

### 削除

- 静的なAPIの`SettingsPages::register()`・`isRegistered()`・`registerPage()`・`reset()`、`Thumbnail::register()`・`reset()`・`isRegistered()`・`config()`、`JaToolkit::enable()`・`isEnabled()`・`reset()`を削除した。

### 修正

- settings-page:`add_submenu_page()`のfalseを、権限が無いため登録しなかった正常な結果として扱い、権限の無いユーザーのページ表示とPOSTを`wp_die()`の403で拒否する。
- thumbnail:生成を一時ファイルへの保存と`rename()`とキー単位の`flock`で行い、URLの値は`rawurlencode`してから埋め込む。拡大の要求は元画像の寸法で頭打ちにし、画像エディタが扱えない元画像は再生成しない。
- `wp tmswp thumb clear`の`--days`の誤りを、何も消さずに`WP_CLI::error()`(終了コード1)で返し、synopsisを`[--days[=<days>]]`にした。値の無い`--days`がキャッシュを全件消す不具合と、上限(`ThumbCache::maxOlderThanDays()`)を超える日数のTypeErrorを直した。

### 開発

- docs/08の受け入れ基準(CSRF・権限の拒否・PRG・RESTの実応答・CLI・二重登録)をユニット・統合テストで確かめた。packages/core/rules/extensions.mdの登録例を`KernelConfig`の`extensions`に渡す形にした。

## v0.6.0 — acf-bridge

### 追加

- coreに`KernelConfig::load()`を足し、テーマ・プラグインのルートの`tmswp-kernel.php`がKernelConfigを返す形にした。functions.php・プラグインの本体と`tms-acf`が同じ構成を読む。
- WordPressを起動しない`vendor/bin/tms-acf`(syncとcheck、`--dry-run`・`--strict`・`--root=`)を足した。WP-CLIの`wp tmswp acf`と同じ処理(SyncRunner)を使う。
- `Kernel::defer()`を拡張の公開APIにした。boot中に発火済みのフックへ予約したものも、キャッチアップで実行する。
- 単一のテンプレートを条件に持つACFのグループを、テンプレート別のクラス`{Studly}TemplateFields`に出す。locationのpost_templateに対応し、locationのblockは非対応としてW006で案内する。
- packages/acf-bridge/rules/sync-check.mdとREADMEを新設し、packages/core/rules/acf.mdを書き直した。

### 変更

- `AcfBridge`を`ExtensionInterface`の実装にし、static状態と`boot()`・`config()`・`reset()`・`registerManagedKeys()`を削除した。`KernelConfig`の`extensions`に`new AcfBridge(?BridgeConfig)`を渡し、テーマとプラグインで別々に登録する。
- 対応するACFの下限を6.2にし、6.2より古いACFが有効なら例外にする。`acf/json/save_paths`で、ACFが実際に読み込んだファイルが自分のacf-jsonにあるときだけ、そこへ書き戻す。
- `AcfMetaStorage`は、値を`WpMetaStorage`で読み書きし、参照のメタ`_{キー}`にfield keyを書いてACFの値のキャッシュを消す。扱えるのは、ACFの`update_value`が値を変えない単純な型だけで、ほかの型の書き込みは`AcfBridgeRuntimeException`にする。
- `AcfFieldAccess`の型強制で未設定(null・false・'')と形の不一致を区別し、形の不一致はdebugで例外、本番でロガーに記録して未設定の値を返す。`has()`はメタの行の有無で判定する。
- 生成アクセサはfield key(`KEY_`定数)でACFを呼び、keyのフィールドがACFに登録されていなければ例外にする。名前で呼ぶのは、traitの`value()`・`rawValue()`・`update()`・`delete()`・`has()`だけにした。
- 生成される`of()`と`AcfMetaStorage`の全メソッドは、IDが1未満なら`InvalidArgumentException`にする。traitのメソッド名との衝突(大文字小文字を区別しない)・`class`・整数のキーになるサブフィールド名は生成時のE002にする。
- 同じkeyのacf-jsonはjsonDirsの並び(テーマの既定では子テーマが先)で先勝ちにし、負けた側をW010で報告して、実行時のACFも生成と同じファイルを使う。テーマのjsonDirsの既定は`{ルート}/acf-json`だけにした。
- cloneの展開をACF Proの展開(保存名・ラベル・cloneのcloneの名前)を再現する形にし、displayの既定をseamlessにした。`KEY_`定数と管理キーは元のkeyを使う。
- date_time_pickerで同じ現地の時刻が2つの瞬間を表すときは早い方に解決し、return_formatがarrayのcolor_pickerで値の無いときの`rgba(0,0,0,0)`はnullにする。flexible_contentのレイアウトをJSONの順のlistにし、shapeを`@phpstan-type`の別名にした。

### 削除

- `PreviewAcfSupport`と`BridgeConfig`の`previewMeta`を削除した(プレビュー時の値はACF標準の保存に任せる)。I001と`type_map`フィルタを仕様から外した。

### 開発

- 全31型のfixtureから生成したアクセサを実物のACFで確かめるAcfFieldTypesIntegrationTestと、ACF Proのzipで走らせる`composer test:integration:acf-pro`を足した。`composer acf:check:fixtures`を`composer qa`とCIに入れた。

## v0.5.0 — Model・Migrator・テンプレート前処理

### 追加

- `wp tmswp migrate`(`--dry-run`・`--force`)を足し、結果の`action`をenumの`MigrationAction`(Migrated・Skipped)にした。テーブル定義のハッシュが保存済みのものと食い違うときは、`manage_options`を持つ利用者にだけ`admin_notices`で通知する。
- `Model::query()`や`new QueryBuilder`から始まるチェーンの`get`・`first`・`Model::find`の行の型を、`as()`か`rowClass()`の宣言型で絞るPHPStan拡張を足した。

### 変更

- マイグレーションは`wp tmswp migrate`だけで適用し、公開の`migrate()`を削除した。起動時・プラグインの有効化・`after_switch_theme`・`admin_init`・ajax・cronでは適用しない。
- dbDeltaの後に、実際の構造(NULLの可否・既定値・照合順序・主キー・索引)を定義と照らし合わせ、食い違えば`MigrationException`にしてハッシュを更新しない。
- TableDefinitionで、予約語のカラム名・インデックス名、charsetの無いcollation、`rawColumn()`の型の別名(`json`等)とdbDeltaが収束しない表記を、宣言の時点で`SchemaException`にする。JSONの列は`json()`(longtext)で宣言する。
- `rowClass()`の既定をnull(行はstdClass)にし、Modelのコンストラクタを引数なしの`final public`にした。Modelのクラスは行のクラスにできず、`as()`・`rowClass()`・QueryBuilderのコンストラクタで例外にする。
- `update`・`delete`・`count`をdocs/06のシグネチャに合わせ、`useConnection()`を全Modelに共通にしてnullで解除できるようにした。どのKernelにも属さないModelは、`useConnection()`を案内する例外にする。
- Connectionの`insert`・`update`・`delete`とQueryBuilderの`update`でNULLを代入でき、テーブル名はベア名だけを受け付ける。プレースホルダは`%d`・`%f`・`%s`・`%i`・`%%`だけを受け付け、`value()`・`pluck()`は既存のSELECT句を保って結果に無いキーを例外にする。
- `Template`は、どのクラスにも束縛しないクロージャでincludeし、テンプレートから見える変数を`$args`だけにした。`Template::part()`は、slugの検証の後に`get_template_part_{$slug}`と`get_template_part`を発火してからテンプレートを解決する。
- `TableDefinition::toSql()`の索引の行を、PRIMARY KEY → UNIQUE KEY → KEYの順に出す。KEYをUNIQUEより先に宣言したテーブルはハッシュが変わり、管理画面に通知が出るため、`wp tmswp migrate`をもう一度実行する。
- Emailルールが、国際化ドメイン名のメールアドレスとローカル部に非ASCIIを含むメールアドレスを違反にすることをdocs/05に明記した。

### 削除

- テンプレート前処理のActionRouter・View・NotFound・`tms_var()`・NoOutputRule・NoSet404Rule・tms-checkと、`KernelConfig`の`actions`の引数と、rules/actions.mdを削除した。代わりに、テンプレートの先頭で前処理クラスを明示的に呼ぶ方式のレシピrules/page.mdを新設した。

### 修正

- `Connection::prepare()`の結果を`remove_placeholder_escape()`に通して返す。DTOの日時の変換で、NULを含む値を`HydrationException`にした(今まではValueErrorがそのまま出ていた)。
- Kernelのフックのメソッドの失敗のログの文脈を、許可したキー(`class`・`method`・`hook`・`exceptionClass`・`exceptionAt`・`previousClasses`)だけにした。

### 開発

- WpdbTestDoubleの`prepare()`を実wpdbに合わせ、両者を比べるWpdbTestDoubleParityTestを足した。docs/06・07の受け入れ基準を直接確かめるテストを足し、rules/model.mdを書き直した。

## v0.4.0 — Form

### 追加

- `SpamGuardInterface`と`FormDefinition::spamGuard()`を足し、honeypotとTurnstileの手本をrules/form.mdに置いた。`handle()`と`open()`で`nocache_headers()`と`DONOTCACHEPAGE`を付ける。
- `Form::confirmFields()`・`FormDefinition::hideOnConfirm()`・チェックボックスの`checkedLabel`を足した。`open()`のaction、入力のid、`labelTag()`のforを上書きできる。
- `JtiStore::isClaimed()`を足し、一時ファイルが消えた後の確認トークンの再送を拒否する。`RequiredIf::equals`をcheckboxesの値に対応させ、`MaxFileSize::mb()`にmessageの引数を足した。
- Urlルールで、ホスト名の国際化ドメイン名を`idn_to_ascii()`(UTS #46)で変換してから判定する。ext-intlが無い環境では、Urlのコンストラクタが`FormConfigException`を投げる。
- rules/form.mdを書き直し、rules/form-mail.md(ヘッダインジェクションの対策・自動返信・添付・送信の失敗)を新設した。

### 変更

- 確認画面の無いフォームは`open()`で使い捨ての送信トークン(SubmitToken)を埋め込み、確認画面ありのフォームはConfirmTokenのjtiに一本化して、二重送信を防ぐ。トークンは`base64url(正準JSON).HMAC-SHA256`で鍵を用途ごとにHKDFで派生させ、`ConfirmTokenStatus`を`TokenStatus`に改名した。
- `JtiStore::claim()`は`JtiClaim`(Claimed・Duplicate)を返し、データベースのエラーは例外にする。onCompleteの例外は、debugでは例外、本番ではFormのKernelのロガーに記録して送信失敗のエラーを出し、同じトークンで再送できる。
- 確認画面の有無にかかわらず、受け取ったファイルを`TempFileStore`に保管してonCompleteには常に`StoredFile`を渡し、onCompleteが正常に終わったら消費されなかった一時ファイルを消す。一時ファイルの保存名は固定の`file`にし、gcはWP-Cronの毎時のイベント(TempFileGc)だけで行う。
- `FormRequest::fromGlobals()`は例外を投げない。数値のキー、配列のファイル、不正な形のファイルのエントリ、不正なUTF-8の入力は、その項目のエラーにする。
- パスワードの項目は再描画・確認画面・トークンに出さず、確認画面ありのフォームに置いたら定義の時点で例外にする。FWが決める属性(method・enctype・name・checked等)の指定は`FormConfigException`にする。
- メッセージの解決を`Messages::resolve()`に一本化し、フィルタの文字列以外の戻り値と、Callbackのboolと文字列以外の戻り値を`FormConfigException`にする。検証は「入力の不正 → アップロードの失敗 → 規則のエラー」の順にする。
- `applyDefaults()`は、`handle()`の後の呼び出しと、未知の項目・形の違う値を例外にする。`Field::checkbox()`の空の`checkedValue`は`FormConfigException`にする。

### 削除

- `FormHtml::escHtml()`を削除し、`esc_html()`を直接呼ぶ。UploadFileルールと、確率的な一時ファイルのgcを削除した。

### 修正

- `toAttachment()`は、フロントでもwp-admin/includesを読み込んでから`media_handle_sideload()`を呼ぶ。`.htaccess`をApache 2.4と2.2の両方の構文にした。
- 確認トークンの値をclaimの前に今の規則で検証し直し、単一のチェックボックスの`checkedValue`との一致・複合フィールドの部品の暗黙の選択肢・複数選択に直接渡した文字列を検証する。CSRFの失敗・改ざん・再送の拒否・ファイルの消失・onCompleteの例外を、フォームのIDと例外のクラス名・投げられた場所だけの文脈でログに残す。

### 開発

- docs/05の受け入れ基準の全項目にテストを対応させ、FormRuleTest・FormHtmlTest・FormStructureTestを新設した。

## v0.3.0 — エンティティ・メタ・オプション・検索

### 追加

- `markExternallyManagedInAllSubtypes()`を足し、サブタイプの文字列`'all'`の特別扱いをやめた。
- クエリのenumの`TermField`・`TermOperator`・`UserSearchColumn`と、任意の引数を足す`args(array)`・`withArgs(array)`を足した。
- `EntityDataException`を足し、`parents()`の深さの超過を実行時のデータの不整合として扱う(debugは例外、本番はKernelのロガーに記録して空配列)。
- メタ・オプションの既定のストレージを、拡張の`register()`で`MetaStorageInterface`・`OptionStorageInterface`のidに登録した実装に差し替えられる(bootの後の差し替えと、interfaceを実装しない登録は`ContainerException`)。`WpMetaStorage`・`WpOptionStorage`はコンストラクタで所属するKernelを受け取る。
- rules/user-role.md(認証のレシピを含む)を新設した。

### 変更

- メタ・オプションの書き込みの戻り値を、true(書き込んだ)・false(保存値が変わっていない)・`RuntimeException`(データベースのエラー)の3通りにした。キーと値を`wp_slash()`してから保存する。
- `null`の書き込みを拒否して宣言した型と値の型を検査し、`register_*_meta`がfalseを返したら例外にする。非公開の定数への`#[MetaSchema]`を拒否し、`#[OptionSchema]`をenumのケースだけに限った。
- `MetaBag::all()`はsingle=falseのキーの全行を返し、`deleteAll()`は実際に削除した名前だけを返す。falseを保存したBooleanのキーは`MetaBag::bool()`でfalseに戻す。
- メタスキーマをKernelのbootの中で登録し(`register_*_meta`はinit 20、ロールの登録はinit 11)、initの前でも`meta()`が使える。`meta()`に存在しない対象・スコープ外の対象を渡したら、debugに関係なく`InvalidArgumentException`にする。
- 単独の読み取りのデータベースのエラーは、debugでは例外、本番ではKernelのロガーに記録して既定値を返す。クエリの`WP_Error`は、debugでは`QueryException`、本番ではエンティティのKernelのロガーに記録して既定値を返す。
- 存在しないbuiltinの投稿タイプ・タクソノミー・ロールのアダプタを、`register()`で例外にする。`UserRole::register()`はKernelを受け取り、登録済みのロールとの差分をwarningで記録する。
- `PostQuery`・`TermQuery`・`UserQuery`がCompare・CastType・SortOrder等のenumを受け取り、空のIN指定は検索を実行せずに0件にする。件数を指定しない`get()`は全件、`first()`は常に1件を返し、矛盾する指定は`InvalidQueryException`にする。

### 削除

- WpBridgeを削除し、WordPressの関数を直接呼ぶ。オプションのnullのセンチネル(WpOptionNullValue)、QueryValidator、slugから作るクエリのファクトリ(`ofType`・`ofTaxonomy`・`ofRole`)を削除した。

### 修正

- Thumbnailのimg要素の属性を`Support\Html::attributes()`で組み立て、許可されないプロトコルのURLを拒否する。
- `create()`のslash(PostType・Taxonomy・UserRole)、`PostQuery::author()`の0と負の値、`#[WithMetaRevisions]`のプレビューの偽の値、`parents()`の祖先がちょうど100件の場合を直した。`orderByMeta()`はメタを持たないレコードを落とさずに並べる。

### 開発

- 統合テストでinitの前にKernelをbootする基盤(BootsKernelBeforeInit)と、`MetaStorageInterface`の契約テスト(`MetaStorageContract`・`MetaStorageContractTestCase`)を足した。

## v0.2.0 — core基盤

### 追加

- `wp tmswp discover [--check]`と、探索結果ファイル(`tmswp-discovery.php`)をビルド時に生成してコミットする方式を足した。`composer discover:build`・`discover:check`を足した。
- `ExtensionInterface::register(Kernel)`と`KernelConfig`の`extensions`で、拡張をKernelごとに登録する。同じKernelへの二重登録は例外にする。
- `KernelConfig`の`logChannels`でログのチャネルを宣言し、`labelLanguage`でラベル言語の既定をKernelごとに指定する。
- `TmswpExceptionInterface`を新設し、全パッケージの例外が実装するようにした。
- `Kernel::VERSION`を、読み込まれた全vendorのinstalled.phpのtmswp/coreの版と起動時に照合する。psr/container 2・psr/log 3と違う宣言を検出して例外にする。

### 変更

- 実行時のキャッシュ(ComponentCache)を廃止し、本番(debug=false)は探索結果ファイルだけを読んで、無ければ例外にする。app/の走査はdebugのときだけ行う。
- `label()`を`label(string $singular, ?string $plural = null)`にし、Enでは複数形を必須、Jaでは省略すると単数形を使う。WordPress 7.0のコアの全キーを出す。
- `Container::get()`に`@template`と条件付きの戻り値型を付け、型の合わない登録は登録の時点で例外にする。`has()`は`get()`と同じ規則で判定し、factoryの中の例外はidを含む`ContainerException`で包む。
- フックメソッドを`call_user_func_array`で呼び、`#[Filter]`は最低1個の引数で登録する。フックメソッドの実行時の失敗は、本番ではKernelのロガーに記録してそのメソッドだけスキップし、Filterは元の値を返す。
- Initializerの失敗と属性を復元できない場合は、debugに関係なく例外にする。WordPress 7.0未満と、bootの途中からの別のKernelのbootを例外にする。
- debugの既定値を`WP_DEBUG`の真偽から取る。有効化と無効化は`rewrite_rules`の削除だけにした。
- LoggerRegistryをKernelごとに持ち、静的な`Log::*()`・`tms_log()`は呼び出し元のファイルを含むKernelに記録する。
- ログのファイル名に`wp_hash()`由来の接尾辞を付けた。書き込みの失敗はその行を`error_log`へ出し、例外のpreviousチェーンを出力し、1記録を1行にする。
- `Html`のURL属性に`esc_url`を適用し、許可されないプロトコルは例外にする。ヘルパの`function_exists`のガードを外した。

### 削除

- `Log::set()`・`Log::setRegistry()`、使われていない`StorageException`、`UnsupportedComponentException`・`UnsupportedComponentInitializer`を削除した。

### 開発

- `Kernel::reset()`をテスト以外から呼ぶことを禁じるPHPStanルールを足した。PHPStan拡張をReflectionProviderだけで実装し、解析中に利用者のコードを実行しない。

## v0.1.0 — 基盤の健全化

### 変更

- 対応範囲をPHP 8.3〜8.5、WordPress 7.0以上にした。全composer.jsonのPHPの下限を8.3にした。
- 翻訳ファイルの生成を、`composer i18n:build`(wp-envのWP-CLIによる`make-pot` → `update-po` → `make-mo` → `make-php`)にした。`.l10n.php`はWordPressが読む形式で生成し、ja-toolkitのpotの名前は`tmswp-ja-toolkit.pot`になった。
- Formのルールと`KanaTextField`の訳を`__('…', 'tmswp')`のリテラル呼び出しにし、`function_exists('__')`による代替経路を削除した。
- docs/11からレシピの複製を除き、レシピの正本を各パッケージのrules/にした。docs/12を版ごとのロードマップに書き換えた。
- read-only splitの送り先を`tmstack-io/tmswp-{パッケージ名}`の7リポジトリにした。

### 削除

- tools/build-l10n.phpを削除した。

### 開発

- `@wordpress/env`をpackage.jsonで11.17.0に固定し、.wp-env.jsonにPHP 8.3と無料版ACF 6.8.10を固定した。
- CIのunitをPHP 8.3・8.5、integrationをPHP 8.3・8.5 × WordPress 7.0・最新版の4セルにした。外部アクションをコミットのSHAで固定した。
- 統合テストを、fixtureのテーマとプラグインをinitの前にbootする経路で実行し、偽のACF関数をやめてwp-envの無料版ACFで検証する。偽wpdbをWpdbTestDoubleに一本化した。
- unitとintegrationのcloverを行単位で合算してカバレッジを判定する`coverage`ジョブを足した。
