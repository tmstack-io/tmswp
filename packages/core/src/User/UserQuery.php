<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Query\PageResult;
use TMSWP\Core\Query\QueryValidator;
use WP_User;
use WP_User_Query;

/**
 * WpUserQueryArgs は php-stubs/wordpress-stubs の WP_User_Query::__construct() の $query(`@phpstan-param`)と同じ配列の形。
 * stubs が要素の型を書いていない array(meta_query・orderby の配列)だけは要素の型を書く
 * (PHPStan level max は要素の型の無い array を許さないため)。
 *
 * @phpstan-type WpUserQueryArgs array{
 *   blog_id?: int,
 *   role?: string|string[],
 *   role__in?: string[],
 *   role__not_in?: string[],
 *   meta_key?: string|string[],
 *   meta_value?: string|string[],
 *   meta_compare?: string,
 *   meta_compare_key?: string,
 *   meta_type?: string,
 *   meta_type_key?: string,
 *   meta_query?: array<int|string, string|array<string, mixed>>,
 *   capability?: string|string[],
 *   capability__in?: string[],
 *   capability__not_in?: string[],
 *   include?: int[],
 *   exclude?: int[],
 *   search?: string,
 *   search_columns?: string[],
 *   orderby?: string|array<string, string>,
 *   order?: string,
 *   offset?: int,
 *   number?: int,
 *   paged?: int,
 *   count_total?: bool,
 *   fields?: string|string[],
 *   who?: string,
 *   has_published_posts?: bool|string[],
 *   nicename?: string,
 *   nicename__in?: string[],
 *   nicename__not_in?: string[],
 *   login?: string,
 *   login__in?: string[],
 *   login__not_in?: string[],
 *   cache_results?: bool,
 * }
 */
final class UserQuery
{
    /** @var WpUserQueryArgs */
    private array $args = [];

    /** @var list<array<string, mixed>> */
    private array $metaClauses = [];

    /** @var array<string, array<string, mixed>> */
    private array $namedMetaClauses = [];

    /** @var array<string, string> */
    private array $orderBy = [];

    /** @var WpUserQueryArgs */
    private array $rawArgs = [];

    private function __construct(
        private readonly string $role,
    ) {}

    /** @param class-string<UserRole> $userRoleClass */
    public static function of(string $userRoleClass): self
    {
        return new self($userRoleClass::role());
    }

    public static function ofRole(string $role): self
    {
        return new self($role);
    }

    public function search(string $keyword, string ...$columns): self
    {
        $this->args['search'] = str_contains($keyword, '*') ? $keyword : '*' . $keyword . '*';

        if ($columns !== []) {
            $this->args['search_columns'] = $columns;
        }

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
            throw new QueryException('user query limit must be -1 or greater than 0');
        }

        $this->args['number'] = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new QueryException('user query offset must be 0 or greater');
        }

        $this->args['offset'] = $offset;

        return $this;
    }

    /**
     * 専用のメソッドが無い WP_User_Query の引数を設定する。キーごとに上書きする。role は上書きできない。
     *
     * @param WpUserQueryArgs $args
     */
    public function withArgs(array $args): self
    {
        $this->rawArgs = array_merge($this->rawArgs, $args);

        return $this;
    }

    /** @return list<WP_User> */
    public function get(): array
    {
        $query = new WP_User_Query($this->toArgs());

        return array_values(array_filter($query->get_results(), static fn(mixed $user): bool => $user instanceof WP_User));
    }

    public function first(): ?WP_User
    {
        $args = $this->toArgs();
        $args['number'] = 1;
        $query = new WP_User_Query($args);
        $users = $query->get_results();
        $user = \array_key_exists(0, $users) ? $users[0] : null;

        return $user instanceof WP_User ? $user : null;
    }

    /** @return list<int> */
    public function ids(): array
    {
        $args = $this->toArgs();
        $args['fields'] = 'ID';
        $query = new WP_User_Query($args);
        $ids = [];

        foreach ($query->get_results() as $id) {
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
        $args['fields'] = 'ID';
        $args['number'] = 1;
        $args['count_total'] = true;
        $query = new WP_User_Query($args);

        return (int) $query->get_total();
    }

    public function exists(): bool
    {
        return $this->first() instanceof WP_User;
    }

    public function url(): ?string
    {
        $user = $this->first();

        if (!$user instanceof WP_User) {
            return null;
        }

        $url = get_author_posts_url((int) $user->ID);

        return $url === '' ? null : $url;
    }

    /** @return PageResult<WP_User> */
    public function paginate(int $perPage, ?int $page = null): PageResult
    {
        if ($perPage < 1) {
            throw new QueryException('user query perPage must be greater than 0');
        }

        $currentPage = $page === null ? self::currentPage() : $page;
        $args = $this->toArgs();
        $args['number'] = $perPage;
        $args['offset'] = ($currentPage - 1) * $perPage;
        $args['count_total'] = true;
        $query = new WP_User_Query($args);
        $users = array_values(array_filter($query->get_results(), static fn(mixed $user): bool => $user instanceof WP_User));

        return new PageResult($users, (int) $query->get_total(), $perPage, $currentPage);
    }

    public function wpUserQuery(): WP_User_Query
    {
        return new WP_User_Query($this->toArgs());
    }

    /** @return WpUserQueryArgs */
    public function toArgs(): array
    {
        $args = ['fields' => 'all', 'count_total' => false];
        $args = array_merge($args, $this->args);
        $metaQuery = [...$this->metaClauses, ...$this->namedMetaClauses];

        if ($metaQuery !== []) {
            $args['meta_query'] = ['relation' => 'AND', ...$metaQuery];
        }

        if ($this->orderBy !== []) {
            $args['orderby'] = $this->orderBy;
        }

        $args = array_merge($args, $this->rawArgs);
        $args['role'] = $this->role;

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
