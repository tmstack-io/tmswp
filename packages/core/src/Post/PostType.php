<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Entity\EntityReference;
use TMSWP\Core\Entity\WpBridge;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\EntityPersistenceException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaBag;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_Term;

abstract class PostType
{
    /** @var array<class-string<self>, PostTypeDefinition> */
    private static array $definitions = [];

    abstract public static function definition(): PostTypeDefinition;

    final public static function slug(): string
    {
        return self::definitionFor(static::class)->slug();
    }

    final public static function register(): void
    {
        $definition = self::definitionFor(static::class);
        $slug = $definition->slug();

        if ($definition->isBuiltin()) {
            foreach ($definition->supportsList() as $feature) {
                add_post_type_support($slug, $feature);
            }

            self::registerBlockEditorPreference($definition);

            return;
        }

        if (post_type_exists($slug)) {
            throw new EntityDefinitionException("post type is already registered: {$slug}");
        }

        if (\in_array('thumbnail', $definition->supportsList(), true)) {
            self::mergeThumbnailSupport($slug);
        }

        $registered = WpBridge::call('register_post_type', $slug, $definition->toArgs());

        if ($registered instanceof WP_Error) {
            throw new EntityDefinitionException($registered->get_error_message());
        }

        self::registerBlockEditorPreference($definition);
    }

    final public static function get(WP_Post|int $post): ?WP_Post
    {
        if ($post instanceof WP_Post) {
            return $post->post_type === static::slug() ? $post : null;
        }

        if ($post <= 0) {
            return null;
        }

        $resolved = get_post($post);

        if (!$resolved instanceof WP_Post || $resolved->post_type !== static::slug()) {
            return null;
        }

        return $resolved;
    }

    final public static function findBySlug(string $slug): ?WP_Post
    {
        $post = get_page_by_path($slug, OBJECT, static::slug());

        if (!$post instanceof WP_Post) {
            return null;
        }

        return static::get($post);
    }

    final public static function is(WP_Post|int|null $post): bool
    {
        if ($post === null) {
            return false;
        }

        return static::get($post) instanceof WP_Post;
    }

    public static function query(): PostQuery
    {
        return PostQuery::of(static::class);
    }

    /** @return MetaBag<static> */
    final public static function meta(WP_Post|int $post): MetaBag
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            throw new EntityDefinitionException('cannot create MetaBag for missing or mismatched post');
        }

        $registry = Kernel::forClass(static::class)->container()->get(MetaSchemaRegistry::class);

        return $registry->bagFor(static::class, $resolved);
    }

    final public static function permalink(WP_Post|int $post): ?string
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            return null;
        }

        $url = get_permalink($resolved);

        return $url === false ? null : $url;
    }

    final public static function archiveUrl(): ?string
    {
        $url = get_post_type_archive_link(static::slug());

        return $url === false ? null : $url;
    }

    final public static function title(WP_Post|int $post): string
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            return '';
        }

        return get_the_title($resolved);
    }

    /** @param string|array<int> $size */
    final public static function thumbnail(WP_Post|int $post, string|array $size = 'full'): ?Thumbnail
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            return null;
        }

        if (has_post_thumbnail($resolved)) {
            $thumbnailId = get_post_thumbnail_id($resolved);

            if (!\is_int($thumbnailId)) {
                $thumbnailId = 0;
            }

            $src = wp_get_attachment_image_src($thumbnailId, $size);

            if (\is_array($src) && \array_key_exists(0, $src) && \is_string($src[0])) {
                $altValue = get_post_meta($thumbnailId, '_wp_attachment_image_alt', true);
                $alt = trim(wp_strip_all_tags(\is_scalar($altValue) ? (string) $altValue : '', true));

                return new Thumbnail($src[0], \is_int($src[1]) ? $src[1] : null, \is_int($src[2]) ? $src[2] : null, $alt);
            }
        }

        $fallback = apply_filters('tmswp/post/thumbnail_fallback', null, $resolved, $size);

        return $fallback instanceof Thumbnail ? $fallback : null;
    }

    /** @param string|array<int> $size */
    final public static function thumbnailUrl(WP_Post|int $post, string|array $size = 'full'): ?string
    {
        $thumbnail = static::thumbnail($post, $size);

        return $thumbnail instanceof Thumbnail ? $thumbnail->url : null;
    }

    /** @return list<WP_Term> */
    final public static function terms(WP_Post|int $post, string $taxonomy): array
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            return [];
        }

        $terms = get_the_terms($resolved, EntityReference::taxonomySlug($taxonomy));

        if (!\is_array($terms)) {
            return [];
        }

        return array_values($terms);
    }

    final public static function firstTerm(WP_Post|int $post, string $taxonomy): ?WP_Term
    {
        $terms = static::terms($post, $taxonomy);

        if ($terms === []) {
            return null;
        }

        return $terms[0];
    }

    /** @return list<WP_Post> */
    final public static function parents(WP_Post|int $post, bool $includeSelf = false): array
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            return [];
        }

        $parents = $includeSelf ? [$resolved] : [];
        $current = $resolved;

        for ($depth = 0; $depth < 100; ++$depth) {
            if ((int) $current->post_parent <= 0) {
                return array_reverse($parents);
            }

            $parent = static::get((int) $current->post_parent);

            if (!$parent instanceof WP_Post) {
                return array_reverse($parents);
            }

            $parents[] = $parent;
            $current = $parent;
        }

        throw new EntityDefinitionException('post parent depth exceeded 100');
    }

    /** @param array<string, mixed> $postarr */
    final public static function create(array $postarr): int
    {
        $postarr['post_type'] = static::slug();
        unset($postarr['ID']);

        $id = WpBridge::call('wp_insert_post', $postarr, true);

        if ($id instanceof WP_Error) {
            throw EntityPersistenceException::fromWpError($id);
        }

        if (!\is_int($id)) {
            throw new EntityPersistenceException('wp_insert_post did not return an integer ID');
        }

        return $id;
    }

    final public static function postTypeObject(): ?WP_Post_Type
    {
        $object = get_post_type_object(static::slug());

        return $object instanceof WP_Post_Type ? $object : null;
    }

    final public static function resetRuntimeCache(): void
    {
        unset(self::$definitions[static::class]);
    }

    /**
     * @param class-string<self> $class
     */
    private static function definitionFor(string $class): PostTypeDefinition
    {
        if (!\array_key_exists($class, self::$definitions)) {
            self::$definitions[$class] = $class::definition();
        }

        return self::$definitions[$class];
    }

    private static function mergeThumbnailSupport(string $slug): void
    {
        $support = get_theme_support('post-thumbnails');

        if ($support === true) {
            return;
        }

        if (\is_array($support) && \array_key_exists(0, $support) && \is_array($support[0])) {
            $types = [];

            foreach ($support[0] as $postType) {
                if (\is_string($postType)) {
                    $types[] = $postType;
                }
            }

            add_theme_support('post-thumbnails', array_values(array_unique([...$types, $slug])));

            return;
        }

        add_theme_support('post-thumbnails', [$slug]);
    }

    private static function registerBlockEditorPreference(PostTypeDefinition $definition): void
    {
        $preference = $definition->blockEditorPreference();

        if ($preference === null) {
            return;
        }

        add_filter(
            'use_block_editor_for_post_type',
            static fn(bool $useBlockEditor, string $postType): bool => $postType === $definition->slug() ? $preference : $useBlockEditor,
            10,
            2,
        );
    }
}
