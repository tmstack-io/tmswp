# 08. 拡張パッケージ仕様 — thumbnail / settings-page / ja-toolkit

## 目的

コア(tmswp/core)に収載しない付帯機能を、composerで個別導入できる3つの公式拡張パッケージとして確定する(DR-06/DR-10/DR-14/DR-15)。本書だけを読めば各パッケージをゼロから実装できる粒度で、公開API・振る舞い・エッジケース・受け入れ基準を定義する。

## スコープ(対象パッケージ)

| パッケージ | 名前空間 | monorepo配置 | 概要 |
|---|---|---|---|
| tmswp/thumbnail | `TMSWP\Thumbnail\` | packages/thumbnail | 署名付きURL+オンデマンド生成のサムネイル(timthumb全面置換) |
| tmswp/settings-page | `TMSWP\SettingsPage\` | packages/settings-page | Form層ベースの管理画面設定ページ |
| tmswp/ja-toolkit | `TMSWP\JaToolkit\` | packages/ja-toolkit | 日本語サイト対応のオプトインモジュール集 |

tmswp/acf-bridge は本書のスコープ外(専用文書が定義)。CSVインポート基盤は公式拡張として提供しない(DR-20。必要な案件はapp側で実装する)。

## 依存文書

- 01 Kernel/Discovery設計書 … `Kernel::boot()`、`Kernel::all()`(マルチKernel走査)、属性探索、`ComponentAttributeInterface` / `ComponentInitializerInterface`(拡張属性の正準契約。settings-pageが実装する)
- 03 エンティティ定義設計書 … `PostType::thumbnailUrl()` と `tmswp/post/thumbnail_fallback` フィルタ
- 04 Meta/Option設計書 … オプショングループ enum(`OptionAccess` trait)/ `OptionRepository`(settings-pageの保存先)
- 05 Form設計書 … `Form` / `FormResult` / `FormStep` / `TempFileStore`(settings-pageが利用)
- 06 Model/Log/View設計書 … `Log`(PSR-3)、`View\Template`

## パッケージ共通規約

全3パッケージに共通する規約。各パッケージ節では差分のみ記す。

### composer.json

```json
{
    "name": "tmswp/<package>",
    "type": "library",
    "require": {
        "php": ">=8.3",
        "tmswp/core": "^0.10"
    },
    "autoload": {
        "psr-4": { "TMSWP\\<Pascal>\\": "src/" }
    }
}
```

- パッケージ固有の追加require: thumbnail=`ext-gd`(DR-14でコアから移設)、ja-toolkit=`ext-mbstring`。settings-pageは追加なし。
- WPプラグインヘッダは持たない。各パッケージは「テーマ/プラグインのvendor/に入るライブラリ」であり、有効化はapp側の`KernelConfig`への明示的な登録(下記)で行う。

### 登録API(KernelConfig経由・明示オプトイン)

拡張は自動では何もしない。各パッケージの入口のクラス(`TMSWP\Core\Kernel\ExtensionInterface`の実装)のインスタンスを、テーマ・プラグインのルートの`tmswp-kernel.php`で`KernelConfig::theme()` / `plugin()`の`extensions`に渡す。拡張は、渡したKernelでだけ有効になる(文書02 §3.10)。テーマとプラグインがそれぞれ同じ拡張を登録した場合は、Kernelごとに別々に動く。

```php
// tmswp-kernel.php(functions.php / プラグイン本体は、このファイルのKernelConfigでKernel::boot()する)
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\SettingsPage\SettingsPages;

return KernelConfig::theme(
    __DIR__ . '/app',
    'App',
    extensions: [new SettingsPages()],
);
```

入口のクラスは、各パッケージの節に書く(thumbnailは§1.4の`Thumbnail`、settings-pageは§2.2の`SettingsPages`、ja-toolkitは§3.2の`JaToolkit`)。

- Kernelは、bootの中で`extensions`の各インスタンスの`register(Kernel $kernel)`を、渡した順に1回ずつ呼ぶ。
- 同じクラスの拡張を同じKernelに2つ渡すと、`KernelConfig`が`TMSWP\Core\Exception\KernelException`を投げる。1つのインスタンスを2つのKernelに渡すと、2つ目のKernelの`register()`が例外を投げる(1つのインスタンスは1つのKernelにだけ登録できる)。どちらも設定の誤りなので、debugに関係なく起動を止める。冪等な握り潰しはしない。設定差異の取り違えを早期に検出するためである。
- 拡張のdebugによる分岐は、登録したKernelの`config()->debug`に従う。`WP_DEBUG`を直接見ない。拡張の内部のログは、登録したKernelの`logger()`に記録する。

`register(Kernel $kernel)`が、`init`等のフックに処理を予約するときは、`add_action()`ではなく`$kernel->defer($hook, $priority, $callback)`を使う。本番の遅延boot(フックの発火後のboot)では`add_action()`で登録した処理が二度と実行されないが、`defer()`はKernelのキャッチアップの中で実行するためである(文書02 §3.3)。

拡張のコンストラクタは、WordPressの関数を呼ばない。WordPressに触れる処理は`register()`以降で行う。拡張は`tmswp-kernel.php`の中で`KernelConfig`と一緒に生成され、このファイルはWordPressを起動しない`tms-acf`(文書09 §5.1)も読むためである(文書02 §3.10)。

### 例外・ログ・i18n

- 汎用の宣言の誤り・型の誤り(設定の誤りや、拡張に固有の情報を持たない実行時の失敗)は、coreの`TMSWP\Core\Exception\LogicException`・`RuntimeException`を直接使う。中身の無いラッパーの例外クラスは作らない。
- 拡張に固有の例外は、各パッケージの`TMSWP\<Pascal>\Exception\`配下に置き、`TMSWP\Core\Exception\RuntimeException`(または`LogicException`)を継承する。
- ログは、受け取った Kernel の `logger()`(PSR-3)に記録する(Kernel設計書§3.10、View/Support/Log設計書§5.1)。`echo` によるログ出力は全パッケージで禁止。
- テキストドメインは `tmswp`(DR-17)。拡張独自ドメインは作らない。

### QA

コアと同一のmonorepoでCIを共有する(DR-11/DR-12)。`composer qa` でPHPStan level max・php-cs-fixer・PHPUnitが全パッケージ横断で走ること。ユニットテストはBrain MonkeyでWP関数をモック、統合テストはwp-envで実施。

---

# 1. tmswp/thumbnail — 署名付きURLサムネイル

## 1.1 設計方針

旧iwf2bの `Iwf2b\Thumb` は「timthumb.phpへ無署名クエリを投げるURL生成器」だった。timthumbは2011年のリモートファイル取得RCE(キャッシュディレクトリへのwebshell書き込み、いわゆるTimThumb脆弱性事件で数百万サイトが侵害)で悪名高く、メンテも終了している。旧設計はFW側に署名・サイズ上限・srcドメイン制限が皆無で、(a)任意巨大サイズの連続リクエストによるCPU/ディスクDoS、(b)src改変によるSSRF/パストラバーサルがエンドポイント実装品質に全面依存していた。

新設計の原則:

1. **リモートURLのフェッチを一切行わない**。srcはアップロードディレクトリ配下のローカルファイルまたは添付ファイルIDのみ。
2. **全パラメータをHMAC署名で固定**。署名のないURL・改ざんされたURLは画像を生成しない。
3. **サイズ上限を強制**。署名検証後であっても上限超過は拒否(多層防御)。
4. 画像処理は `wp_get_image_editor()`(WP_Image_Editor)に委譲し、GD/Imagickの差異をWPに任せる。
5. 生成物は `uploads/tmswp-thumb/` にキャッシュし、生成の後は静的ファイルとして配信する(PHPを通さない)。
6. 同じ要求で再生成を繰り返さない。元画像より大きい要求は元画像の寸法で頭打ちにし、画像エディタが扱えない元画像はネガティブキャッシュに記録する(§1.2の手順)。

### 利用条件と制約

- **REST APIが使えること**。未生成のサムネイルのURLは、RESTのルート`/wp-json/tmswp/v1/thumb`(パーマリンクの設定が基本なら`/?rest_route=/tmswp/v1/thumb`)を指す。REST以外の経路は無い。訪問者(ログインしていないユーザー)からこのルートが使えないと、未生成のサムネイルは表示されない。
  - 起動の後、管理画面の表示(`admin_notices`)ごとに、`RestRouteCheck`が訪問者としてルートが使えるかを確かめ、使えなければ`manage_options`の権限のユーザーに通知する(§1.4)。検出するのは、`rest_authentication_errors`のフィルタが訪問者の要求をエラーにする場合(REST APIを無効化するプラグインの多く)と、ルートのコールバックまで届かない場合(`rest_endpoints`のフィルタによるルートの削除、`rest_pre_dispatch`での遮断等)である。Webサーバーの設定による`/wp-json/`の遮断は、PHPに届かないため検出しない。
- **thumbnailを登録できるKernelは、サイトに1つ**。2つ目のKernelの`register()`は`TMSWP\Core\Exception\LogicException`になる(設定の誤り。debugに関係なく起動を止める)。RESTのルートの名前空間とキャッシュのディレクトリはサイトに1つであり、複数のKernelが登録すると、どの設定と鍵が効くかが決まらないためである。
  - `tms_thumb()`・RESTのルート・`wp tmswp thumb`は、登録したKernelのdebug・ロガーと、そのKernelに渡した`Thumbnail`の設定に従う。thumbnailを登録したKernelが無い状態で`tms_thumb()`を呼ぶと`LogicException`になる。
- **uploadsはローカルのファイルシステムにあること**。外部ストレージ(オブジェクトストレージへのオフロード等)には対応しない。元画像の読み込みとキャッシュの書き込みは`wp_upload_dir()['basedir']`のローカルのパスに対して行い、キャッシュの静的なURLは`baseurl`から作る。
- キャッシュの書き込みは、同じディレクトリの中の`rename()`と`flock()`に頼る(ApacheとLinuxのローカルのファイルシステムを想定する)。
- 未生成のサムネイルの初回の表示は、RESTを経由する(WordPressが起動する)。生成の後は、静的なファイルを直接返す。
- ページキャッシュ(WP Super Cache等)のHTMLに残る静的なURLへのアクセスは、GCの判定に使われない(§1.3)。`--days`はページキャッシュの寿命より長くする。
- `srcset`や`width`・`height`の属性を出すヘルパは持たない(必要なら案件で作る)。

## 1.2 URL形式とエンドポイント

エンドポイントは **REST API ルート**とする(rewrite ruleは採用しない。`flush_rewrite_rules` 運用が不要で、テーマ/プラグインどちらの導入でも確実に動作するため)。

```
GET /wp-json/tmswp/v1/thumb?src=<src>&w=<int>&h=<int>&o=<opts>&s=<signature>
```

| パラメータ | 型 | 意味 |
|---|---|---|
| `src` | string | `"id:123"`(添付ファイルID) または uploadsベースURL配下の画像URL |
| `w` | int | 出力幅(px)。`0` = 高さ基準の自動 |
| `h` | int | 出力高さ(px)。`0` = 幅基準の自動(`w`と`h`の同時0は不正) |
| `o` | string | オプションの正規化文字列(後述)。オプションなしは空文字 |
| `s` | string | HMAC-SHA256署名(hex 64文字) |

URLの生成では、各値を`rawurlencode()`でエンコードしてから`add_query_arg()`に渡す(`add_query_arg()`は値をエンコードしない)。受け取った側の`$_GET`で1回デコードされ、署名した値に戻る。`o`の`&`・`=`と、`src`の`%`(uploadsのURLの中のエンコード済みの文字)も、この方法で署名と一致する。

### オプション正規化文字列(`o`)

許可キーは `crop`(bool) と `quality`(int 1〜100)の2つのみ。正規化手順:

1. 許可キー以外が含まれていたらURL生成時に `Exception\InvalidThumbOptionException` を投げる。
2. キー昇順にソートし、`crop=1&quality=82` 形式で連結(boolは`1`/`0`、デフォルト値と同値のキーは**含めない**)。デフォルト: `crop=true`、`quality=82`。
3. 全オプションがデフォルトなら空文字。

### 署名

```
key       = hash_hkdf('sha256', wp_salt('auth'), 32, 'tmswp/thumbnail/url-signature')
payload   = serialize([src, w, h, o])   // 4つとも文字列
signature = hash_hmac('sha256', payload, key)
```

- 値を区切りの文字で連結しない。`serialize()`は各値の長さを含むため、値の中に`|`等があっても、別の値の組み合わせと同じpayloadにならない。
- 鍵は`wp_salt('auth')`をそのまま使わず、HKDF(SHA-256)で用途の文字列を入れて導出する(`UrlSigner::deriveKey()`)。認証のcookie等と同じ鍵をこのHMACに使わないためである。saltがサイトごとに違うため、サイト間でURLは流用できない。saltを変えると全URLが無効になる(キャッシュ済みファイルの配信は影響を受けない)。
- 検証は`hash_equals()`で行う。

検証時のpayloadは**受信した生のクエリ文字列値**(`src`/`w`/`h`/`o`)をそのまま並べて組み立てる(intキャスト・trim・再正規化を一切行わない。§UrlSigner参照)。URL生成側は整数を10進最短表記で埋め込むため、`w=050` のような異表記は生成時payloadと一致せず署名不一致(403)になる。

### エンドポイントの処理手順(確定アルゴリズム)

```
1. s が無い/64桁hexでない        → 403 (WP_Error 'tmswp_thumb_unsigned')
2. UrlSigner::verify() が false  → 403 (WP_Error 'tmswp_thumb_bad_signature')
   ※ verify() には受信した生の src/w/h/o 文字列をそのまま渡す(intキャスト前)
