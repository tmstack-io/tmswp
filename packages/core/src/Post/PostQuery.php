<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Entity\EntityReference;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Query\PageResult;
use TMSWP\Core\Query\QueryValidator;
use WP_Post;
use WP_Query;
use WP_User;

final class PostQuery
{
    /** @var array<string, mixed> */
    private array $args = [];

    /** @var list<array<string, mixed>> */
    private array $metaClauses = [];

    /** @var array<string, array<string, mixed>> */
    private array $namedMetaClauses = [];

    /** @var list<array<string, mixed>> */
    private array $taxClauses = [];

    /** @var array<string, string> */
    private array $orderBy = [];

    /** @var array<string, mixed> */
    private array $rawArgs = [];

    private function __construct(
        private readonly string $postType,
    ) {}

    /** @param class-string<PostType> $postTypeClass */
    public static function of(string $postTypeClass): self
    {
        return new self($postTypeClass::slug());
    }

    public static function ofType(string $postTypeSlug): self
    {
        return new self($postTypeSlug);
    }

    public function status(string ...$status): self
    {
        $this->args['post_status'] = $status;

        return $this;
    }

    public function anyStatus(): self
    {
        $this->args['post_status'] = 'any';

        return $this;
    }

    public function search(string $keyword): self
    {
        $this->args['s'] = $keyword;

        return $this;
    }

    public function author(int|WP_User $author): self
    {
        $this->args['author'] = $author instanceof WP_User ? (int) $author->ID : $author;

        return $this;
    }

    public function parent(int|WP_Post $parent): self
    {
        $this->args['post_parent'] = $parent instanceof WP_Post ? (int) $parent->ID : $parent;

        return $this;
    }

    public function slugIn(string ...$slugs): self
    {
        $this->args['post_name__in'] = $slugs;

        return $this;
    }

    public function idIn(int ...$ids): self
    {
        $this->args['post__in'] = $ids;

        return $this;
    }

    public function idNotIn(int ...$ids): self
    {
        $this->args['post__not_in'] = $ids;

        return $this;
    }

    public function whereMeta(string $key, mixed $value, string $compare = '=', string $type = 'CHAR'): self
    {
        $this->metaClauses[] = ['key' => $key, 'value' => $value, 'compare' => QueryValidator::compare($compare), 'type' => QueryValidator::metaType($type)];

        return $this;
    }

    public function whereMetaExists(string $key): self
    {
        $this->metaClauses[] = ['key' => $key, 'compare' => 'EXISTS'];

        return $this;
    }

    public function whereMetaNotExists(string $key): self
    {
        $this->metaClauses[] = ['key' => $key, 'compare' => 'NOT EXISTS'];

        return $this;
    }

    /** @param int|string|list<int|string> $terms */
    public function whereTerm(string $taxonomy, int|string|array $terms, string $field = 'slug', string $operator = 'IN'): self
    {
        $termList = \is_array($terms) ? array_values($terms) : [$terms];
        $this->taxClauses[] = [
            'taxonomy' => EntityReference::taxonomySlug($taxonomy),
            'field' => $field,
            'terms' => $termList,
            'operator' => $operator,
        ];

        return $this;
    }

    public function template(string $templateFile): self
    {
        return $this->whereMeta('_wp_page_template', $templateFile);
    }

    public function hasThumbnail(bool $has = true): self
    {
        $this->metaClauses[] = ['key' => '_thumbnail_id', 'compare' => $has ? 'EXISTS' : 'NOT EXISTS'];

        return $this;
    }

    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $this->orderBy[$field] = QueryValidator::direction($direction);

