<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Entity\WpBridge;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Query\QueryValidator;
use WP_Error;
use WP_Post;
use WP_Term;
use WP_Term_Query;

final class TermQuery
{
    /** @var array<string, mixed> */
    private array $args = [];

    /** @var list<array<string, mixed>> */
    private array $metaClauses = [];

    /** @var array<string, array<string, mixed>> */
    private array $namedMetaClauses = [];

    /** @var array<string, string> */
    private array $orderBy = [];

    /** @var array<string, mixed> */
    private array $rawArgs = [];

    private function __construct(
        private readonly string $taxonomy,
    ) {}

    /** @param class-string<Taxonomy> $taxonomyClass */
    public static function of(string $taxonomyClass): self
    {
        return new self($taxonomyClass::slug());
    }

    public static function ofTaxonomy(string $taxonomySlug): self
    {
        return new self($taxonomySlug);
    }

    public function hideEmpty(bool $hide = true): self
    {
        $this->args['hide_empty'] = $hide;

        return $this;
    }

    public function search(string $keyword): self
    {
        $this->args['search'] = $keyword;

        return $this;
    }

    public function parent(int|WP_Term $parent): self
    {
        $this->args['parent'] = $parent instanceof WP_Term ? (int) $parent->term_id : $parent;

        return $this;
    }

    public function childrenOf(int|WP_Term $ancestor): self
    {
        $this->args['child_of'] = $ancestor instanceof WP_Term ? (int) $ancestor->term_id : $ancestor;

        return $this;
    }

    public function slugIn(string ...$slugs): self
    {
        $this->args['slug'] = $slugs;

        return $this;
    }

    public function idIn(int ...$ids): self
    {
        $this->args['include'] = $ids;

        return $this;
    }

    public function idNotIn(int ...$ids): self
    {
        $this->args['exclude'] = $ids;

        return $this;
    }

    public function forPost(WP_Post|int ...$posts): self
    {
        $this->args['object_ids'] = array_map(static fn(WP_Post|int $post): int => $post instanceof WP_Post ? (int) $post->ID : $post, $posts);

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

    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        if ($this->orderBy !== []) {
            throw new QueryException('TermQuery supports only one orderBy clause');
        }

        $this->orderBy[$field] = QueryValidator::direction($direction);

        return $this;
    }

    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, 'DESC');
    }

    public function orderByMeta(string $metaKey, string $direction = 'ASC', bool $numeric = false): self
    {
        if ($this->orderBy !== []) {
            throw new QueryException('TermQuery supports only one orderBy clause');
        }

        $name = 'tms_order_' . \count($this->namedMetaClauses);
        $this->namedMetaClauses[$name] = ['key' => $metaKey, 'compare' => 'EXISTS', 'type' => $numeric ? 'NUMERIC' : 'CHAR'];
        $this->orderBy[$name] = QueryValidator::direction($direction);

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new QueryException('term query limit must be 0 or greater');
        }

        $this->args['number'] = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new QueryException('term query offset must be 0 or greater');
        }

        $this->args['offset'] = $offset;

        return $this;
    }

    public function withArg(string $key, mixed $value): self
    {
        $this->rawArgs[$key] = $value;

        return $this;
    }

    /** @return list<WP_Term> */
    public function get(): array
    {
        $terms = self::getTerms($this->toArgs());

        if ($terms instanceof WP_Error || !\is_array($terms)) {
            return [];
        }

        return array_values(array_filter($terms, static fn(mixed $term): bool => $term instanceof WP_Term));
    }

    public function first(): ?WP_Term
    {
        $args = $this->toArgs();
        $args['number'] = 1;
        $terms = self::getTerms($args);

        if ($terms instanceof WP_Error || !\is_array($terms) || $terms === []) {
            return null;
        }

        return $terms[0] instanceof WP_Term ? $terms[0] : null;
    }

    /** @return list<int> */
    public function ids(): array
    {
        $args = $this->toArgs();
        $args['fields'] = 'ids';
        $ids = self::getTerms($args);

        if ($ids instanceof WP_Error || !\is_array($ids)) {
            return [];
        }

        $typedIds = [];

        foreach ($ids as $id) {
            if (\is_int($id)) {
                $typedIds[] = $id;
                continue;
            }

            if (\is_string($id)) {
                $typedIds[] = (int) $id;
            }
        }

        return $typedIds;
    }

    public function count(): int
    {
        $args = $this->toArgs();
        $args['fields'] = 'count';
        $count = self::getTerms($args);

        if ($count instanceof WP_Error) {
            return 0;
        }

        return is_numeric($count) ? (int) $count : 0;
    }

    public function exists(): bool
    {
        return $this->first() instanceof WP_Term;
    }

    public function url(): ?string
    {
        $term = $this->first();

        if (!$term instanceof WP_Term) {
            return null;
        }

        $url = get_term_link($term);

        return $url instanceof WP_Error ? null : $url;
    }

    public function wpTermQuery(): WP_Term_Query
    {
        $query = WpBridge::instantiate(WP_Term_Query::class, $this->toArgs());

        if (!$query instanceof WP_Term_Query) {
            throw new QueryException('failed to create WP_Term_Query');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function toArgs(): array
    {
        $args = ['hide_empty' => false];
        $args = array_merge($args, $this->args);
        $metaQuery = [...$this->metaClauses, ...$this->namedMetaClauses];

        if ($metaQuery !== []) {
            $args['meta_query'] = ['relation' => 'AND', ...$metaQuery];
        }

        if ($this->orderBy !== []) {
            $field = array_key_first($this->orderBy);
            $args['orderby'] = $field;
            $args['order'] = $this->orderBy[$field];
        }

        $args = array_merge($args, $this->rawArgs);
        $args['taxonomy'] = $this->taxonomy;

        return $args;
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function getTerms(array $args): mixed
    {
        return WpBridge::call('get_terms', $args);
    }
}