2.5. 署名検証通過後、受信した o をパースし crop(bool)/quality(int) を取り出す:
   - o を '&' / '=' で分解し、許可キー(crop/quality)以外が含まれていれば → 400
   - quality が int として 1〜100 の範囲外 → 400
   - crop が '0'/'1' 以外 → 400
   - o に現れないキーは ThumbnailConfig の既定値(defaultCrop/defaultQuality)で補完
   ※ 署名が通っていても範囲外パラメータは多層防御で拒否する。
     o の再正規化・再署名は行わない(署名が通った o をそのままパースするのみ。
     非正規形の o は手順2の生文字列照合の時点で署名不一致になる)
3. w,h の検証: intキャスト後、両方0、負数、いずれかが maxDimension 超過 → 400
4. SourceResolver::resolve(src)がnull、またはInvalidThumbSourceException → 404
5. key = ThumbCache::key(uploadsからの相対パス, filemtime, w, h, crop, quality)
   ※ 4の後に元画像が消えた(5までの読み取りの失敗の後に、ファイルが無い)ら → 404
6. キャッシュのファイル({key}.{ext})がある
   → 更新日時を今にして(touch)、302(Location: キャッシュの静的URL、
     Cache-Control: public, max-age={redirectMaxAge})
7. 失敗の印({key}.failed)がある → 元画像URLへ302(Cache-Control: no-store)。生成も記録もしない
8. キャッシュのディレクトリとshardを作り、キーのロック({key}.lockへのflock(LOCK_EX|LOCK_NB))を取る。
   flockの後に、開いたファイルとパスの今のファイルが同じ(inodeとデバイスが一致)かを確かめ、違えば開き直す(3回まで)。
   取れない(別のプロセスが同じキーを生成中) → 待たずに元画像URLへ302(no-store)
9. ロックの中で6・7を確かめ直してから生成する:
   wp_get_image_editor(絶対パス) → 寸法の頭打ち(§1.4 ThumbGenerator)→ 必要ならresize
   → set_quality(quality) → save(一時ファイル{key}.tmp-{乱数}.{元画像の拡張子})
   → save()が返したpathのファイルをrename()で{key}.{そのpathの拡張子}に置き換える
   → ロックを持ったままロックのファイルを消し、ロックを手放す → 6と同じ302
   ※ crop/quality は手順2.5のパース結果を使う(他の出所を持たない)
10. 画像エディタが元画像を読めない・リサイズできない(恒久的な失敗。UnprocessableImageException)
   → 一時ファイルを消し、失敗の印を書いてから11へ
11. 失敗(10と、4〜9の実行時の失敗。元画像の情報の読み取り・ディレクトリ・ロック・保存・rename等。RuntimeException)
   → 元画像が消えていたら、5の※のとおり404
   → 登録したKernelがdebugなら、例外をそのまま投げる
   → 本番なら、Kernelのロガーにerrorで記録し、元画像URLへ302(no-store)(劣化運転)。
     元画像のURLを決める前の失敗なら、500(WP_Error 'tmswp_thumb_failed')
```

- 6で更新日時を変えられないときは、本番ならKernelのロガーにerrorで記録して、キャッシュのファイルへの302を返す(debugは例外)。
- ログの文脈は、例外のクラス(`exceptionClass`)・投げられた場所のファイル:行(`exceptionAt`)・previousのクラス(`previousClasses`)だけである。元画像のパス等の要求に由来する値と、例外のオブジェクト・メッセージ・トレースは入れない(v0.4.0のFormと同じ。パスや入力値を本番のログに残さない)。
- 設定の誤りや`TypeError`等(`TMSWP\Core\Exception\RuntimeException`でない例外)は、11で扱わずにそのまま伝わる。
- エラー応答(403/400/404)はJSON(`WP_Error` 標準形式)。画像を返さないことでクローラのキャッシュ汚染を防ぐ。

## 1.3 キャッシュ規則

- 配置: `wp_upload_dir()['basedir'] . '/{cacheDirName}/{shard}/'`(`cacheDirName`の既定は`tmswp-thumb`。`shard` = cacheKey先頭2文字)
  - `{key}.{ext}`: 生成したサムネイル
  - `{key}.failed`: 恒久的な失敗の印(空のファイル)
  - `{key}.lock`: 生成中のロック(ロックを持ったまま、手放す前に消す。消した後に別のプロセスが古いファイルのロックを取っても、手順8のinodeの確認で取り直すため、同じキーのロックを2つのプロセスが同時に持つことは無い)
  - `{key}.tmp-{乱数}.{ext}`: 生成中の一時ファイル(rename()で`{key}.{ext}`に置き換える。途中で止まったものはGCで消える)
  - 生成の前に、キャッシュのディレクトリとshardのディレクトリを`wp_mkdir_p()`で作り、それぞれに空の`index.html`を置く。作れなければ`TMSWP\Core\Exception\RuntimeException`(手順11)。
  - キャッシュのディレクトリには、印のファイル`.tmswp-thumb-cache`(`ThumbCache::MARKER`)を置く。キャッシュのディレクトリを作ったとき(既にあって空のときを含む)に置く。既にあって印が無く、空でなければ、`TMSWP\Core\Exception\RuntimeException`にする(`cacheDirName`がuploadsの既存のディレクトリ(年のディレクトリ等)と重なったとき、そのディレクトリをキャッシュとして使い始めない)。例外のメッセージで、`cacheDirName`が既存のディレクトリと重なっている可能性を案内する。
  - shardのディレクトリは、キャッシュのディレクトリの直下の実在のディレクトリでなければならない(実パスが`{キャッシュのディレクトリの実パス}/{shard}`)。シンボリックリンク等で外を指していれば、作成・取得・ロック・GC・集計の前に`TMSWP\Core\Exception\RuntimeException`にする(外にindex.html・失敗の印・生成物を書かず、外のファイルを返さない)。
- キャッシュキー(確定式):

```
cacheKey = substr(hash('sha256', serialize(
    [uploadsからの相対パス, filemtime, w, h, crop, quality]
)), 0, 40)
```

- `crop`・`quality`は、`o`を既定値で補った値である。`ThumbnailConfig`の既定値を変えると、オプションを省いたURL(`o`が空)も別のキーになる。
- `filemtime` をキーに含めるため、元画像が差し替わると自動的に別キャッシュへ切り替わる(明示的な無効化処理が不要)。旧ファイルはGCで回収する。
- 拡張子(`ext`)は、画像エディタが実際に書いたファイルの拡張子である(元画像と同じ形式。`image_editor_output_format`のフィルタが出力形式を変えると、その形式)。キャッシュの有無は`jpg`・`png`・`gif`・`webp`・`avif`の順で確かめる。
- 許可拡張子(元画像): `jpg, jpeg, png, gif, webp, avif`。それ以外のsrcは `SourceResolver` が拒否する。
- ネガティブキャッシュ: 失敗の印は、キーが変わる(元画像の差し替え・指定・既定値の変更)か、GCで消えるまで有効である。消えた後の最初の要求で1回だけ生成を試し直す。
- 302のキャッシュ: 生成済みのファイルへの302は`Cache-Control: public, max-age={redirectMaxAge}`(既定3600秒)。長期のキャッシュは、生成済みの静的なファイル(Webサーバーが返す。ヘッダはWebサーバーの設定による)の側で行う。元画像への302(失敗・劣化・ロックの競合)は`no-store`。
- GC: WP-CLI `wp tmswp thumb clear [--days=<n>]`。
  - RESTのルートが既存のキャッシュのファイルを返すたびに、そのファイルの更新日時を今にする(手順6)。ファイルの更新日時は、最後にREST経由で返した(または生成した)日時になる。生成した時の更新日時のままGCすると、使用中のファイルも消えるためである。
  - `--days=<n>`を指定すると、更新日時がn日より前のファイル(サムネイル・失敗の印・残った一時ファイル)だけを消す。指定しなければすべて消す。どちらも`index.html`は消さない。
  - GCと`status`は、キャッシュのディレクトリが無ければ0件である。あるのに印が無ければ、何も消さずに`TMSWP\Core\Exception\RuntimeException`にする(`cacheDirName`の誤りを知らせる)。印のファイルは、GCと`status`の対象にしない。
  - GCと`status`が扱うのは、キャッシュ自身が作ったファイルだけである。shardのディレクトリの直下にあり、名前が40桁の16進のキーで始まり、そのキーの先頭2文字がshardのディレクトリ名と同じ、シンボリックリンクでない通常のファイルに限る。それ以外のファイル・ディレクトリ(キャッシュのディレクトリの直下のファイル、`cacheDirName`がuploadsの既存のディレクトリと同じ名前のときの元画像等)には触れない。
  - キーのファイル(`{key}.*`)は、キーごとにそのキーのロックを待たずに取り、取れたキーのファイルだけを消す。生成中(ロックを持たれている)のキーは飛ばすため、生成中のロックと一時ファイルは消えない。ロックのファイルは、ロックを持ったまま手放す前に消す(異常終了で残ったロックのファイルも消える)。
  - `n`は正の整数で、n日は`redirectMaxAge`秒より長くなければならない(違えば、何も消さずに`WP_CLI::error()`で`Error:`を出し、終了コード1で終わる)。ブラウザ等にキャッシュされた302が、消したファイルを指さないためである。
  - 静的なURLへの直接のアクセス(`tms_thumb()`が静的なURLを返した後の表示や、ページキャッシュのHTML)は、Apacheが返すため更新日時を変えない。消したファイルは、次の`tms_thumb()`がRESTのURLを返して作り直す。ページキャッシュのHTMLに残った静的なURLは、ページキャッシュが切れるまで404になるため、`--days`はページキャッシュの寿命より長くする。
  - 自動cron(WP-Cronの予約)は持たない(DECISION: 運用判断)。定期的な削除は、サーバーのcron等で`wp tmswp thumb clear --days=<n>`を実行する(レシピ`packages/thumbnail/rules/thumbnail.md`)。

## 1.4 クラス仕様

### `TMSWP\Thumbnail\Thumbnail`(パッケージの入口)

```php
final class Thumbnail implements \TMSWP\Core\Kernel\ExtensionInterface
{
    public function __construct(ThumbnailConfig $config = new ThumbnailConfig());

    public function register(\TMSWP\Core\Kernel\Kernel $kernel): void;

    /** サイトでthumbnailを登録したインスタンス。無ければLogicException */
    public static function current(): self;

    /** @return string|null 画像が無いときはnull */
    public function url(int|string $src, ?int $width = null, ?int $height = null, array $options = []): ?string;

    public function config(): ThumbnailConfig;

