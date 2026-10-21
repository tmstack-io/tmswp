# サイト設定値を定義する

サイト設定は backed enum と `OptionAccess` で宣言する。`get_option()` / `update_option()` を直接呼ばない。

## 使い分け

- 入力検証が重要なシステム設定(メールの宛先、APIキー等)は、このオプショングループで宣言し、設定画面はsettings-pageで作る。APIキー等の秘密情報もここに保存してよい(下の「してはいけないこと」の扱いを守る)。
- フロントに出すコンテンツ設定(画像、繰り返し項目等)は、ACFのオプションページで作り、acf-bridgeの生成アクセサ(`XxxOptions`)で読む(rules/acf.md)。同じ値をオプショングループにも宣言しない。
- 投稿・ターム・ユーザーごとの値は設定ではなくメタで扱う(rules/meta.md)。

## 手順

1. `app/Option/SiteOption.php` を作る。
2. `#[AsOptionGroup]` を付けた string backed enum にする。
3. ケースに `#[OptionSchema]` を付ける。
4. 頻繁に読む設定は、enum に明示アクセサ(下の例の `contactEmail()`)を static メソッドで書く。

## 完全な実装例

```php
<?php

declare(strict_types=1);

namespace App\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;

#[AsOptionGroup(prefix: 'site_')]
enum SiteOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: '')]
    case ContactEmail = 'contact_email';

    public static function contactEmail(): string
    {
        return self::ContactEmail->string();
    }
}
```

## キーの渡し方

- 宣言済みのキーは enum のケースで読み書きする(`SiteOption::ContactEmail->string()`)。`SiteOption::repository()->get('contact_email')` のように文字列で読み書きしない。この規約は自動では検査しないため、レビューで確かめる。
- 頻繁に読む設定は、テンプレートから enum のケースを直接呼ばず、明示アクセサ(`SiteOption::contactEmail()`)を呼ぶ。

## 未宣言のキーを読み書きする

- グループの接頭辞の中で、ケースとして宣言していないキーは、`SiteOption::repository()` の `OptionRepository` に文字列で渡して読み書きする。接頭辞は付くが、スキーマの既定値と型の検査は効かない。
- ほかのプラグインのオプション等、接頭辞の無いオプション名は、アプリの Kernel の Container から `OptionStorageInterface` の保存先を取り出して読み書きする。Container が作るクラス(`#[AsHook]` のクラス等)なら、コンストラクタの引数に `OptionStorageInterface` を書けば渡される。`get_option()` を直接書かない。`WpOptionStorage` を自分で `new` しない(保存先は、取り出した Kernel の debug とログでデータベースのエラーを扱い、テストで差し替えた実装にもなる)。

```php
<?php

use App\Option\SiteOption;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Option\OptionStorageInterface;

// 実際のオプション名は site_extra_note(グループの接頭辞が付く)
$note = SiteOption::repository()->string('extra_note');

// 接頭辞の無いオプション名
$options = Kernel::forClass(SiteOption::class)->container()->get(OptionStorageInterface::class);
$siteName = $options->get('blogname');
```

## 読み取りの規則

- 読み取り(`has()`・`get()`・型付きの getter・`all()`)の途中でデータベースのエラーが起きたら、オプショングループ(または保存先を取り出した Container)の Kernel の debug では `RuntimeException` が投げられる。本番では、その Kernel のログに error で記録され、値が無いときと同じ結果(引数の既定値・スキーマの既定値等)が返る。
- キャッシュから読んでデータベースに問い合わせなかった読み取りは、エラーにならない。

## 書き込みの規則

- `set()` は書き込んだら `true`、書き込まなかったら `false` を返す。`false` は失敗ではなく、同じ値だった場合と、ほかのプラグインのフィルタが書き込みを止めた場合で、保存されている値は変わっていない。データベースのエラーで書き込めなかったら `RuntimeException` が投げられる。
- 同じ値かどうかは WordPress の `update_option()` が決める。サニタイズ(`sanitize_option_{$name}`)やフィルタの結果が保存済みの値と同じになった場合も、`update_option()` が書き込まずに `false` を返すので `false` になる。
- 宣言済みのケースには、`#[OptionSchema]` の型に合う値だけを渡す(`MetaValueType::Boolean` なら bool)。合わない値は `InvalidArgumentException` になる。フォームの文字列は呼び出し側で型を変換してから渡す。`MetaValueType::String` のケースに `Stringable` のオブジェクトを渡しても `InvalidArgumentException` になる(`(string)` にしてから渡す)。
- 値を消すときは `delete()` を使う。`null` を `set()` すると `InvalidArgumentException` になり、保存されない。
- `deleteAll()` は実際に削除したオプション名だけを返す。

