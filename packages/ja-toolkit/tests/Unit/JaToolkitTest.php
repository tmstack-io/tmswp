<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\DisableTexturize;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;
use TMSWP\JaToolkit\Tests\Support\BootsJaToolkitKernel;

/**
 * JaToolkitの登録(KernelConfig経由、1インスタンス1Kernel、原子的な登録)と、JapaneseSlugの設定の検査・変換。
 */
final class JaToolkitTest extends UnitTestCase
{
    use BootsJaToolkitKernel;

    /** @var list<int> JaToolkitがinitに予約した訳の読み込みのpriority */
    private array $textdomainLoaders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];

        Actions\expectAdded('init')->zeroOrMoreTimes()->whenHappen(function (mixed $callback, int $priority): void {
            if ($callback instanceof Closure && (new ReflectionFunction($callback))->getClosureScopeClass()?->getName() === JaToolkit::class) {
                $this->textdomainLoaders[] = $priority;
            }
        });
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        parent::tearDown();
    }

    /** モジュールを渡さない登録は、訳の読み込み(init 1)だけを予約し、モジュールのフックを登録しない。 */
    public function testRegisterWithoutModulesOnlyDefersTranslationLoading(): void
    {
        $this->bootJaToolkitKernel('NoModules', new JaToolkit());

        self::assertSame([1], $this->textdomainLoaders);
        self::assertFalse(has_filter('run_wptexturize'));
        self::assertFalse(has_action('pre_get_posts'));
        self::assertFalse(has_filter('wp_unique_post_slug'));
    }

    /** 渡したモジュールだけを有効にする。 */
    public function testRegisterEnablesOnlyPassedModules(): void
    {
        $this->bootJaToolkitKernel('Modules', new JaToolkit(new DisableTexturize(), new FullwidthSearchNormalizer()));

        self::assertSame([1], $this->textdomainLoaders);
        self::assertNotFalse(has_filter('run_wptexturize', '__return_false'));
        self::assertNotFalse(has_action('pre_get_posts'));
        self::assertFalse(has_filter('wp_unique_post_slug'));
    }

    /** 1つのインスタンスは1つのKernelにだけ登録できる。2つ目のKernelの登録は、何も登録せずに例外になる。 */
    public function testInstanceCannotBeRegisteredToTwoKernels(): void
    {
        $jaToolkit = new JaToolkit();
        $this->bootJaToolkitKernel('First', $jaToolkit);

        try {
            $this->bootJaToolkitKernel('Second', $jaToolkit);
            self::fail('2つ目のKernelへの登録が例外になりませんでした。');
        } catch (LogicException $exception) {
            self::assertStringContainsString('this JaToolkit instance is already registered to the kernel', $exception->getMessage());
        }

        self::assertSame([1], $this->textdomainLoaders);
    }

    /**
     * 同じクラスのモジュールが2つあると、検査で例外になり、前に並んだモジュールも訳の読み込みも登録しない(A6-L09)。
     */
    public function testDuplicateModuleClassRegistersNothing(): void
    {
        try {
            $this->bootJaToolkitKernel('Duplicate', new JaToolkit(new DisableTexturize(), new FullwidthSearchNormalizer(), new DisableTexturize()));
            self::fail('同じクラスのモジュールが例外になりませんでした。');
        } catch (LogicException $exception) {
            self::assertStringContainsString('ja-toolkit module ' . DisableTexturize::class . ' is passed more than once', $exception->getMessage());
        }

        self::assertSame([], $this->textdomainLoaders);
        self::assertFalse(has_filter('run_wptexturize'));
        self::assertFalse(has_action('pre_get_posts'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFormats(): iterable
    {
        yield '{post_id}が無い' => ['{post_type}'];
        yield '英大文字' => ['Post-{post_id}'];
        yield 'スラッシュ' => ['{post_type}/{post_id}'];
        yield 'パーセント' => ['%e6-{post_id}'];
        yield '知らないプレースホルダ' => ['{post_name}-{post_id}'];
        yield '知らないプレースホルダ(foo)' => ['{foo}-{post_id}'];
        yield '閉じていない波括弧' => ['{post_type-{post_id}'];
        yield '開いていない波括弧' => ['post_type}-{post_id}'];
        // 内側の{post_type}を消すと{post_id}ができる入れ子。順に消して検査すると通ってしまう(F3)
        yield '入れ子' => ['{post_{post_type}id}-{post_id}'];
        yield '入れ子({post_id}の中)' => ['{post_{post_id}id}'];
        // 順に消して数えると最悪の長さが200になるが、実際には20文字の投稿タイプで235バイトになる入れ子の反復(F3)
        yield '入れ子の反復' => [str_repeat('x', 59) . str_repeat('{post_{post_type}id}', 6) . '-{post_id}'];
    }

    /**
     * formatは、`{post_id}`を含み、ほかは`{post_type}`とスラッグに使える文字だけでなければならない(A6-16)。formatは
     * 1回の走査でプレースホルダと固定部分に分けて検査するため、入れ子も拒む(F3)。
     */
    #[DataProvider('invalidFormats')]
    public function testJapaneseSlugRejectsInvalidFormat(string $format): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("invalid JapaneseSlug format '{$format}'");

        new JapaneseSlug($format);
    }

    /** @return iterable<string, array{string, string}> */
    public static function validFormats(): iterable
    {
        yield '既定' => ['{post_type}-{post_id}', 'blog-123'];
        yield 'IDだけ' => ['{post_id}', '123'];
        yield '接頭辞' => ['jp_{post_id}', 'jp_123'];
        yield '反復' => ['{post_type}-{post_type}-{post_id}-{post_id}', 'blog-blog-123-123'];
        yield 'プレースホルダが隣接' => ['{post_type}{post_id}', 'blog123'];
    }

    /** 正しいformatは通り、プレースホルダを1回の走査で置き換える。 */
    #[DataProvider('validFormats')]
    public function testJapaneseSlugAcceptsValidFormat(string $format, string $expected): void
    {
        Functions\expect('wp_unique_post_slug')->once()->with($expected, 123, 'publish', 'blog', 0)->andReturnFirstArg();

        self::assertSame($expected, (new JapaneseSlug($format, ['blog']))->filterSlug('%e6%9d%b1', 123, 'publish', 'blog', 0));
    }

    /** @return iterable<string, array{string}> */
    public static function formatsAtTheLengthLimit(): iterable
    {
        yield '外の文字180+{post_id}' => [str_repeat('a', 179) . '-{post_id}'];
        yield '外の文字140+{post_type}×2+{post_id}' => ['{post_type}{post_type}' . str_repeat('a', 139) . '-{post_id}'];
        yield '外の文字160+{post_id}×2' => ['{post_id}' . str_repeat('a', 160) . '{post_id}'];
    }

    /** 最悪の場合の長さ({post_type}を20文字、{post_id}を20桁として数える)がちょうど200なら通る(F1)。 */
    #[DataProvider('formatsAtTheLengthLimit')]
    public function testJapaneseSlugAcceptsFormatWhoseWorstLengthIs200(string $format): void
    {
        Functions\expect('wp_unique_post_slug')->once()->andReturnFirstArg();

        $slug = (new JapaneseSlug($format, ['blog']))->filterSlug('%e6%9d%b1', 123, 'publish', 'blog', 0);

        self::assertSame(str_replace(['{post_type}', '{post_id}'], ['blog', '123'], $format), $slug);
    }

    /** @return iterable<string, array{string}> */
    public static function formatsOverTheLengthLimit(): iterable
    {
        yield '外の文字181+{post_id}' => [str_repeat('a', 180) . '-{post_id}'];
        yield '外の文字141+{post_type}×2+{post_id}' => ['{post_type}{post_type}' . str_repeat('a', 140) . '-{post_id}'];
        yield '外の文字161+{post_id}×2' => ['{post_id}' . str_repeat('a', 161) . '{post_id}'];
    }

    /**
     * 最悪の場合の長さが201以上のformatは、短いIDの投稿では200バイトに収まるとしても例外にする。post_nameは
     * varchar(200)で、収まらないスラッグの公開はデータベースの更新の失敗になるため(F1)。
     */
    #[DataProvider('formatsOverTheLengthLimit')]
    public function testJapaneseSlugRejectsFormatWhoseWorstLengthExceeds200(string $format): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('can produce a slug of 201 bytes');

        new JapaneseSlug($format);
    }

    public function testJapaneseSlugRejectsEmptyOrInvalidPostTypes(): void
    {
        try {
            new JapaneseSlug(postTypes: []);
            self::fail('空の投稿タイプが例外になりませんでした。');
        } catch (LogicException $exception) {
            self::assertStringContainsString('post types must not be empty', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("invalid post type key for JapaneseSlug: 'Blog'");

        new JapaneseSlug(postTypes: ['post', 'Blog']);
    }

    /**
     * 置き換えたスラッグは、wp_unique_post_slug()にもう一度通した値を返す(一意性の再確認。A6-16)。対象外の投稿
     * タイプ・IDが未確定・URLエンコードの無いスラッグは、そのまま返す。
     */
    public function testJapaneseSlugConvertsOnlyAllowedPostTypesAndRechecksUniqueness(): void
    {
        Functions\expect('wp_unique_post_slug')->once()->with('blog-123', 123, 'publish', 'blog', 7)->andReturn('blog-123-2');
        $module = new JapaneseSlug(postTypes: ['blog']);

        self::assertSame('blog-123-2', $module->filterSlug('%e6%9d%b1%e4%ba%ac', 123, 'publish', 'blog', 7));
        self::assertSame('%e6%9d%b1%e4%ba%ac', $module->filterSlug('%e6%9d%b1%e4%ba%ac', 123, 'publish', 'post', 0));
        self::assertSame('%e6%9d%b1%e4%ba%ac', $module->filterSlug('%e6%9d%b1%e4%ba%ac', 0, 'publish', 'blog', 0));
        self::assertSame('plain-slug', $module->filterSlug('plain-slug', 123, 'publish', 'blog', 0));
    }
}
