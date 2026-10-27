# tmswp/testing

TMStack WordPress Frameworkのパッケージと、TMSWPを使うテーマ・プラグインのテストで使う支援コードです。開発用の依存として入れます(`composer require --dev tmswp/testing`)。

## 提供するもの

| 名前 | 役割 |
|---|---|
| `UnitTestCase` | ユニットテストの基底。Brain MonkeyとMockeryの検証を全テストに保証し、翻訳関数をソースの文字列を返すスタブにする。`createTempDirectory()`と、別のPHPのプロセスに渡す`composerAutoloadFile()`を持つ |
| `IntegrationTestCase` | monorepoの統合テストの基底(`WP_UnitTestCase`を継承する)。monorepoの`tests/bootstrap-integration.php`がfixtureのテーマ・プラグイン(`tests/fixtures/`)を有効にして起動したWordPressの上で動く |
| `UsesTempDirectories` | テストごとの一時ディレクトリを、テストごとに専用の親ディレクトリの下に作り、テストの後に親ディレクトリごと消す |
| `BootsKernelBeforeInit` | 統合テストで、`init`より前の時点のKernelの起動を再現する |
| `RecordingLogger` | 書かれたログを記録するPSR-3のロガー |
| `MemoryMetaStorage`・`MemoryOptionStorage`・`StorageExtension` | メタ・オプションの保存先をメモリに差し替える |
| `MetaStorageContract`・`MetaStorageContractTestCase` | `MetaStorageInterface`の実装が契約を満たすかを確かめるテストの共通部分 |
| `WpdbTestDouble` | ユニット層用の`wpdb`の代役。実行したSQLを記録し、読み取りの結果をキューから返す |

## 依存

`tmswp/core`のほか、PHPUnit 11・Brain Monkey・Mockery・psr/logを依存として持ちます。`IntegrationTestCase`・`MetaStorageContractTestCase`・`BootsKernelBeforeInit`が使うWordPressのテストライブラリ(`WP_UnitTestCase`と、それが使うPHPUnit 9.6)は依存に含みません。

## 開発

このパッケージは[tmstack-io/tmswp](https://github.com/tmstack-io/tmswp)のmonorepoで開発しています。単独で取り出したときは、`composer install && composer test`でユニットテストを実行できます。