    /** 登録したKernel。未登録ならLogicException */
    public function kernel(): \TMSWP\Core\Kernel\Kernel;
}
```

`KernelConfig`の`extensions`に`new Thumbnail()`を渡したKernelで、thumbnailが有効になる(共通規約「登録API」。サイトに1つのKernelだけ。§1.1)。コンストラクタは、WordPressの関数を呼ばない。

`register()`の振る舞い(登録したKernelのbootの中で1回):

1. このインスタンスを登録済みなら`TMSWP\Core\Exception\LogicException`。起動したほかのKernelにthumbnailを登録済みなら`LogicException`(サイトに1つの制約。§1.1)。
2. 訳の読み込み(`init` priority 1。`load_textdomain('tmswp', <thumbnail>/languages/tmswp-{$locale}.mo)`)、RESTのルートの登録(`rest_api_init` priority 10)、RESTのルートの確認と通知(`admin_notices` priority 10。`RestRouteCheck`)を、`$kernel->defer()`で予約する。
3. WP-CLIの環境なら、`WP_CLI::add_command('tmswp thumb', new ThumbCommand($this))`で登録する。

`url()`の振る舞い(確定手順):

1. `$src`が`0`か空文字なら`null`(画像が無い。アイキャッチの未設定は`get_post_thumbnail_id()`が`0`を返す。投稿が無いときは`get_post_thumbnail_id()`が`false`を返し、`$src`はint|stringなので、呼び出し側で`(int) get_post_thumbnail_id()`として`0`にする。`0`は画像が無いとして`null`になる)。負の整数は`InvalidThumbSourceException`。intなら`"id:{$src}"`へ正規化する。
2. `$width`/`$height` がともにnull/0、負、上限超過なら `InvalidThumbSourceException`(URL生成時点で開発者ミスを検出)。
3. オプションを正規化する(§1.2)。誤りは`InvalidThumbOptionException`。
4. `SourceResolver::resolve()` でローカルパスに解決する。`null`(添付ファイルが無い・実体が無い)なら`null`を返す。uploadsの外・許可されない拡張子は`InvalidThumbSourceException`。
5. キャッシュファイルが既に存在すれば**キャッシュファイルの静的URLを返す**(エンドポイントを経由させない)。
6. それ以外は署名付きRESTエンドポイントURLを返す(値はURLエンコードする。§1.2)。

エラー方針(00-overview.md §5.6):

- 画像が無いこと(1と4の`null`)はエラーにしない。debugでも例外にせず、記録もしない。
- 引数とオプションの誤り(`InvalidThumbSourceException`・`InvalidThumbOptionException`)は、debugに関係なく例外のまま投げる。
- 実行時の失敗(元画像の更新日時が読めない、`wp_upload_dir()`の異常等。`TMSWP\Core\Exception\RuntimeException`)は、登録したKernelがdebugなら例外のまま投げ、本番ならKernelのロガーにerrorで記録して`null`を返す。文脈は例外のクラス・場所・previousのクラスだけ(§1.2)。ただし、4で解決した元画像がその後に消えていたら、画像が無いとして`null`を返す。
- debugの判定は、登録したKernelの`config()->debug`である。`WP_DEBUG`を直接見ない。

### `TMSWP\Thumbnail\ThumbnailConfig`

```php
final readonly class ThumbnailConfig
{
    public function __construct(
        public int $maxDimension = 2560,          // WPのbig_image閾値に合わせる。1以上
        public int $defaultQuality = 82,          // 1〜100
        public bool $defaultCrop = true,
        public string $cacheDirName = 'tmswp-thumb',
        public int $redirectMaxAge = 3600,        // 生成済みのファイルへの302をキャッシュさせる秒数。1以上
    ) {}
}
```

- コンストラクタで値を確かめ、範囲・形の外なら`TMSWP\Core\Exception\InvalidArgumentException`(設定の誤り)。`cacheDirName`は、英数字で始まり英数字・`.`・`_`・`-`だけの1つの名前(`/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/`。空・`.`・`..`・パスの区切り・`../victim`・末尾の改行等を拒む)。
- キャッシュのディレクトリは`{uploadsの実パス}/{cacheDirName}`である。`Thumbnail`は、キャッシュを操作する前に、そのディレクトリが既にあるならその実パスが同じ(シンボリックリンク等でuploadsの外を指していない)ことを確かめ、違えば`TMSWP\Core\Exception\RuntimeException`にする。

### `TMSWP\Thumbnail\UrlSigner`

```php
final class UrlSigner
{
    public function __construct(private readonly string $key) {}  // deriveKey(wp_salt('auth'))を注入

    public static function deriveKey(string $secret): string;    // HKDF(SHA-256)、info 'tmswp/thumbnail/url-signature'
    public function sign(string $src, int $width, int $height, string $options): string;
    public function verify(string $src, string $width, string $height, string $options, string $signature): bool;
}
```

`verify()` の確定仕様: **受信した生の `src`/`w`/`h`/`o` 文字列そのもの**からpayloadを組み立てて照合する(intキャスト前の文字列で照合する)。

```
verify(src, width, height, options, signature):
  payload = serialize([src, width, height, options])   // 受信文字列そのまま。キャスト・trim・再正規化は行わない
  return hash_equals(hash_hmac('sha256', payload, key), signature)
```

`sign()`(URL生成側)は整数を10進最短表記の文字列にして埋め込むため、`w=050`・`h=+1` 等の異表記や `o` のキー並べ替え・余分キー混入は生成時payloadと一致せず、署名不一致として確実に弾かれる。タイミング攻撃対策のため `===` 比較は禁止。

### `TMSWP\Thumbnail\SourceResolver`

```php
final class SourceResolver
{
    /** @return non-empty-string|null 解決済み絶対パス(uploads配下保証)。ファイルが無いときはnull */
    public function resolve(string $src): ?string;
}
```

解決手順(確定。realpath封じ込めを**全経路共通の最終ゲート**とする):

```
1. パス取得(いずれかの経路):
   a. "id:{n}"形式 → get_attached_file(n)。falseならnull(添付ファイルが無い)
   b. URL形式 → rtrim(wp_upload_dir()['baseurl'], '/')に'/'を付与した文字列(接頭辞)で前方一致しなければ
      InvalidThumbSourceException。baseurlの末尾が'/'のサイトでは、wp_get_attachment_url()が
      baseurl . "/$file"でURLを作り'//'になるため、そのときだけ接頭辞の直後の'/'を1つ余分に受け入れる。
      一致したら相対パス部をrawurldecodeしてbasedirに連結
      (空・NULを含む・先頭が'/'(エンコードした'/'と、それ以上の重なり)→ InvalidThumbSourceException)