        return $this;
    }

    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, 'DESC');
    }

    public function orderByMeta(string $metaKey, string $direction = 'ASC', bool $numeric = false): self
    {
        $name = 'tms_order_' . \count($this->namedMetaClauses);
        $this->namedMetaClauses[$name] = ['key' => $metaKey, 'compare' => 'EXISTS', 'type' => $numeric ? 'NUMERIC' : 'CHAR'];
        $this->orderBy[$name] = QueryValidator::direction($direction);

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit <= 0 && $limit !== -1) {
            throw new QueryException('post query limit must be -1 or greater than 0');
        }

        $this->args['posts_per_page'] = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new QueryException('post query offset must be 0 or greater');
        }

        $this->args['offset'] = $offset;

        return $this;
    }

    public function noPaging(): self
    {
        $this->args['nopaging'] = true;

        return $this;
    }

    public function ignoreSticky(bool $ignore = true): self
    {
        $this->args['ignore_sticky_posts'] = $ignore;

        return $this;
    }

    /**
     * 専用のメソッドが無い WP_Query の引数を設定する。キーごとに上書きする。post_type は上書きできない。
     *
     * @param array<string, mixed> $args WP_Query::__construct() の $query(stubs の型は string|array)
     */
    public function withArgs(array $args): self
    {
        $this->rawArgs = array_merge($this->rawArgs, $args);

        return $this;
    }

    /** @return list<WP_Post> */
    public function get(): array
    {
        $args = $this->toArgs();
        $args['no_found_rows'] = true;
        unset($args['fields']);
        $query = new WP_Query($args);

        return array_values(array_filter($query->posts, static fn(mixed $post): bool => $post instanceof WP_Post));
    }

    public function first(): ?WP_Post
    {
        $args = $this->toArgs();
        $args['posts_per_page'] = 1;
        $args['no_found_rows'] = true;
        unset($args['fields']);
        $query = new WP_Query($args);
        $post = \array_key_exists(0, $query->posts) ? $query->posts[0] : null;

        return $post instanceof WP_Post ? $post : null;
    }

    /** @return list<int> */
    public function ids(): array
    {
        $args = $this->toArgs();
        $args['fields'] = 'ids';
        $args['no_found_rows'] = true;
        $query = new WP_Query($args);
        $ids = [];

        foreach ($query->posts as $id) {
            if (\is_int($id)) {
                $ids[] = $id;
                continue;
            }

            if (\is_string($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    public function count(): int
    {
        $args = $this->toArgs();
        $args['fields'] = 'ids';
        $args['posts_per_page'] = 1;
        $args['paged'] = 1;
        $args['no_found_rows'] = false;
        $query = new WP_Query($args);

        return (int) $query->found_posts;
    }

    public function exists(): bool
    {
        return $this->first() instanceof WP_Post;
    }

    public function url(): ?string
    {
        $post = $this->first();

        if (!$post instanceof WP_Post) {
            return null;
        }

        $url = get_permalink($post);

        return $url === false ? null : $url;
    }

    /** @return PageResult<WP_Post> */
    public function paginate(int $perPage, ?int $page = null): PageResult
    {
        if ($perPage < 1) {
            throw new QueryException('post query perPage must be greater than 0');
        }

        $currentPage = $page === null ? self::currentPage() : $page;
        $args = $this->toArgs();
        $args['posts_per_page'] = $perPage;
        $args['paged'] = $currentPage;
        $args['no_found_rows'] = false;
        unset($args['fields']);
        $query = new WP_Query($args);
        $posts = array_values(array_filter($query->posts, static fn(mixed $post): bool => $post instanceof WP_Post));

        return new PageResult($posts, (int) $query->found_posts, $perPage, $currentPage);
    }

    public function wpQuery(): WP_Query
    {
        return new WP_Query($this->toArgs());
    }

    /** @return array<string, mixed> WP_Query::__construct() の $query(stubs の型は string|array で、配列の形を持たない) */
    public function toArgs(): array
    {
        $args = ['post_status' => ['publish'], 'ignore_sticky_posts' => true];
        $args = array_merge($args, $this->args);

        $metaQuery = [...$this->metaClauses, ...$this->namedMetaClauses];

        if ($metaQuery !== []) {
            $args['meta_query'] = ['relation' => 'AND', ...$metaQuery];
        }

        if ($this->taxClauses !== []) {
            $args['tax_query'] = ['relation' => 'AND', ...$this->taxClauses];
        }

        if ($this->orderBy !== []) {
            $args['orderby'] = $this->orderBy;
            unset($args['order']);
        }

        $args = array_merge($args, $this->rawArgs);
        $args['post_type'] = $this->postType;

        return $args;
    }

    private static function currentPage(): int
    {
        $pagedValue = get_query_var('paged');
        $paged = \is_scalar($pagedValue) ? (int) $pagedValue : 0;

        if ($paged > 0) {
            return $paged;
        }

        $pageValue = get_query_var('page');
        $page = \is_scalar($pageValue) ? (int) $pageValue : 0;

        if ($page > 0) {
            return $page;
        }

        return 1;
    }
}
