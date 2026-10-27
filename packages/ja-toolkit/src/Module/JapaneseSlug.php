<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Module;

use TMSWP\Core\Exception\LogicException;
use TMSWP\JaToolkit\ModuleInterface;

/**
 * 日本語のタイトルから作られたURLエンコードのスラッグを、`$format`(既定は`{post_type}-{post_id}`)のスラッグに
 * 置き換える。対象は許可リストの投稿タイプだけで、置き換えたスラッグはWordPressの一意性の確認にもう一度通す。
 */
final class JapaneseSlug implements ModuleInterface
{
    /** スラッグの形式で、プレースホルダの外に書ける文字(投稿タイプのキーと同じ文字種) */
    private const FORMAT_LITERAL_PATTERN = '/\A[a-z0-9_-]*\z/';

    /** スラッグの形式を、プレースホルダと固定部分に分ける区切り(プレースホルダを捕捉する) */
    private const FORMAT_PLACEHOLDER_PATTERN = '/(\{post_type\}|\{post_id\})/';

    /** 投稿タイプのキー(register_post_type()の制約: 英小文字・数字・_・-の1〜20文字) */
    private const POST_TYPE_PATTERN = '/\A[a-z0-9_-]{1,20}\z/';

    /** スラッグの最大のバイト数(wp_postsのpost_nameの列がvarchar(200)のため) */
    private const SLUG_MAX_LENGTH = 200;

    /** `{post_type}`を置き換える値の最大の長さ(投稿タイプのキーの上限の20文字) */
    private const POST_TYPE_MAX_LENGTH = 20;

    /** `{post_id}`を置き換える値の最大の桁数(wp_postsのIDの列のBIGINT UNSIGNEDの最大値18446744073709551615の20桁) */
    private const POST_ID_MAX_DIGITS = 20;

    /**
     * WordPressの関数は呼ばない(tmswp-kernel.phpの中で、WordPressを起動しない経路からも生成されるため)。
     *
     * @param string $format 置き換えたスラッグの形式。`{post_id}`を必ず含み、ほかは`{post_type}`と英小文字・数字・_・-
     * @param list<string>|null $postTypes 対象の投稿タイプのキー。nullなら、変換の時点で公開(public)の投稿タイプのうち
     *                                     attachment以外
     *
     * @throws LogicException `$format`が`{post_id}`を含まない・使えない文字を含む・置き換えた後に200バイトを超えうる
     *                        場合、`$postTypes`が空・投稿タイプのキーの形でない場合
     */
    public function __construct(
        private readonly string $format = '{post_type}-{post_id}',
        private readonly ?array $postTypes = null,
    ) {
        $maxLength = self::worstLength($format);

        if ($maxLength > self::SLUG_MAX_LENGTH) {
            throw new LogicException("JapaneseSlug format '{$format}' can produce a slug of {$maxLength} bytes; the slug must fit in " . self::SLUG_MAX_LENGTH . ' bytes (post_name is varchar(200)), counting {post_type} as ' . self::POST_TYPE_MAX_LENGTH . ' and {post_id} as ' . self::POST_ID_MAX_DIGITS . ' characters');
        }

        if ($postTypes === []) {
            throw new LogicException('JapaneseSlug post types must not be empty; pass null to target the public post types');
        }

        foreach ($postTypes ?? [] as $postType) {
            if (preg_match(self::POST_TYPE_PATTERN, $postType) !== 1) {
                throw new LogicException("invalid post type key for JapaneseSlug: '{$postType}'");
            }
        }
    }

    /**
     * スラッグの形式を1回だけ走査してプレースホルダと固定部分に分け、検査したうえで、置き換えた後の最悪の場合の長さを
     * 返す。プレースホルダを順に消してから検査すると、`{post_{post_type}id}`のような入れ子が、消した後にできる新しい
     * プレースホルダごと消えて検査を通るため、消さずに分ける。
     *
     * 最悪の場合の長さは、固定部分の文字数と、`{post_type}`の出現数×20と、`{post_id}`の出現数×20の合計である。
     *
     * @throws LogicException `{post_id}`を含まない場合、固定部分が英小文字・数字・_・-以外(入れ子・閉じていない波括弧・
     *                        知らないプレースホルダの波括弧等)を含む場合
     */
    private static function worstLength(string $format): int
    {
        $parts = preg_split(self::FORMAT_PLACEHOLDER_PATTERN, $format, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            throw new LogicException("failed to parse the JapaneseSlug format '{$format}': " . preg_last_error_msg());
        }

        $length = 0;
        $hasPostId = false;

        foreach ($parts as $part) {
            if ($part === '{post_type}') {
                $length += self::POST_TYPE_MAX_LENGTH;
            } elseif ($part === '{post_id}') {
                $length += self::POST_ID_MAX_DIGITS;
                $hasPostId = true;
            } elseif (preg_match(self::FORMAT_LITERAL_PATTERN, $part) === 1) {
                $length += \strlen($part);
            } else {
                throw new LogicException("invalid JapaneseSlug format '{$format}': the part '{$part}' outside {post_type} and {post_id} may contain only a-z, 0-9, '_' and '-'");
            }
        }

        if (!$hasPostId) {
            throw new LogicException("invalid JapaneseSlug format '{$format}': it must contain {post_id}");
        }

        return $length;
    }

    public function register(): void
    {
        add_filter('wp_unique_post_slug', [$this, 'filterSlug'], 10, 5);
    }

    /**
     * `wp_unique_post_slug`のフィルタ。対象の投稿タイプで、IDが決まっていて、スラッグがURLエンコードした文字を含む
     * ときだけ置き換える。
     *
     * フィルタの戻り値はWordPressの一意性の確認の後の値として使われるため、置き換えたスラッグを
     * wp_unique_post_slug()にもう一度通し、重複していれば`-2`等の接尾辞を付ける。置き換えたスラッグはURLエンコードした
     * 文字を含まないため、その中で呼ばれるこのフィルタは値を変えない。
     *
     * 置き換えたスラッグはコンストラクタの検査で200バイト以内に収まる。超えた場合は、末尾を切り詰めず(必須の
     * `{post_id}`を失うため)例外にする。
     *
     * @throws LogicException 置き換えたスラッグが200バイトを超えた場合(本来起きない)
     */
    public function filterSlug(string $slug, int $postId, string $postStatus, string $postType, int $postParent): string
    {
        if ($postId === 0 || !$this->targets($postType) || preg_match('/(%[0-9a-f]{2})+/i', $slug) !== 1) {
            return $slug;
        }

        // strtr()は置き換えた結果を再び走査しない
        $converted = strtr($this->format, ['{post_type}' => $postType, '{post_id}' => (string) $postId]);

        if (\strlen($converted) > self::SLUG_MAX_LENGTH) {
            throw new LogicException('JapaneseSlug produced a slug longer than ' . self::SLUG_MAX_LENGTH . " bytes for the post {$postId} of the post type '{$postType}'");
        }

        return wp_unique_post_slug($converted, $postId, $postStatus, $postType, $postParent);
    }

    private function targets(string $postType): bool
    {
        if ($this->postTypes !== null) {
            return \in_array($postType, $this->postTypes, true);
        }

        $object = get_post_type_object($postType);

        return $postType !== 'attachment' && $object !== null && $object->public;
    }
}