1.5. パスの文字列での封じ込め(ファイルの有無を見る前。経路a/bの両方):
   basedir + DIRECTORY_SEPARATORで始まらない、またはその後(直後の区切りの重なりを除いた残り)に
   空の区間(//)・'.'・'..'の区間・NULを含むならInvalidThumbSourceException
   (uploadsの外を指すsrcは、相手のファイルの有無に関係なく例外。直後の重なりを許すのは、get_attached_file()が
   basedir . "/$file"でパスを作るため、basedirの末尾が'/'のサイトでは'//'になるからである)
2. 拡張子が許可リスト外ならInvalidThumbSourceException。is_file()でなければnull(実体が無い)
3. realpath封じ込め(最終ゲート。経路a/bの両方に必ず適用する):
   base = realpath(basedir)、real = realpath(取得パス)
   どちらかがfalse、またはstr_starts_with(real, base . DIRECTORY_SEPARATOR)でなければ
   InvalidThumbSourceException
   realの拡張子も許可リストで確かめる(シンボリックリンクの名前だけで判定しない)。外ならInvalidThumbSourceException
   合格したらrealを返す
```

- `null`は「画像が無い」(データが無い)、`InvalidThumbSourceException`は「srcの誤り」である。`url()`は前者を`null`、後者を例外にし、エンドポイントはどちらも404にする。
- 手順3は `realpath(basedir)` に**末尾ディレクトリ区切り `DIRECTORY_SEPARATOR` を付与した文字列**で `str_starts_with()` 判定を行い、prefix一致の境界をディレクトリ単位に限定する。`basedir` が `/var/www/uploads` のとき `/var/www/uploads-evil/x.jpg` のような「同prefix別ディレクトリ」の誤通過を遮断する(旧 `Filesystem::is_child_path` のstrpos前方一致バグの再発防止)。`../` 混入・シンボリックリンク脱出も同時に遮断される。
- 経路a(`id:{n}`)で得た `get_attached_file()` の結果も手順3を必ず通す。添付ファイルメタ(`_wp_attached_file`)が改変された環境ではuploads外を指しうるため、DB由来のパスを信頼しない。

### `TMSWP\Thumbnail\ThumbCache`

```php
final class ThumbCache
{
    public function __construct(private readonly string $baseDir, private readonly string $baseUrl) {}

    public function key(string $relativePath, int $mtime, int $width, int $height, bool $crop, int $quality): string;
    public function find(string $key): ?string;              // 生成済みのファイルの絶対パス。無ければnull
    public function url(string $path): string;               // キャッシュのファイルの静的URL
    public function touch(string $path): void;               // 更新日時を今にする
    public function hasFailed(string $key): bool;
    public function markFailed(string $key): void;
    public function ensureDirectory(string $key): void;      // ディレクトリ・印・index.html。印の無い空でない既存のディレクトリと失敗はRuntimeException
    public function lock(string $key): ?ThumbLock;           // 待たずに取り、inodeを確かめる。競合ならnull
    public function temporaryPath(string $key, string $extension): string;
    public function commit(string $writtenPath, string $key): string;   // rename()で置き換え、キャッシュのパスを返す
    public function discard(string $path): void;
    /** @return int 削除ファイル数 */
    public function clear(?int $olderThanDays = null): int;  // キーごとにロックを取って消す。1未満とmaxOlderThanDays()を超える日数はInvalidArgumentException(何も消さない)
    public static function maxOlderThanDays(): int;          // clear()の日数の上限。intdiv(PHP_INT_MAX, 86400)(秒に換算してintに収まる最大の日数)。CLIの--daysも同じ上限を使う
    /** @return array{files: int, bytes: int, failed: int} */
    public function status(): array;                         // index.html・ロック・一時ファイルは数えない
}
```

- `lock()`は、`flock(LOCK_EX|LOCK_NB)`を取った後に、開いたファイル(`fstat()`)とパスの今のファイル(`clearstatcache()`の後の`stat()`)のinodeとデバイスが一致するかを確かめる。一致しない(パスが消えた、または別のファイルに替わった)なら、手放して開き直す(3回まで。取れなければ競合としてnull)。調べる間に別のholderがパスを消すのは予想どおりの競合なので、`stat()`の失敗の警告を外に出さずに受け、パスが無くなっていれば不一致として扱う。パスがあるのに`stat()`が失敗したときは`TMSWP\Core\Exception\RuntimeException`にする。
- `ThumbLock::release()`は、ロックを持ったままロックのファイルを消してから、ロックを手放す。消す前に別のプロセスが古いファイルを開いていて、手放した後にそのflockを取っても、`lock()`の確認で取り直すため、同じキーのロックを2つのプロセスが同時に持つことは無い。GCも、キーのロックを持ったままロックのファイルを消す。

### `TMSWP\Thumbnail\ThumbGenerator`

```php
final class ThumbGenerator
{
    /** @return string save()が返したpath(出力形式のフィルタで$targetと拡張子が違いうる) */
    public function generate(string $source, string $target, int $width, int $height, bool $crop, int $quality): string;

    /** @return array{0: int, 1: int}|null リサイズに渡す幅・高さ。元画像の寸法のままでよければnull */
    public static function fit(int $width, int $height, int $sourceWidth, int $sourceHeight, bool $crop): ?array;
}
```

- 元画像の寸法を超える要求は、元画像の寸法で頭打ちにする(拡大しない)。幅と高さの両方を指定した切り抜きは、指定の縦横比のまま元画像に収まる大きさに縮める。それ以外(切り抜かない、または片方が0)は、指定の枠に収まるよう元画像の縦横比で縮める。縮めた結果が元画像と1px以内なら、リサイズせずに元画像の寸法で保存する(WordPressの画像エディタは、拡大と同じ寸法へのリサイズをエラーにするため)。
- 画像エディタが読めない・リサイズできないときは`Exception\UnprocessableImageException`。品質の設定・保存の失敗は`TMSWP\Core\Exception\RuntimeException`。

### `TMSWP\Thumbnail\RestController`

```php
final class RestController
{
    public function __construct(
        private readonly UrlSigner $signer,
        private readonly SourceResolver $resolver,
        private readonly ThumbCache $cache,
        private readonly ThumbGenerator $generator,
        private readonly ThumbnailConfig $config,
        private readonly \TMSWP\Core\Kernel\Kernel $kernel,   // 登録したKernel(debug・ロガー)
    ) {}

    public function registerRoutes(): void;  // register_rest_route('tmswp/v1', '/thumb', ...)
    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
}
```

`permission_callback`は`'__return_true'`(認可は署名検証が担う)。処理手順は1.2の確定アルゴリズムに従う。

### `TMSWP\Thumbnail\RestRouteCheck`

```php
final class RestRouteCheck
{
    public function notice(): void;      // admin_notices。manage_optionsのユーザーで、isAvailable()がfalseなら通知
    public function isAvailable(): bool; // 訪問者として、認証のフィルタを通り、ルートのコールバックまで届くか
}
```

`isAvailable()`は、確かめる間だけ現在のユーザーを未ログイン(`wp_set_current_user(0)`)にし、要求のnonce(`$_GET`・`$_POST`・`$_REQUEST`の`_wpnonce`と、`$_SERVER['HTTP_X_WP_NONCE']`の4つ)を無い状態にして、次の両方を確かめる。訪問者の要求にはnonceが無い。WordPressのcookieの認証の検査(`rest_cookie_check_errors`)は、未ログインでもnonceがあれば照合してエラーを返すため、管理画面の要求のnonceを残すと誤って「使えない」と判定する。フィルタは外さないので、コールバックの順序は変わらない。状態を変える前からtry/finallyで囲み、例外のときも含めて、ユーザーを戻す。nonceは、元にあったキーを元の値(nullを含む)に戻し、元に無かったキー(確かめる間に足されたもの)を消す。

1. `apply_filters('rest_authentication_errors', null)`が`WP_Error`でない。
2. `rest_do_request()`で署名の無い`GET /tmswp/v1/thumb`を送ると、ルートのコールバックが返す`tmswp_thumb_unsigned`になる。

### `TMSWP\Thumbnail\Cli\ThumbCommand`

```php
final class ThumbCommand
{
    public function __construct(Thumbnail $thumbnail);
    /** wp tmswp thumb clear [--days[=<days>]] : キャッシュ削除(--days指定時は更新日時がn日より前のもののみ) */
    public function clear(array $args, array $assocArgs): void;
    /** wp tmswp thumb status : サムネイルの件数・合計サイズと、失敗の印の件数を表示 */
    public function status(array $args, array $assocArgs): void;
}
```

- 各メソッドは、WP-CLIのsynopsisのdocblock(`## OPTIONS`・`## EXAMPLES`)を持つ。
- `--days`は正の整数で、秒に換算してintに収まる日数(`intdiv(PHP_INT_MAX, 86400)`日、64ビットで106751991167300日)以下でなければ、またn日が`redirectMaxAge`秒より長くなければ、利用者の入力の誤りとして何も消さずに`WP_CLI::error()`で終わる(`Error:`・終了コード1。§1.3)。キャッシュの操作の失敗(消せないファイル、キャッシュのディレクトリの印が無い等)は、設定や環境の誤りなので例外のまま伝える。
- synopsisは、`--days`の値を任意(`[--days[=<days>]]`)にする。値を必須(`[--days=<days>]`)にすると、WP-CLIは値の無い`--days`を警告だけで捨ててコマンドを続け、すべてのファイルを消すためである。値の無い`--days`は、ほかの誤りと同じく`Error:`で終わる。
- WP-CLIのAPIは`WP_CLI::success()`等で直接呼ぶ(`['WP_CLI', ...]`のcallableを組み立てない)。
- `Thumbnail::register()`が、WP-CLIの環境で`WP_CLI::add_command('tmswp thumb', ...)`で登録する。

### グローバルヘルパ `tms_thumb()`

パッケージの`composer.json`の`autoload.files`で定義する。`function_exists`のガードで囲まない。同じ名前の関数が先に定義されていると、PHPの関数の再宣言のエラーで止まる(別の実装を黙って使わない)。

```php
function tms_thumb(int|string $src, ?int $width = null, ?int $height = null, array $options = []): ?string
{
    return \TMSWP\Thumbnail\Thumbnail::current()->url($src, $width, $height, $options);
}
```

旧 `iwf_thumb()`(5プロジェクト44箇所で利用)と同じ書き味を `tms_` 接頭辞で踏襲する。thumbnailを登録したKernelが無ければ`LogicException`。

## 1.5 利用例(app側)

```php
// tmswp-kernel.php
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;

return KernelConfig::theme(
    __DIR__ . '/app',
    'App',
    extensions: [new Thumbnail(new ThumbnailConfig(defaultQuality: 80))],
);
```

```php
<?php /* template-parts/card.php — 画像が無い(null)ときはimgを出さない */ ?>
<?php $thumb = tms_thumb((int) get_post_thumbnail_id(), 400, 300); ?>
<?php if ($thumb !== null) : ?>
<img src="<?php echo esc_url($thumb); ?>" alt="">
<?php endif; ?>
```

## 1.6 旧iwf2bからの設計根拠

- `Thumb` + `TimthumbDriver` は無署名クエリURL生成のみで、timthumb本体(2011年RCEの代名詞・メンテ終了)を各案件が配置する前提だった → 署名付きURL+WP_Image_Editorで全面置換。
- `composer.json` の `ext-gd` はtimthumb前提の幻の依存だった → 本パッケージのrequireへ移設(DR-14)。
- staticシングルトン+`set_driver` の不整合状態(例外時にdriverのみ設定済み)→ `KernelConfig`の`extensions`に渡したインスタンスの`register()`で初期化し、静的な設定を持たない。
- `AbstractPost::get_thumbnail()` の本文`<img>`抽出+`@getimagesize`(リモート同期I/O)はコアの責務外 → コアには `tmswp/post/thumbnail_fallback` フィルタポイントのみ残し、本文画像抽出は app 側レシピ(`rules/post-type.md`)で実装する(DR-15)。

---

# 2. tmswp/settings-page — 管理画面設定ページ

## 2.1 設計方針

旧 `Iwf2b\SettingsPage\AbstractSettingsPage` は5プロジェクト16クラスで使われた実需機能だが、**`$_REQUEST['action']` 直読みでnonce/CSRF検証なしにアクションファイルをinclude実行する**重大な欠陥を持っていた(加えて `View::load_global` のextract依存、`{slug}/{action}.php` 命名規則ディスパッチというCakePHP時代の移植痕)。新設計はファイルベースディスパッチを全廃し、次の3層合成で再構築する:

1. **メニュー登録**: `#[AsSettingsPage]` + `SettingsPageDefinition`(`add_menu_page`/`add_submenu_page` の薄い宣言)
2. **フォーム処理**: コアForm層(DR-07)に全面委譲 — nonce自動埋め込み/自動検証、PRG(Post/Redirect/Get)
3. **描画**: コア `View\Template`(part/render)による通常のPHPテンプレート

WP Settings API(`register_setting`/`add_settings_section`)は採用しない。Form層と二重のフォーム抽象になるうえ、旧FW利用案件のUI要件(自由なHTML)と合わないため。

## 2.2 クラス仕様

### `TMSWP\SettingsPage\SettingsPages`(パッケージの入口)

```php
final class SettingsPages implements \TMSWP\Core\Kernel\ExtensionInterface
{
    /** @param list<class-string> $pages 属性探索で見つからない(app/の外にある)設定ページのクラス */
    public function __construct(array $pages = []);

    public function register(\TMSWP\Core\Kernel\Kernel $kernel): void;

    /** Kernelに登録したSettingsPages。無ければLogicException */
    public static function forKernel(\TMSWP\Core\Kernel\Kernel $kernel): self;
}
```

`KernelConfig`の`extensions`に`new SettingsPages()`を渡したKernelでだけ、設定ページが有効になる(共通規約「登録API」)。コンストラクタは、WordPressの関数もページの`definition()`も呼ばない。

`register()`の振る舞い(登録したKernelのbootの中で1回):

1. このインスタンスを登録済みなら`TMSWP\Core\Exception\LogicException`(1つのインスタンスは1つのKernelにだけ登録できる)。コンストラクタの`$pages`に`SettingsPage`を継承しないクラスがあれば`LogicException`。
2. 訳の読み込み(`init` priority 1。`load_textdomain('tmswp', <settings-page>/languages/tmswp-{$locale}.mo)`)を`$kernel->defer()`で予約する。
3. `$pages`があれば、`admin_menu`(priority 10)でそれぞれをメニューに登録する処理を`$kernel->defer()`で予約する(属性探索を使わない場合・appDirの外のクラスのための経路)。

ページの`definition()`は、メニューに登録するとき(`admin_menu`。`init`の後)に初めて呼ぶ。`definition()`の中で`__()`を使っても、`init`より前の翻訳の読み込み(WP 6.7以降の`_doing_it_wrong`)にならない。

slugの重複は、経路(属性探索/コンストラクタ)を問わず、起動したすべてのKernelのSettingsPagesにまたがって検出し、`LogicException`にする(管理画面のページはサイトで1つの名前空間を共有するため)。

`#[AsSettingsPage]` 付きクラスの収集に、本パッケージ独自の探索機構・Kernelへのハンドラ登録は**存在しない**。コアDiscoveryの `scan()` は `ComponentAttributeInterface` 実装属性を `getAttributes(ComponentAttributeInterface::class, IS_INSTANCEOF)` でinstanceof収集する(Kernel設計書§4/§5)ため、下記のとおり属性が同インターフェースを実装するだけで、Kernel側の改修・登録テーブルなしに「app/に置くだけ」で収集・スケジュールされる。

### `TMSWP\SettingsPage\Attribute\AsSettingsPage`

```php
<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage\Attribute;

use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\SettingsPage\SettingsPageInitializer;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsSettingsPage implements ComponentAttributeInterface
{
    public function hookName(KernelContext $context): string
    {
        return 'admin_menu';   // Theme/Plugin共通。管理画面以外では発火しない=フロントへの副作用ゼロ
    }

    public function hookPriority(): int
    {
        return 10;
    }

    /** @return class-string<\TMSWP\Core\Kernel\ComponentInitializerInterface> */
    public function initializerClass(): string
    {
        return SettingsPageInitializer::class;
    }
}
```

引数なしのマーカー属性。定義の実体は `definition()` が持つ(コアの `#[AsPostType]` + `definition()` 規約と相似形に揃える)。`admin_menu` はフロント・REST・WP-CLIでは発火しないため、本番(debug=false)の boot では、管理画面以外のリクエストで設定ページクラスのロード・初期化が一切行われない(debug の boot は走査(02-kernel §4)でクラスを読み込む)。

### `TMSWP\SettingsPage\SettingsPageInitializer`

```php
final class SettingsPageInitializer implements \TMSWP\Core\Kernel\ComponentInitializerInterface
{
    public function initialize(\TMSWP\Core\Kernel\DiscoveredComponent $component, \TMSWP\Core\Kernel\Kernel $kernel): void;
}
```

Kernelの初期化スケジューラから `admin_menu`(priority 10)の発火中に、発見コンポーネント1件につき1回呼ばれる(KernelのContainerからシングルトンとして解決される)。`initialize()` の確定アルゴリズム:

```
1. SettingsPages::forKernel($kernel)が、そのKernelに登録したSettingsPagesを返す。
   無ければLogicException(「拡張は自動では何もしない」共通規約のゲート。設定の誤りなので、
   Kernelの初期化(runInitializer)はdebugに関係なく例外をそのまま投げる)
2. $component->class が SettingsPage 非継承 → LogicException
3. definition()->slug を、起動したすべてのKernelのSettingsPagesのslugと照合して登録する。重複slugは LogicException
4. new PageRegistrar($component->class) を生成し registerMenu() を即時実行
   (admin_menu発火中のため add_menu_page/add_submenu_page を直接呼べる。
    registerMenu() が hook_suffix を取得し load-{hook_suffix} へ handleRequest() を接続する)
```

### `TMSWP\SettingsPage\SettingsPageDefinition`

```php
final readonly class SettingsPageDefinition
{
    public string $pageTitle;   // 未指定時はslugから生成(ucwords)
    public string $menuTitle;   // 未指定時はpageTitleへフォールバック

    public function __construct(
        public string $slug,                            // 例: 'site-settings'
        ?string $pageTitle = null,
        ?string $menuTitle = null,
        public string $capability = 'manage_options',
        public ?string $parent = null,                  // 指定でadd_submenu_page。例: 'options-general.php'
        public ?string $icon = null,                    // dashicon名またはURL。top-levelのみ有効
        public ?int $position = null,
    ) {
        // 派生既定値はコンストラクタ内で確定する(getter内の分岐を持たない)
        $this->pageTitle = $pageTitle ?? ucwords(str_replace(['-', '_'], ' ', $slug));
        $this->menuTitle = $menuTitle ?? $this->pageTitle;
    }
}
```

7スカラのみを保持する不変の値オブジェクト(`ThumbnailConfig`・`BridgeConfig` と同じreadonly+コンストラクタ昇格スタイル)。利用側はnamed argumentsで必要な項目だけを指定する: `new SettingsPageDefinition(slug: 'site-settings', pageTitle: __('サイト設定', 'my-theme'), parent: 'options-general.php')`(テキストドメインはアプリ自身のもの。01-architecture.md §7.4)。`PageRegistrar` はgetterを介さずpublic readonlyプロパティを直読みする(PHPStanが型を直接追える)。

注記: コアの `PostTypeDefinition`(エンティティ定義設計書)は流暢ビルダのまま据え置く。あちらは `toArgs()` が「呼ばれなかったセッターのキーを含めない」疎args意味論(WP側既定値への委譲)を持つためビルダ様式に技術的必然があるが、本クラスの7スカラは `add_menu_page`/`add_submenu_page` の位置引数へ流すだけで「不在 vs 既定値」の区別が不要である。この様式差は正当な理由のある既知の許容差分としてrules/にも1行記載する。

### `TMSWP\SettingsPage\SettingsPage`(基底)

```php
abstract class SettingsPage
{
    /** admin_menu以降(initの後)に呼ばれる。__()で訳した文字列を使える */
    abstract public static function definition(): SettingsPageDefinition;

    /** 設定フォーム。フォーム不要の情報表示ページはnullのまま。確認画面の有効なフォームはLogicException */
    protected function createForm(): ?\TMSWP\Core\Form\Form
    {
        return null;
    }

    /** フォーム送信成功時(検証通過+CSRF通過)の保存処理 */
    protected function onSubmit(\TMSWP\Core\Form\FormResult $result): void
    {
    }

    /**
     * ページ本体の描画(echoする)。$formはcreateForm()の戻り(handle済み)。
     * フォームのあるページでは、FWが出す<form>の中(項目の部分)だけを描画する
     */
    abstract protected function render(?\TMSWP\Core\Form\Form $form): void;

    // ===== フレームワーク管理(finalメソッド) =====
    /** このページの生のURL(esc_url前)+クエリの引数。admin_menuで登録した後に使える。未登録ならLogicException */
    final public function url(array $queryArgs = []): string;
}
```

`url()`はmenu_page_url()と同じ規則でURLを組み立てるが、esc_url()を通さない生のURLを返す(menu_page_url()の戻り値は`&`が`&#038;`になっており、リダイレクト先やadd_query_arg()の入力に使えないため)。HTMLに出すときは、出力の時点でesc_url()でエスケープする。

### `TMSWP\SettingsPage\PageRegistrar`(内部クラス)

```php
final class PageRegistrar
{
    /** @param class-string<SettingsPage> $pageClass */
    public function __construct(private readonly string $pageClass) {}

    public function registerMenu(): void;   // admin_menuフックで実行
    public function handleRequest(): void;  // load-{hook_suffix}フックで実行
    public function output(): void;         // メニューコールバック
}
```

確定ライフサイクル:

```
admin_menu:
  definition()を読み、parent有無で add_menu_page / add_submenu_page。
  add_submenu_pageがfalseを返したら(今のユーザーがcapabilityを持たないためWordPressが登録しなかった)、
  正常な結果として何もしない(load-{hook_suffix}も接続しない)。
  戻り値hook_suffixを保持し add_action("load-{$hookSuffix}", handleRequest)。
  登録したときは、removable_query_argsに'tmswp-settings-saved'を足す(表示の後に、WordPressがアドレスバーのURLから除く)

load-{hook_suffix}(画面出力前):
  1. current_user_can(capability) を再確認(失敗は wp_die(..., '', ['response' => 403]))
  2. $form = createForm()。nullなら何もしない。$form->isConfirmEnabled()ならLogicException(設定の誤り)
  3. $result = $form->handle()
     (Form層がメソッド判定・バインド・nonce検証・バリデーション・状態遷移を行う。
      nonce不一致はForm層の仕様に従いエラー終了し、onSubmitへは到達しない)
  4. $result->step === FormStep::Complete のとき:
     a. onSubmit($result) を呼ぶ(保存処理)
     b. wp_safe_redirect(url(['tmswp-settings-saved' => '1'])); exit  — PRGで再POST防止
        (このexitは設計原則1のexit禁止の例外。00-overview.md §3)
  5. Formをインスタンスに保持し output() へ引き継ぐ

output():
  1. <div class="wrap"><h1>{pageTitle}</h1> を出力する
  2. $_GET['tmswp-settings-saved'] === '1' で、POSTでないなら成功notice(.notice-success)を出力
  3. フォームが無ければrender(null)を呼び</div>を閉じる
  4. フォームがあれば:
     a. Formのフォームエラー(formErrors()。nonceの失効等)を1件ずつエラーnotice(.notice-error)で出力
     b. 項目のエラーがあれば、保存しなかったことを示すエラーnoticeを出力
     c. $form->open()(<form>の開始タグと必須のhidden)、render($form)(項目)、
        保存ボタン(<button type="submit" name="_step" value="submit">)、$form->close()の順に出力し</div>を閉じる
```

- 保存の後のURLのクエリ変数は、FW独自の`tmswp-settings-saved`にし、WordPressの`updated`を使わない。本体の`wp-admin/options-head.php`は、親が`options-general.php`のページで`$_GET['updated']`(と`page`)を見て「Settings saved.」を出すため、`updated`を使うとFWの通知と二重になる。親が何であっても、成功の通知はFWの1つだけになる。
- 外枠(wrap・見出し・通知・`<form>`の開始タグと必須のhidden・`_step=submit`の保存ボタン・終了タグ)はFWが描画し、render()は項目だけを描画する。`_step`の値は文書05の予約POSTキーのとおり、確認画面の無いフォームの送信(`submit`)である。
- 設定フォームは確認画面を使えない。`createForm()`が確認画面の有効なフォーム(`Form::isConfirmEnabled()`がtrue)を返すと、`handle()`の前に`LogicException`になる(設定の誤り。debugに関係なく投げる)。保存ボタンは`_step=submit`だけを送り、確認画面の有効なフォームは保存できないためである。
- GET時(`handle()` が `FormStep::Input` を返す)は `OptionFormBridge::applyDefaults()`(下記)等で初期値を与える。
- FWの文言(通知・保存ボタン・権限エラー)のソース文字列は英語で、ja訳を`languages/`に同梱する(テキストドメインは`tmswp`)。

### `TMSWP\SettingsPage\OptionFormBridge`

オプショングループ enum(コアOption層。`#[AsOptionGroup]` 付き string backed enum)とFormの橋渡しヘルパ。フィールド名とオプションキー(`OptionSchema` 付き enum ケースの backing 値)が一致するものを、スキーマの型とFormの値の形の間で変換して転送する。両メソッドはオプショングループ enum の class-string を受け取る。

```php
final class OptionFormBridge
{
    /**
     * オプショングループの現在値をFormの初期値へ流し込む(handle()の前)
     * @param class-string $optionGroupClass #[AsOptionGroup] 付きの string backed enum
     */
    public static function applyDefaults(\TMSWP\Core\Form\Form $form, string $optionGroupClass): void;

    /**
     * 検証済みフォーム値をスキーマの型に変換してオプショングループへ保存する(onSubmit時)
     * @param class-string $optionGroupClass #[AsOptionGroup] 付きの string backed enum
     * @param list<string> $only 指定時はこのキーのみ保存。#[OptionSchema]で宣言していない名前はLogicException
     */
    public static function persist(\TMSWP\Core\Form\FormResult $result, string $optionGroupClass, array $only = []): void;
}
```

- 転送規則: `$optionGroupClass` の `OptionSchema` 宣言済みキー集合とフォームのフィールド名集合の積集合のみを転送する。片側にしかない名前は黙って無視せず、オプショングループのKernel(SettingsPagesを登録したKernel)のロガー(`$kernel->logger()`)にdebugで記録する。オプショングループのKernelにSettingsPagesが無ければ`LogicException`。
- 値の読み書きはコア `OptionRepository` を経由する(直接 `get_option` を呼ばない)。
- スキーマの型とFormの値の形の対応:

| スキーマの型 | Formの値の形(applyDefaultsが渡す形・persistが受け付ける形) | 保存する値 |
|---|---|---|
| string | 文字列 | 文字列 |
| integer | 整数の文字列(先頭の`+`と0を許す) | int |
| number | 数値の文字列(`is_numeric()`。有限の値) | 整数ならint、それ以外はfloat |
| boolean | `'1'`(true)・`''`(false)。`Field::checkbox()`の既定のcheckedValue`'1'`とチェックしない場合の値 | bool |
| array | 文字列のlist(複数選択の項目) | 文字列のlist |
| object | 項目と結べない(`LogicException`) | — |

- `applyDefaults()`は、保存値(無ければスキーマの既定値)を上の形に変換して`Form::applyDefaults()`に渡す。値が無いキーは項目の既定値のままにする。WordPressのoptionsテーブルを経た保存値(`update_option()`がintやboolを文字列で保存し、次のリクエストで`'5'`・`'1'`・`''`として読める値)も受け付ける。保存値が型の形でない場合はデータの取得の失敗として扱い、登録したKernelのdebugなら`RuntimeException`、本番ならKernelのロガーにerrorで記録してその項目を項目の既定値のままにする(エラー方針、00-overview.md §5.6)。変換した値の形が項目に合わない場合(array型のキーを単一の値の項目と結んだ等)は、`Form::applyDefaults()`の`FormConfigException`になる。
- `persist()`は、すべての値を変換してから保存する。変換できない値(整数の項目に整数以外を許す等、項目の検証の規則が足りない場合)は`LogicException`にし、どのキーも保存しない。例外のメッセージに利用者の入力値は入れない。`OptionRepository::set()`の戻り値(false=保存値が変わらなかった)は失敗ではないため扱わない。

## 2.3 利用例(app側)

```php
// app/SettingsPage/SiteSettingsPage.php
declare(strict_types=1);

namespace App\SettingsPage;

use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\{SettingsPage, SettingsPageDefinition, OptionFormBridge};
use TMSWP\Core\Form\{Form, FormDefinition, FormResult};
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\View\Template;
use App\Option\SiteOption;

#[AsSettingsPage]
final class SiteSettingsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(
            slug: 'site-settings',
            pageTitle: __('サイト設定', 'my-theme'),   // アプリ自身のテキストドメイン(01-architecture.md §7.4)
            parent: 'options-general.php',
        );
    }

    protected function createForm(): ?Form
    {
        $form = new Form(
            FormDefinition::create('site_settings')   // 確認画面なし
                ->text('site_catchphrase', __('キャッチコピー', 'my-theme'), [new MaxLength(100)]),
        );
        OptionFormBridge::applyDefaults($form, SiteOption::class);

        return $form;
    }

    protected function onSubmit(FormResult $result): void
    {
        OptionFormBridge::persist($result, SiteOption::class);
    }

    protected function render(?Form $form): void
    {
        Template::part('parts/admin/site-settings', ['form' => $form]);
    }
}
```

```php
<?php

// parts/admin/site-settings.php — <form>・必須のhidden・保存ボタン・エラーの通知はFWが出す。ここは項目だけ
declare(strict_types=1);

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Form;

// Template::part()のテンプレートから見える変数は$argsだけ。渡したFormは$args['form']から取り、型を確かめてから使う
$form = $args['form'] ?? null;

if (!$form instanceof Form) {
    throw new LogicException("parts/admin/site-settings.php requires a Form in \$args['form']");
}

$field = $form->field('site_catchphrase');
?>
<table class="form-table">
    <tr>
        <th><?php echo $field->labelTag(); ?></th>
        <td>
            <?php echo $field->input(['class' => 'regular-text']); ?>
            <?php echo $field->errors(); ?>
        </td>
    </tr>
</table>
```

## 2.4 旧iwf2bからの設計根拠

- 旧 `AbstractSettingsPage::action()` は `$_REQUEST['action']` から `{action_dir}/{slug}/{action}.php` を**CSRF検証なしでinclude実行**していた → アクションファイル機構を全廃し、Form層のnonce自動検証へ一本化(本パッケージ最大の新設根拠)。
- `View::load_global()` + `extract()` 依存の画面描画 → `View\Template::part()`(extract禁止、明示変数渡し)へ(DR-16)。
- `%template_dir%` プレースホルダ・`generate_file_name()` 命名規則ディスパッチ → 廃止。描画は `render()` にコードで書く。
- 5プロジェクト16クラスの利用実態(設定値保存+一覧表示が大半)から、必要十分なのは「メニュー登録+フォーム+オプション保存」の3点と判断し、それ以外(タブ・複数action画面)はapp側で `$_GET` 分岐して `render()` 内で実装する方針とする。
- `SettingsPageDefinition` は疎args意味論を持たない純粋な7スカラ値オブジェクトであるため、コア `PostTypeDefinition` のような流暢ビルダにはせず、隣接する `ThumbnailConfig`/`BridgeConfig` と同じreadonly+named argumentsで構成する(`PostTypeDefinition` は `toArgs()` の疎args意味論があるためビルダとする)。

---

# 3. tmswp/ja-toolkit — 日本語サイト対応

## 3.1 設計方針

旧 `Iwf2b\AbstractHook` は10テーマが継承した神クラスで、`initialize()` が40超のフックを**継承一発で無差別に**登録していた(管理メニュー全削除等の破壊的デフォルト+ACF/CF7/Yoast直結合+View乗っ取り)。ACF非使用の5プロジェクトは継承を避けて独自Hookを再実装しており、責務過多が利用側の分裂を招いた。

新設計は**モジュール式の明示オプトイン**(DR-10):

1. 1関心事=1モジュール(final class)。`KernelConfig`の`extensions`に渡した`new JaToolkit(...)`のコンストラクタに列挙したものだけが、そのKernelで有効になる(共通規約「登録API」)。
2. 収載対象は**日本語サイト固有の課題を解くモジュールとFormプリセットのみ**。管理メニュー・管理バー・ダッシュボード・wp_head 出力等の汎用WPクリーンアップは、削除対象がテーマ毎に異なり一律の既定値が成立しないため**コード収載しない**。app側Hookクラスのレシピ(コアの rules/hooks.md)として提供する(3.6の対応表参照)。
3. クラシックエディタ(TinyMCE・クラシックメタボックス)専用の挙動調整は、WP 7.0 を下限とする本FWでは収載しない。必要案件はapp側Hookクラスで実装する。
4. ACF/CF7/Yoast連携・View乗っ取り(`template_include`+exit)・`load_global` は**本パッケージにも収載しない**(完全廃止。3.6の対応表参照)。
5. 全モジュールはフロント/管理画面の双方で安全に `register()` できる(各モジュールが内部で適切なフックに繋ぐ)。

## 3.2 エントリAPI

### `TMSWP\JaToolkit\JaToolkit`(パッケージの入口)

```php
final class JaToolkit implements \TMSWP\Core\Kernel\ExtensionInterface
{
    /** @param ModuleInterface ...$modules 有効にするモジュール。同じクラスは1つまで */
    public function __construct(ModuleInterface ...$modules);

    public function register(\TMSWP\Core\Kernel\Kernel $kernel): void;
}
```

`KernelConfig`の`extensions`に`new JaToolkit(...)`を渡したKernelでだけ、ja-toolkitが有効になる(共通規約「登録API」)。モジュールはインスタンスで渡す(コンストラクタ引数の無いモジュールも`new JapaneseSlug()`のように渡す)。コンストラクタは、WordPressの関数を呼ばない。

`register()`の振る舞い(登録したKernelのbootの中で1回):

1. 検査を、何かを登録するより前にすべて行う。このインスタンスを登録済みなら`TMSWP\Core\Exception\LogicException`(1つのインスタンスは1つのKernelにだけ登録できる)。同じクラスのモジュールが2つ以上あれば`LogicException`。どちらも設定の誤りなので、debugに関係なく起動を止める。例外のときは、訳の読み込みもモジュールのフックも登録しない(登録は原子的)。
2. 訳の読み込み(`init` priority 1。`load_textdomain('tmswp', <ja-toolkit>/languages/tmswp-{$locale}.mo)`)を`$kernel->defer()`で予約する。モジュールを1つも渡さない`new JaToolkit()`でも予約する。
3. 渡した順に、各モジュールの`register()`を呼ぶ。

`init`の後のboot(遅延boot)の扱いは、Kernelに従う(文書02 §3.3。debugなら`register()`より前にKernelが例外にし、本番ならKernelが記録してキャッチアップする)。JaToolkitは独自の`did_action('init')`の検査を持たない。

ja-toolkitには、debugで振る舞いを変える処理とログを記録する処理が無く、`WP_DEBUG`も見ない。検査の失敗は、どれも設定の誤りとしてdebugに関係なく例外になる。

### `TMSWP\JaToolkit\ModuleInterface`

```php
interface ModuleInterface
{
    /** WPフックの登録のみを行う(副作用の即時実行は禁止)。JaToolkitを登録したKernelのbootの中で1回呼ばれる */
    public function register(): void;
}
```

## 3.3 モジュール仕様(`TMSWP\JaToolkit\Module\` 配下)

すべて `final class`・`ModuleInterface` 実装。コンストラクタ引数は全てreadonly。

### 3.3.1 `JapaneseSlug` — 日本語スラッグの自動変換

```php
final class JapaneseSlug implements ModuleInterface
{
    /**
     * @param string $format プレースホルダ: {post_type} {post_id}
     * @param list<string>|null $postTypes 対象の投稿タイプ(許可リスト)。nullなら公開の投稿タイプのうちattachment以外
     */
    public function __construct(string $format = '{post_type}-{post_id}', ?array $postTypes = null);

    public function register(): void;  // add_filter('wp_unique_post_slug', ..., 10, 5)

    public function filterSlug(string $slug, int $postId, string $postStatus, string $postType, int $postParent): string;
}
```

コンストラクタの検査(設定の誤り。debugに関係なく`TMSWP\Core\Exception\LogicException`):

- `$format`は`{post_id}`を必ず含み、プレースホルダの外は英小文字・数字・`_`・`-`だけ(投稿タイプのキーと同じ文字種)。`{post_id}`が無いと全投稿が同じスラッグの候補になり、`%`等を許すと置き換えたスラッグが再び変換の対象になるためである。`$format`は1回の走査で`{post_type}`・`{post_id}`と固定部分に分け(`preg_split()`でプレースホルダを捕捉する)、固定部分をそれぞれ検査する。プレースホルダを順に消してから検査すると、`{post_{post_type}id}`のような入れ子が、消した後にできる新しい`{post_id}`ごと消えて検査を通るためである。入れ子・閉じていない波括弧・知らないプレースホルダ(`{foo}`等)は、固定部分に波括弧が残るため拒む。下の長さの計算も、同じ解析の結果から行う。
- 置き換えたスラッグの最悪の場合の長さは200以下でなければならない。最悪の場合の長さは、プレースホルダの外の文字数と、`{post_type}`の出現数×20と、`{post_id}`の出現数×20の合計である(繰り返し現れるプレースホルダも数える)。上限の根拠は、`wp_posts`の`post_name`の列がvarchar(200)であること、投稿タイプのキーの上限が20文字であること、`ID`の列(BIGINT UNSIGNED)の最大値が20桁であることである。`wp_unique_post_slug()`は重ならない候補を200バイトに切り詰めないため、超えるスラッグの公開はデータベースの更新の失敗になり、投稿が下書きのまま残る。短いIDの投稿では収まる場合も、設定の誤りとして拒む。
- `$postTypes`は空の配列を許さず、各要素は投稿タイプのキーの形(英小文字・数字・`_`・`-`の1〜20文字)。投稿タイプが登録済みかは確かめない(投稿タイプは`init`で登録されるため)。

対象の投稿タイプ(許可リスト):

- `$postTypes`を渡したら、そのキーの投稿タイプだけ。
- `null`(既定)なら、変換の時点で`get_post_type_object()`の`public`が真の投稿タイプのうち、`attachment`以外(既定ではpost・pageと、公開のカスタム投稿タイプ)。`wp_block`・`wp_template`・`wp_navigation`等の内部の投稿タイプは`public`が偽のため対象にならない。

`filterSlug()` の確定アルゴリズム:

```
1. $postId === 0(新規・ID未確定)、$postTypeが対象でない、または
   preg_match('/(%[0-9a-f]{2})+/i', $slug) が偽(URLエンコードされた多バイト文字を含まない)
   → $slug をそのまま返す
   ($postId === 0の場合は、保存後の再サニタイズで本フィルタが再度呼ばれた時点で変換される。旧実装と同一)
2. $format の {post_type}/{post_id} を置換した文字列を作る(例: 'blog-123')
3. それをwp_unique_post_slug($converted, $postId, $postStatus, $postType, $postParent)に通した値を返す
```

2の置き換えは`strtr()`で行い、置き換えた結果を再び走査しない。2で作った文字列が200バイトを超えたら、`LogicException`にする(コンストラクタの検査により本来起きない)。末尾を切り詰めないのは、必須の`{post_id}`を失うためである。

3はフィルタの戻り値がWordPressの一意性の確認の後の値として使われるため、置き換えたスラッグの一意性をもう一度確かめる(重複していれば`-2`等の接尾辞が付く)。置き換えたスラッグはURLエンコードした文字を含まないため、その中で呼ばれる本フィルタは値を変えない(再帰しない)。

### 3.3.2 `FullwidthSearchNormalizer` — 全角スペース検索の正規化

```php
final class FullwidthSearchNormalizer implements ModuleInterface
{
    public function register(): void;  // add_action('pre_get_posts', ..., 10, 1)

    public function normalize(\WP_Query $query): void;
}
```

`normalize()` の確定アルゴリズム:

```
1. $query->is_search() でない、または query_var 's' が文字列でないか空文字 → 何もしない
2. $s = mb_convert_kana($s, 's', 'UTF-8')  // 全角スペース→半角
3. 半角スペースで分割→空要素を除去→半角スペース1つで再結合(連続スペースの圧縮と先頭末尾のtrimを兼ねる)
4. $query->set('s', $s)
5. 3の結果が空(全角・半角のスペースだけの検索語)なら、$query->set('post__in', [0])で0件にする
```

5は、`s`が空文字の検索にWordPressが検索の条件を付けず、全件を返すためである。空白だけの検索語は「何も探していない」のではなく「該当なし」として扱い、`is_search()`は真のまま残す(検索結果のテンプレートで0件として表示される)。正規化しないで元の検索語のまま検索させる案は、全角スペースを含む投稿(和文では多い)に当たるため採らない。

旧実装の「`init` で `$_GET['s']` を直接書き換える」方式はスーパーグローバル汚染(他プラグインへの副作用)のため廃止し、クエリオブジェクト側で正規化する。メイン/サブクエリを問わず `is_search()` のクエリ全てに適用する。

### 3.3.3 `DisableTexturize` — wptexturize無効化

```php
final class DisableTexturize implements ModuleInterface
{
    public function register(): void;  // add_filter('run_wptexturize', '__return_false')
}
```

旧実装は `the_title`/`the_excerpt`/`the_content`/`comment_text` から個別に `remove_filter` していたが、ウィジェット等に漏れがあった。WP公式の一括無効化フィルタ `run_wptexturize` に置換する(タイトル・本文・抜粋・コメントすべてに効く)。

## 3.4 Form プリセット

### `TMSWP\JaToolkit\Form\KanaTextField`

カナ入力用の構成済み `Field` を返す静的ファクトリ。WPフックを登録しないため `ModuleInterface`は実装しない。フォーム定義で直接 `KanaTextField::create()` を呼ぶ。

```php
namespace TMSWP\JaToolkit\Form;

use TMSWP\Core\Form\Field;

enum KanaType
{
    case Katakana;
    case Hiragana;
}

final class KanaTextField
{
    /**
     * @param list<RuleInterface> $rules
     */
    public static function create(
        string $name,
        string $label,
        KanaType $type = KanaType::Katakana,
        array $rules = [],
        int $maxLength = 100,
    ): Field;
}
```

- filters: 前後の空白(全角スペースを含む)の除去(`Field::text()`の既定)の後に、表記ゆれを正規化する。まず`mb_convert_kana(..., 'KV')`で半角カタカナを全角に、濁点・半濁点を1文字にし、次にKatakanaは`'Cs'`(ひらがな→カタカナ、全角スペース→半角)、Hiraganaは`'cs'`(カタカナ→ひらがな、全角スペース→半角)で変換する。2回に分けるのは、1回の`'KVc'`では半角カタカナがひらがなまで変換されないためである。`mb_convert_kana()`が変換しないゔ・ゕ・ゖとヴ・ヵ・ヶも、項目の文字種に合わせて変換する。
- rules: `$rules`を先頭に展開し(`Required` 等の必須条件を呼び出し側で追加できる)、続けて正規化後の文字種を検証する`KanaRule($type)`と`MaxLength($maxLength)`を加える。文字数は正規化の後の値で数える。
- `TMSWP\JaToolkit\Form\KanaRule`(coreの`Rule`を継承するfinal class)は、値が次の文字だけからなることを検証する。改行・タブ等の空白は通さない。
  - Katakana: 全角カタカナ(ァ(U+30A1)〜ヶ(U+30F6)。ヴ・ヵ・ヶを含む)・長音符(ー)・中黒(・)・半角スペース
  - Hiragana: ひらがな(ぁ(U+3041)〜ゖ(U+3096)。ゔ・ゕ・ゖを含む)・長音符(ー)・中黒(・)・半角スペース
- メッセージ: ソース文字列は英語(`Please enter {label} in katakana.` / `Please enter {label} in hiragana.`)で、`KanaRule::defaultMessage()`が検証の時点で`__('...', 'tmswp')`を通す(coreの規則と同じ遅延解決)。フォーム定義を`init`より前(訳の読み込みの前)に作っても、検証の時点で読み込んだ訳が出る。
- 訳: 日本語訳はja-toolkitの翻訳ファイルで提供し、JaToolkitを`KernelConfig`の`extensions`に登録したときに読み込む(§3.2)。モジュールを使わずKanaTextFieldだけを使う場合も、`new JaToolkit()`(モジュール0個)を登録する。JaToolkitを登録しない場合、訳は保証しない(ほかのKernelがja-toolkitを登録していれば同じ`tmswp`ドメインの訳が出るが、それに頼らない)。

## 3.5 利用例(app側)

```php
// tmswp-kernel.php
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\{JapaneseSlug, FullwidthSearchNormalizer, DisableTexturize};

return KernelConfig::theme(
    __DIR__ . '/app',
    'App',
    extensions: [
        new JaToolkit(
            new JapaneseSlug(),                // 既定: '{post_type}-{post_id}'、公開の投稿タイプ(attachment以外)
            new FullwidthSearchNormalizer(),
            new DisableTexturize(),
        ),
    ],
);
```

KanaTextFieldだけを使う場合は、モジュールを渡さずに登録する(訳の読み込みのため)。

```php
return KernelConfig::theme(__DIR__ . '/app', 'App', extensions: [new JaToolkit()]);
```

```php
FormDefinition::create('contact')
    ->add(KanaTextField::create('kana', 'フリガナ', rules: [new Required()], maxLength: 50));
```

管理画面・wp_head 等の汎用クリーンアップは、コアの rules/hooks.md のレシピに従いapp側Hookクラスで実装する。

## 3.6 旧AbstractHook全フック棚卸し→対応表

旧 `Iwf2b\AbstractHook::initialize()`(658行・実ソース確認済み)の全登録フックと新FWでの処遇。**太字=本パッケージのモジュール**。

| 旧フック登録(AbstractHook) | 内容 | 新FWでの処遇 |
|---|---|---|
| `init` → `init_theme()` | show_acf/hide_acfクッキー切替 | **廃止**(ACF開発者向けトグル。acf-bridgeにも収載しない) |
| `wp_terms_checklist_args` → `terms_checklist_args()` | 選択済みタームの最上部移動を無効化 | 廃止(クラシックメタボックス専用。WP 7.0 下限の本FWでは収載しない。必要案件はapp側Hookクラスで実装) |
| `admin_enqueue_scripts` → `check_tinymce_override()` / `tiny_mce_before_init` → `override_mce_options()` | 固定ページTinyMCEの空タグ削除抑制 | 廃止(TinyMCE=クラシックエディタ専用。同上) |
| `widgets_init` → `remove_default_widgets()` | 標準ウィジェット13種解除 | レシピ化(rules/hooks.md。`widgets_init` priority 11 で `unregister_widget()`) |
| `admin_menu` → `remove_admin_menu()` | 管理メニュー全削除 | レシピ化(rules/hooks.md の `AdminCleanupHook` 例。削除対象はテーマ毎にapp側で明示) |
| `wp_dashboard_setup` → `remove_dashboard_widgets()` | ダッシュボードウィジェット削除 | レシピ化(同上) |
| `wp_before_admin_bar_render` → `remove_admin_bar_menu()` | 管理バーノード削除 | レシピ化(同上。admin_bar_menu+remove_nodeへ近代化) |
| `template_redirect` → `before_filter()` | テンプレ表示前処理(空フック) | 廃止。必要ならapp側 `Hook\Hook` 継承クラスで直接 `add_action` |
| `pre_get_posts` → `query_filter()` | クエリ調整(空フック) | 同上(app側Hookクラス) |
| `template_include` priority 100 → `template_filter()` | **View乗っ取り**: actions/読込+`View::load_global()`+`exit` | **完全廃止**(DR-10。代替なし。WP標準テンプレート階層をそのまま使う) |
| `the_content` priority 1 → `pre_the_content()` | 固定ページのwpautop解除 | 廃止(クラシックエディタ本文専用。必要案件はapp側Hookクラスで実装) |
| `posts_clauses` → `posts_sql_filter()` | SQL調整(空フック) | 廃止(app側Hookクラス) |
| `wp_enqueue_scripts` → `enqueue_scripts()` | アセット読込(空フック) | 廃止(app側Hookクラス) |
| `wp_head` → `head_scripts()` | `var ajaxurl = ...` のインラインscript出力 | 廃止。rules/レシピ化(`wp_add_inline_script`/`wp_localize_script` を案内) |
| `wpcf7_support_html5` / `wpcf7_map_meta_cap` → `cf7_meta_cap()` | **CF7連携** | **廃止**(DR-10/DR-16。コアにも全拡張にも収載しない。必要案件はapp側で2行書く) |
| `wpseo_metabox_prio` → `yoast_seo_metabox_priority()` | **Yoast連携**(メタボックス位置low) | **廃止**(同上) |
| `remove_action wp_head`: shortlink/generator/rsd/wlwmanifest/rest_output_link | headタグ掃除 | レシピ化(rules/hooks.md。wlwmanifest_linkのみ対応不要: WP 6.3で本体撤去済み・本FW下限WP 7.0のため全環境で恒久no-op) |
| `remove_action wp_head`: feed_links_extra | フィードリンク削除 | レシピ化(同上) |
| 絵文字3点セット+`emoji_svg_url` | 絵文字無効化 | レシピ化(同上。admin側remove_actionを含めて完全化) |
| `remove_action welcome_panel` | ウェルカムパネル削除 | レシピ化(同上) |
| `init` → `filter_search_keyword()` | `$_GET['s']` の全角スペース正規化 | **FullwidthSearchNormalizer**(pre_get_posts方式へ刷新) |
| `wp_unique_post_slug` → `auto_post_slug()` | 日本語スラッグ→`{post_type}-{id}` 変換 | **JapaneseSlug**(visibility欠落バグ修正・format設定可) |
| `remove_filter wptexturize` ×4 | テクスチャライズ無効化 | **DisableTexturize**(run_wptexturizeへ一本化) |
| `wp_insert_post` → `save_preview_post()` / `save_preview_postmeta` アクション発火 | プレビュー時メタ保存の基盤 | コアへ移管: `#[WithMetaRevisions]`(オプトイン。Meta設計書が定義) |
| `class_exists('ACF')` ブロック一式: `acf_load_config`/`acf_activated`/`acf/settings/path`/`acf/settings/dir`/`acf/load_field`/`acf_field_auto_export`/`acf_save_preview_postmeta`/`acf/settings/show_admin` | **ACF連携**(同梱パス・JSON自動書出し・表示トグル) | **廃止→acf-bridgeへ移管**(DR-06。acf-json真実源化により自前JSON書出し`acf_field_auto_export`は役割消滅。`acf_save_preview_postmeta`はACF標準で足りるため継承しない。詳細はacf-bridge設計書) |
| `iwf2b/meta/*`・`iwf2b/option/*` フィルタ → `*_with_acf()` ×6 | nullセンチネルのメタ/オプションACF委譲(**フィルタ名不一致で実際は機能していなかった**) | **廃止**(DR-18。後継は `MetaStorageInterface` + `#[UseMetaStorage]` の明示オプトイン。acf-bridgeの `AcfMetaStorage` が実装) |

## 3.7 旧iwf2bからの設計根拠

- 「継承一発・全部入り・破壊的デフォルト」が利用側の分裂(10テーマが継承/5プロジェクトが回避して再実装)を招いた → 1関心事=1モジュールの明示オプトインへ。
- ACF/CF7/Yoast連携のハードコードが、それらを使わないプロジェクトにまで暗黙の結合を強いた → 外部プラグイン制御コードはコア・本パッケージから完全排除(要件4/DR-10)。
- `auto_post_slug`/`yoast_seo_metabox_priority` のvisibility欠落、`$_GET` 直接書き換え等の品質問題 → 全モジュールで型宣言+strict_types+クエリオブジェクト操作に統一。
- 管理画面整理・wp_head掃除等の汎用クリーンアップは削除対象がテーマ毎に変動し、FW側の一律プリセットは「継承一発で全部消える」の変種になりやすい → コード収載をやめ、rules/hooks.md のapp側Hookクラスレシピへ移した(削除対象は必ず案件側で明示する)。
- 旧TinyMCE空タグ抑制・checked_ontop調整・固定ページwpautop解除はクラシックエディタ専用の挙動であり、WP 7.0(ブロックエディタ標準)を下限とする本FWでは収載しない。旧wlwmanifest_link対応も WP 6.3 で本体撤去済みのため対応を持たない。

---

# 受け入れ基準

## 共通

- [ ] 3パッケージとも `composer.json` が共通規約(php>=8.3、tmswp/core ^0.10、PSR-4)に従い、固有依存(ext-gd/ext-mbstring)が正しいパッケージにのみ存在する
- [ ] 全クラスが `declare(strict_types=1)`・PSR-12・final優先・全シグネチャ型宣言・マジックメソッド不使用・`extract()` 不使用
- [ ] 3パッケージとも、1つのインスタンス(`SettingsPages`・`Thumbnail`・`JaToolkit`)を2つのKernelの`KernelConfig`の`extensions`に渡すと、2つ目のKernelのbootが`LogicException`になる(debugに関係なく。ユニットテスト)
- [ ] 同じ種類の拡張を、2つのKernelにそれぞれ別のインスタンスで登録すると、settings-pageとja-toolkitはどちらのKernelでも有効になり、thumbnailは2つ目のKernelのbootが`LogicException`になる(debugに関係なく。ユニットテスト)
- [ ] PHPStan level max(szepeviktor/phpstan-wordpress)をbaselineなしで通過: `composer analyse`
- [ ] `composer lint` / `composer test` / `composer qa` が成功

## tmswp/thumbnail

- [ ] 無署名・署名不一致リクエストが403になる(統合テスト)
- [ ] `w=050` 等、URL生成時と異なる表記のパラメータが署名不一致403になる(生文字列payload照合のテスト)
- [ ] 値の中の`|`で別の値の組み合わせと同じ署名にならず、鍵が`wp_salt('auth')`そのものでなく用途を入れて導出したものである(ユニットテスト)
- [ ] `maxDimension` 超過の `w`/`h` が400になる(署名済みでも拒否=多層防御のテスト)
- [ ] 署名済みURLでも `o` の `quality` 範囲外(0・101等)・許可外キー混入が400になる(手順2.5のテスト)
- [ ] `src` に `../` やuploads外URL・リモートURLを与えると、エンドポイントが404を返す
- [ ] `basedir` と同prefixの別ディレクトリ(例: `uploads-evil/x.jpg`)・uploads外を指す添付メタ(`id:n`)が `SourceResolver` で拒否される(realpath最終ゲートのテスト)
- [ ] `..`の区間・エンコードした絶対パス等でuploadsの外を指すsrcが、相手のファイルの有無に関係なく`InvalidThumbSourceException`になる。basedirの末尾が`/`のサイトの添付ファイルは解決される。uploadsの外の画像を指すシンボリックリンクと、実体が許可されない拡張子のシンボリックリンクは拒む(ユニットテスト)
- [ ] 既定値以外のオプション(`crop=0&quality=90`)と`%`を含むsrcのURLが403にならず、生成される(統合テスト)
- [ ] 初回リクエストで `uploads/tmswp-thumb/{shard}/{key}.{ext}` が実画像として生成され、`Cache-Control: public, max-age={redirectMaxAge}`の302が返り、`tms_thumb()` の2回目以降の呼び出しがキャッシュ静的URLを直接返す。RESTが既存のファイルを返すと、ファイルの更新日時が今になる(統合テスト)
- [ ] 生成が一時ファイル+`rename()`とキー単位のロックで行われ、一時ファイルとロックのファイルが残らない。ロックの競合では、元画像へ`no-store`の302を返す(統合テスト)
- [ ] 手放すときに消したロックのファイルを、消す前に開いていたプロセスが後からflockしても、新しいロックのファイルを持つプロセスと同時にロックを持たない(実OSのflockと複数のプロセスによるユニットテスト)
- [ ] GCが、ロックを持たれている(生成中の)キーのロックと一時ファイルを消さず、残ったロックのファイルは消す。ロックのファイルを調べる間に消されても、警告を出さずに取り直す(ユニットテスト)
- [ ] GCと`status`が、キャッシュ自身が作ったファイルだけを扱い、キャッシュの形でないファイル(shardの中のシンボリックリンク、16進でない2文字のディレクトリの中のファイルを含む)を消さない。shardのディレクトリが外を指すシンボリックリンクなら、読み書きの前に例外になる(ユニットテスト)
- [ ] キャッシュのディレクトリの初回の作成で印のファイルができ、印の無い既存のディレクトリ(年と月のディレクトリにキーの形の名前のファイルがある等)では、`clear`・`status`・`ensureDirectory`が例外になって何も消さない(ユニットテスト)
- [ ] baseurl・basedirの末尾が`/`のサイトでも、WordPressと同じ結合で作ったURL・添付ファイルのパスを解決する(ユニットテスト)
- [ ] 元画像より大きい要求が元画像の寸法で頭打ちになり、2回目の要求で再生成しない(統合テスト)
- [ ] 画像エディタが扱えない元画像は、本番では元画像へ`no-store`の302を返してKernelのロガーにerrorで記録し(文脈は例外のクラス・場所・previousのクラスだけ)、同じ要求で再生成も再記録もしない。debugのKernelでは例外になる(統合テスト)
- [ ] 解決の後に元画像が消えた要求は、debugでも本番でも404になる。本番で、元画像のURLを決める前の準備に失敗したら、Kernelのロガーに記録して500を返す(統合テスト)
- [ ] `ThumbnailConfig`が、範囲外の`maxDimension`・`defaultQuality`・`redirectMaxAge`と、1つの安全な名前でない`cacheDirName`(`../victim`等)を例外にし、uploadsの外を指すキャッシュのディレクトリを操作の前に例外にする(ユニットテスト)
- [ ] 出力形式を変えるフィルタ(`image_editor_output_format`)が効くと、`save()`が書いたファイルの拡張子でキャッシュし、2回目は再生成しない(統合テスト)
- [ ] 元画像の `filemtime` の変更と、`ThumbnailConfig`の既定のcrop・qualityの変更で、キャッシュキーが変わる
- [ ] `tms_thumb()`が、アイキャッチ未設定・添付ファイルの実体が無いときはdebugでも例外を投げずに`null`を返す。引数・オプションの誤りはdebugに関係なく例外。実行時の失敗は、登録したKernelがdebugなら例外、本番ならKernelのロガーにerrorで記録して`null`を返す(文脈に例外のオブジェクト・メッセージ・トレースが無い)。`WP_DEBUG`を見ない
- [ ] thumbnailを2つ目のKernelに登録すると`LogicException`になり、登録が無い状態の`tms_thumb()`も`LogicException`になる
- [ ] RESTのルートが訪問者から使えない(ルートの削除・訪問者への認証のエラー)ことを検出して、管理画面で`manage_options`のユーザーに通知し、使えるときと権限の無いユーザーには通知しない。管理画面の要求のnonce(GET・POST・REQUEST・X-WP-Nonce)で誤って通知せず、確かめた後(例外のときを含む)にユーザー・nonceのキーの有無と値・認証のフィルタの順序が元のままである(統合テスト)
- [ ] `wp tmswp thumb clear --days=30` が、更新日時が30日より前のファイルだけを削除し、`index.html`を残す。`--days`の0・負・整数でない値・値の無い`--days`・上限(`intdiv(PHP_INT_MAX, 86400)`日)を超える日数・`redirectMaxAge`以下の日数が、何も消さずに`Error:`と終了コード1になる(`WP_CLI::error()`)。`wp tmswp thumb status`が`index.html`・ロック・一時ファイルを数えない
- [ ] `tms_thumb()`が`function_exists`のガードで囲まれていない(コードレビュー)
- [ ] 検証: `wp-env` 上で `curl -i "$(wp eval 'echo tms_thumb(123, 100, 100);')"` → 302 → 画像取得

## tmswp/settings-page

- [ ] `KernelConfig`の`extensions`に`new SettingsPages()`を渡したKernelで、`#[AsSettingsPage]` クラスがapp/に置くだけでメニュー登録される(wp-env統合テスト。コアDiscoveryの `ComponentAttributeInterface` instanceof収集経由であり、本パッケージ独自の探索コードが存在しないことをコードレビューで確認)
- [ ] KernelにSettingsPagesを登録していない場合、`#[AsSettingsPage]` クラスの初期化がdebugに関係なく `LogicException` になる。1つのSettingsPagesを2つのKernelに登録すると`LogicException`になる
- [ ] コンストラクタに渡したページの`definition()`が、bootでは呼ばれず`admin_menu`(initの後)で呼ばれる
- [ ] nonce欠落・不正nonceのPOSTで `onSubmit` が呼ばれない(CSRFテスト=本パッケージ最重要)
- [ ] `capability` を満たさないユーザーで管理画面のメニューの作成が例外にならず(`add_submenu_page()`のfalseを正常として扱う)、ページ表示・POSTの両方が`wp_die()`の403で拒否される(統合テスト)
- [ ] FWがフォームの外枠(`$form->open()`・`_step=submit`の保存ボタン・`$form->close()`)と、フォームエラー・項目のエラーのエラーnoticeを出し、render()は項目だけを描画する(統合テスト)
- [ ] 送信成功時に302(PRG)が返り、リダイレクト先(`url()`の生のURL)で成功noticeが表示される。`wp_safe_redirect()`の直後の`exit`は、00-overview.mdの原則1の例外の範囲だけにある
- [ ] `OptionFormBridge::applyDefaults`が、int・bool・arrayのオプション(optionsテーブルを経た文字列の形を含む)を項目の既定値の形に変換し、int・boolのオプションを結んだ設定ページが例外にならない(統合テスト)
- [ ] `OptionFormBridge::persist` がOptionSchema宣言済みキーのみをスキーマの型に変換して保存し、未宣言フィールドを保存しない。変換できない値と`$only`の未宣言の名前は`LogicException`になり、どのキーも保存しない
- [ ] 片側にしかない名前と、型の形でない保存値(本番)が、登録したKernelのロガーに記録される
- [ ] slug重複登録(Kernelをまたぐ場合を含む)が `LogicException`
- [ ] gettextのソース文字列が英語で、ja訳(`languages/`)が`init`で読み込まれる(統合テスト)
- [ ] 旧 `action` クエリパラメータに相当するファイルinclude機構が存在しない(コードレビュー項目)

## tmswp/ja-toolkit

- [ ] `KernelConfig`の`extensions`にJaToolkitを登録しないKernelでは、本パッケージはWPの挙動を一切変更しない(フック登録ゼロ)。登録の経路は`extensions`だけで、静的な登録APIが無い
- [ ] `new JaToolkit()`(モジュール0個)の登録は訳の読み込み(`init` priority 1)だけを予約し、モジュールのフックを登録しない。モジュールを渡すと、渡したものだけが有効になる(ユニットテスト)
- [ ] 1つのJaToolkitを2つのKernelに登録すると`LogicException`になる。同じクラスのモジュールが2つあると`LogicException`になり、前に並んだモジュールのフックも訳の読み込みも登録されない(登録の原子性。ユニットテスト)
- [ ] `function_exists`でWordPressの関数(`did_action`等)の有無を確かめて検査を飛ばす分岐が無い(grep検証)
- [ ] 収載モジュールが日本語固有の3つ(`JapaneseSlug` / `FullwidthSearchNormalizer` / `DisableTexturize`)のみで、汎用クリーンアップ系モジュールが存在しない
- [ ] モジュール0個のJaToolkitを登録したKernelで、`init`より前に作ったKanaTextFieldの違反のメッセージがja訳になる(統合テスト。メッセージの遅延解決)
- [ ] `KanaTextField` がカタカナ/ひらがなの正規化(半角カタカナ・全角スペース・ヴ/ゔ等)と検証を行い、改行・タブ・漢字・英字・上限超過を弾く(ユニットテスト)
- [ ] `JapaneseSlug`のformatが`{post_id}`を含まない・使えない文字を含む場合と、許可リストが空・投稿タイプのキーの形でない場合が`LogicException`(ユニットテスト)
- [ ] `JapaneseSlug`が、既定では公開の投稿タイプ(attachment以外)だけを、許可リストを渡せばその投稿タイプだけを変換し、attachment・非公開・内部の投稿タイプ(`wp_block`等)を変換しない。置き換えたスラッグが既存の投稿と重なると、接尾辞の付いた一意のスラッグになる(統合テスト)
- [ ] 全角・半角のスペースだけの検索が、`is_search()`のまま0件になる(全件にならない)。`?s=東京%E3%80%80タワー`(全角スペース)の検索クエリが半角正規化される(統合テスト)
- [ ] 3.6対応表で本パッケージ担当の各モジュールについて、旧AbstractHook相当の挙動が再現される統合テスト(例: JapaneseSlugで日本語タイトル投稿のスラッグが`{post_type}-{id}`になる、DisableTexturize有効時にタイトル・本文へwptexturizeが適用されない)
- [ ] CF7/Yoast/ACF関連のフック名(`wpcf7_*`/`wpseo_*`/`acf*`)がパッケージ内に存在しない(grep検証)
- [ ] `$_GET`/`$_POST`/`$_REQUEST` への書き込みが存在しない(grep検証)

検証コマンド(monorepo共通): `composer qa` / `composer test -- --testsuite=unit` / `composer wp:start && composer test:integration`
