# tmswp/core

TMStack WordPress Framework のコアパッケージです。

## 利用条件

### 同じサイトでは同じ版の tmswp/core を使う

テーマとプラグインがそれぞれ `vendor/` に tmswp/core を同梱する場合、PHP は先に読み込まれた側のクラスだけを使います。そのため、同じサイトのテーマ・プラグインは同じ版の tmswp/core を同梱してください。

`Kernel::boot()` は、読み込まれたすべての Composer の vendor の `installed.php` にある tmswp/core の版を `Kernel::VERSION` と照合し、食い違えば `KernelException` で止まります。メッセージには、食い違う版と、その vendor の install path が含まれます。dev 版(`dev-*` のブランチや path repository 等)は照合の対象外です。

### 古い psr/container・psr/log を先に読み込む構成では動かない

tmswp/core は psr/container ^2.0 と psr/log ^3.0 の宣言に合わせて `Container`・`LoggerRegistry` 等を実装しています。PHP の interface は最初に読み込んだ定義で固定されるため、他のプラグインや WP-CLI の phar が古い psr/container(1.x)・psr/log(1.x・2.x)を TMSWP より先に読み込む構成では動きません。

`Kernel::boot()` はこれを起動時に検出し、Fatal error の代わりに次のような `KernelException` で止まります。メッセージには、その interface を定義したファイルのパスと、必要な版が含まれます。

```
TMSWP requires psr/container ^2.0, but Psr\Container\ContainerInterface loaded from phar:///usr/local/bin/wp/vendor/psr/container/src/ContainerInterface.php is incompatible (...)
```

検出されたときの対処:

- ファイルのパスが `phar://` で始まる場合は、WP-CLI の phar が同梱する psr/container 1.0 が先に読み込まれています。TMSWP の Composer のオートローダを、WP-CLI の `--require` や `wp-cli.yml` の `require` で早く登録せず、テーマの functions.php・プラグインの本体ファイルの読み込み時に登録してください(WP-CLI の phar は `--require` の後に自身のオートローダを先頭に登録します)。
- 他のプラグインのファイルの場合は、そのプラグインを更新するか、TMSWP を使うテーマ・プラグインと併用しないでください。
