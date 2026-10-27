<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Tests\Integration;

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Tests\Support\BootsKernelBeforeInit;
use TMSWP\Core\Tests\Support\IntegrationTestCase;
use TMSWP\Core\Tests\Support\UsesTempDirectories;
use TMSWP\JaToolkit\Form\KanaTextField;
use TMSWP\JaToolkit\Form\KanaType;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;
use WP_Post;
use WP_Query;

/**
 * 一時ディレクトリのプラグインのKernel(KernelConfigのextensionsにJaToolkitを登録したもの)を、initより前にbootし、
 * KanaTextFieldの訳・JapaneseSlugの許可リストと一意性・空白だけの検索の扱いを、実際のWordPressで確かめる。
 *
 * 日本語のタイトル・検索語・入力値は、テストの対象そのもの(日本語のスラッグ・全角スペース・かな)である。
 */
final class JaToolkitIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    /** 「東京」をURLエンコードしたスラッグ(sanitize_title()が日本語のタイトルから作る形) */
    private const ENCODED_SLUG = '%e6%9d%b1%e4%ba%ac';

    public function tear_down(): void
    {
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * モジュールを渡さないJaToolkitを登録すれば、KanaTextFieldだけを使っても違反のメッセージがja訳になる(A6-03)。
     * 項目はinitより前(訳の読み込みの前)に作り、メッセージは検証の時点で訳す(A6-15)。
     */
    public function testKanaTextFieldMessageIsTranslatedWithJaToolkitWithoutModules(): void
    {
        $definition = FormDefinition::create('kana')
            ->add(KanaTextField::create('kana_name', 'フリガナ', KanaType::Katakana))
            ->add(KanaTextField::create('hira_name', 'ふりがな', KanaType::Hiragana));
        $callbacks = $this->bootApp('Kana', new JaToolkit());
        $locale = static fn(): string => 'ja';
        add_filter('pre_determine_locale', $locale);

        try {
            self::fireInit($callbacks);

            $result = (new Form($definition))->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'kana',
                Form::FIELD_NONCE => wp_create_nonce('tmswp_form_kana'),
                Form::FIELD_STEP => 'submit',
                Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('kana'),
                'kana_name' => '山田',
                'hira_name' => "やまだ\nたろう",
            ]));

            self::assertSame(FormStep::Input, $result->step);
            self::assertSame(
                [
                    'kana_name' => ['フリガナは全角カタカナで入力してください。'],
                    'hira_name' => ['ふりがなはひらがなで入力してください。'],
                ],
                $result->fieldErrors,
            );
        } finally {
            remove_filter('pre_determine_locale', $locale);
            unload_textdomain('tmswp');
        }
    }

    /**
     * 既定の許可リスト(公開の投稿タイプのうちattachment以外)。公開した投稿のスラッグは`{post_type}-{post_id}`になり、
     * attachment・非公開の投稿タイプ・内部の投稿タイプ(wp_block)のスラッグは変わらない(A6-16)。
     */
    public function testJapaneseSlugConvertsOnlyPublicPostTypesExceptAttachmentByDefault(): void
    {
        register_post_type('tmswp_jt_private', ['public' => false]);
        $this->bootApp('SlugDefault', new JaToolkit(new JapaneseSlug()));
        $postId = $this->publishJapanesePost('post');

        self::assertSame("post-{$postId}", $this->post($postId)->post_name);
        self::assertSame("page-{$postId}", wp_unique_post_slug(self::ENCODED_SLUG, $postId, 'publish', 'page', 0));

        foreach (['attachment', 'tmswp_jt_private', 'wp_block'] as $postType) {
            self::assertSame(self::ENCODED_SLUG, wp_unique_post_slug(self::ENCODED_SLUG, $postId, 'publish', $postType, 0), $postType);
        }
    }

    /** 許可リストを渡すと、その投稿タイプだけを変換する。 */
    public function testJapaneseSlugConvertsOnlyListedPostTypes(): void
    {
        $this->bootApp('SlugList', new JaToolkit(new JapaneseSlug('jp-{post_id}', ['page'])));
        $pageId = $this->publishJapanesePost('page');
        $postId = $this->publishJapanesePost('post');

        self::assertSame("jp-{$pageId}", $this->post($pageId)->post_name);
        self::assertSame(self::ENCODED_SLUG, $this->post($postId)->post_name);
    }

    /** 置き換えたスラッグが既存の投稿と重なれば、WordPressの一意性の確認で接尾辞が付く(A6-16)。 */
    public function testJapaneseSlugRechecksUniquenessOfTheConvertedSlug(): void
    {
        $this->bootApp('SlugUnique', new JaToolkit(new JapaneseSlug()));
        $draftId = self::factory()->post->create(['post_title' => '東京', 'post_status' => 'draft']);
        self::assertIsInt($draftId);
        $existingId = self::factory()->post->create(['post_name' => "post-{$draftId}", 'post_status' => 'publish']);
        self::assertIsInt($existingId);
        self::assertSame("post-{$draftId}", $this->post($existingId)->post_name);

        wp_update_post(['ID' => $draftId, 'post_status' => 'publish']);

        self::assertSame("post-{$draftId}-2", $this->post($draftId)->post_name);
    }

    /**
     * 最悪の場合の長さがちょうど200のformatでも、公開が成功し、スラッグが200バイト以内になる(F1)。既存の投稿と
     * 重なった場合も、接尾辞の付いたスラッグで公開される。
     */
    public function testLongFormatAtTheLengthLimitPublishes(): void
    {
        $prefix = str_repeat('a', 179);
        $this->bootApp('SlugLong', new JaToolkit(new JapaneseSlug($prefix . '-{post_id}')));
        $draftId = self::factory()->post->create(['post_title' => '東京', 'post_status' => 'draft']);
        self::assertIsInt($draftId);
        $existingId = self::factory()->post->create(['post_name' => "{$prefix}-{$draftId}", 'post_status' => 'publish']);
        self::assertIsInt($existingId);
        $postId = $this->publishJapanesePost('post');

        self::assertSame('publish', $this->post($postId)->post_status);
        self::assertSame("{$prefix}-{$postId}", $this->post($postId)->post_name);

        wp_update_post(['ID' => $draftId, 'post_status' => 'publish']);
        $collided = $this->post($draftId);

        self::assertSame('publish', $collided->post_status);
        self::assertSame("{$prefix}-{$draftId}-2", $collided->post_name);
        self::assertLessThanOrEqual(200, \strlen($collided->post_name));
    }

    /**
     * 保存できない長さのformatと入れ子のformatは、JapaneseSlugを作る時点(Kernelのbootと投稿の操作より前)で例外に
     * なり、フィルタを登録しない。投稿の公開は、WordPressの既定のスラッグのまま成功する(F1・F3)。
     */
    public function testInvalidFormatDoesNotRewritePosts(): void
    {
        $formats = [
            'can produce a slug of 201 bytes' => str_repeat('a', 180) . '-{post_id}',
            "the part '{post_' outside" => '{post_{post_type}id}-{post_id}',
            "x{post_' outside {post_type} and {post_id}" => str_repeat('x', 59) . str_repeat('{post_{post_type}id}', 6) . '-{post_id}',
        ];

        foreach ($formats as $message => $format) {
            try {
                new JapaneseSlug($format);
                self::fail("format '{$format}' was accepted");
            } catch (LogicException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        $postId = $this->publishJapanesePost('post');

        self::assertSame('publish', $this->post($postId)->post_status);
        self::assertSame(self::ENCODED_SLUG, $this->post($postId)->post_name);
    }

    /**
     * 全角・半角のスペースだけの検索は、検索のまま0件になる(全件にならない。A6-L02)。全角スペースを含む検索語は、
     * 半角スペースで区切った検索語になる。
     */
    public function testWhitespaceOnlySearchFindsNothing(): void
    {
        $this->bootApp('Search', new JaToolkit(new FullwidthSearchNormalizer()));
        $matchId = self::factory()->post->create(['post_title' => '東京の展望', 'post_content' => 'タワーに登る', 'post_status' => 'publish']);
        self::factory()->post->create(['post_title' => '大阪の展望', 'post_content' => 'タワーに登る', 'post_status' => 'publish']);

        foreach (['　', '　　', ' 　 '] as $search) {
            $query = new WP_Query(['s' => $search, 'post_type' => 'post']);

            self::assertTrue($query->is_search(), $search);
            self::assertSame('', $query->get('s'), $search);
            self::assertSame([], $query->posts, $search);
        }

        $query = new WP_Query(['s' => '東京　タワー', 'post_type' => 'post', 'fields' => 'ids']);

        self::assertSame('東京 タワー', $query->get('s'));
        self::assertSame([$matchId], $query->posts);
    }

    /**
     * 一時ディレクトリのプラグインのKernel(debug)に、渡したJaToolkitを登録してinitより前にbootする。
     *
     * @return list<array{hook: string, priority: int, function: mixed, acceptedArgs: int}> bootがinitに登録したコールバック
     */
    private function bootApp(string $suffix, JaToolkit $jaToolkit): array
    {
        $root = $this->createTempDirectory('integration-ja-toolkit-' . strtolower($suffix));
        $this->writeFile($root . '/app/.gitkeep', '');
        $this->writeFile($root . '/plugin.php', "<?php\n");

        return $this->bootBeforeInit(KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'TmswpIntegrationJaToolkit' . $suffix . uniqid(), true, [$jaToolkit]));
    }

    /** 日本語のタイトルの下書きを作ってから公開する(公開の時点でIDの決まった投稿のスラッグが作られる)。 */
    private function publishJapanesePost(string $postType): int
    {
        $postId = self::factory()->post->create(['post_title' => '東京', 'post_status' => 'draft', 'post_type' => $postType]);
        self::assertIsInt($postId);
        wp_update_post(['ID' => $postId, 'post_status' => 'publish']);

        return $postId;
    }

    private function post(int $postId): WP_Post
    {
        $post = get_post($postId);
        self::assertInstanceOf(WP_Post::class, $post);

        return $post;
    }
}