## テスト

- WordPressなしのユニットテストでは、既定の保存先をメモリに保存する実装に差し替える。既定の保存先は Container の `OptionStorageInterface` の id で解決される(何も登録しなければ `WpOptionStorage`)。`#[AsOptionGroup]` で `storage` を指定しないグループと、Container から取り出した `OptionStorageInterface` の両方が差し替わる。`repository()` は差し替えない。
- 差し替えは、`KernelConfig` の `extensions` に渡す拡張の `register()` の中で、`$kernel->container()->set(OptionStorageInterface::class, ...)` で登録する。方法はこれだけ。boot の後に登録すると `ContainerException` になる。
- `OptionStorageInterface` を実装しないものを登録すると、登録の時点か boot の時点で `ContainerException` になる。
- メモリの実装はFWに含まれない。下の例をアプリの `tests/` に置く。
- `WpOptionStorage` そのものの動き(同じ値の判定、データベースのエラーの扱い)に頼るコードは、wp-env の統合テストで確かめる。

`tests/Support/MemoryOptionStorage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Option\OptionStorageInterface;

final class MemoryOptionStorage implements OptionStorageInterface
{
    /** @var array<string, mixed> 実オプション名ごとの値 */
    private array $values = [];

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->values);
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value, ?bool $autoload = null): bool
    {
        if ($value === null) {
            throw new InvalidArgumentException("option '{$name}' cannot store null; use delete() to remove it");
        }

        if ($this->has($name) && $this->values[$name] === $value) {
            return false;
        }

        $this->values[$name] = $value;

        return true;
    }

    public function delete(string $name): bool
    {
        $existed = $this->has($name);
        unset($this->values[$name]);

        return $existed;
    }
}
```

`tests/Support/MemoryOptionStorageExtension.php`(boot の中で既定の保存先を登録する拡張):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Option\OptionStorageInterface;

final class MemoryOptionStorageExtension implements ExtensionInterface
{
    public function __construct(
        private readonly MemoryOptionStorage $storage,
    ) {}

    public function register(Kernel $kernel): void
    {
        $kernel->container()->set(OptionStorageInterface::class, $this->storage);
    }
}
```

`tests/Unit/ContactEmailTest.php`。WordPressなしで boot するため、`$wp_version`・`$wp_actions`・`$wpdb` のグローバルを置き、フック関数は Brain Monkey に任せる。`wpdb` は Mockery の代役にする。

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Option\SiteOption;
use App\Tests\Support\MemoryOptionStorage;
use App\Tests\Support\MemoryOptionStorageExtension;
use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use wpdb;

final class ContactEmailTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        $GLOBALS['wpdb'] = Mockery::mock(wpdb::class);
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions'], $GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testContactEmailIsReadFromTheMemoryStorage(): void
    {
        $storage = new MemoryOptionStorage();
        $storage->set('site_contact_email', 'info@example.test');
        Kernel::boot(KernelConfig::theme(
            \dirname(__DIR__, 2) . '/app',
            debug: true,
            extensions: [new MemoryOptionStorageExtension($storage)],
        ));

        self::assertSame('info@example.test', SiteOption::contactEmail());
        self::assertTrue(SiteOption::ContactEmail->set('sales@example.test'));
        self::assertSame('sales@example.test', $storage->get('site_contact_email'));
    }
}
```

## してはいけないこと

- オプション名を文字列で直書きしない。
- 巨大な配列を1オプションに保存しない。
- APIキー等の秘密情報をオプションに保存する場合も、テンプレートへの出力・ログへの記録・フロントへの公開(REST等)をしない。
- `#[OptionSchema]` をenumのケース以外(ケースでない定数・enum自身・メソッド・引数)に付けない(起動時に `InvalidSchemaException` になる)。`#[AsOptionGroup]` の無い enum のケースにも付けない(debug の boot と `wp tmswp discover` で `DiscoveryException` になる)。
- `set()` の戻り値 `false` を失敗として扱わない(保存値は変わっていない)。

## 確認方法

1. `composer qa` が通ること。
2. `SiteOption::ContactEmail->set()` 後に値が取得できること。
