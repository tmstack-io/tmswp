<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Post\PostQuery;
use TMSWP\Core\Query\PageResult;
use TMSWP\Core\Tax\TermQuery;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\User\UserQuery;

final class EntityQueryTest extends UnitTestCase
{
    public function testPostQueryBuildsScopedArgs(): void
    {
        $args = PostQuery::ofType('product')
            ->status('draft')
            ->whereMeta('price', 100, '>=', 'NUMERIC')
            ->whereTerm('product_category', ['news'])
            ->orderByMeta('price', 'DESC', true)
            ->limit(5)
            ->withArg('post_type', 'ignored')
            ->toArgs();

        $metaQuery = $args['meta_query'];
        $taxQuery = $args['tax_query'];
        self::assertIsArray($metaQuery);
        self::assertIsArray($taxQuery);

        $metaClause = $metaQuery[0];
        $taxClause = $taxQuery[0];
        self::assertIsArray($metaClause);
        self::assertIsArray($taxClause);

        self::assertSame('product', $args['post_type']);
        self::assertSame(['draft'], $args['post_status']);
        self::assertSame(5, $args['posts_per_page']);
        self::assertSame('price', $metaClause['key']);
        self::assertSame('>=', $metaClause['compare']);
        self::assertSame('NUMERIC', $metaClause['type']);
        self::assertSame('product_category', $taxClause['taxonomy']);
        self::assertSame(['news'], $taxClause['terms']);
        self::assertSame(['tms_order_0' => 'DESC'], $args['orderby']);
    }

    public function testTermQueryRejectsMultipleOrderByClauses(): void
    {
        $this->expectException(QueryException::class);

        TermQuery::ofTaxonomy('product_category')
            ->orderBy('name')
            ->orderByDesc('slug');
    }

    public function testTermQueryBuildsScopedArgs(): void
    {
        $args = TermQuery::ofTaxonomy('product_category')
            ->hideEmpty()
            ->whereMetaExists('featured')
            ->orderBy('name', 'desc')
            ->withArg('taxonomy', 'ignored')
            ->toArgs();

        $metaQuery = $args['meta_query'];
        self::assertIsArray($metaQuery);

        $metaClause = $metaQuery[0];
        self::assertIsArray($metaClause);

        self::assertSame('product_category', $args['taxonomy']);
        self::assertTrue($args['hide_empty']);
        self::assertSame('featured', $metaClause['key']);
        self::assertSame('name', $args['orderby']);
        self::assertSame('DESC', $args['order']);
    }

    public function testUserQueryBuildsScopedArgs(): void
    {
        $args = UserQuery::ofRole('member')
            ->search('tanaka', 'user_login')
            ->whereMetaNotExists('disabled_at')
            ->orderBy('registered', 'DESC')
            ->limit(10)
            ->withArg('role', 'ignored')
            ->toArgs();

        $metaQuery = $args['meta_query'];
        self::assertIsArray($metaQuery);

        $metaClause = $metaQuery[0];
        self::assertIsArray($metaClause);

        self::assertSame('member', $args['role']);
        self::assertSame('*tanaka*', $args['search']);
        self::assertSame(['user_login'], $args['search_columns']);
        self::assertSame('disabled_at', $metaClause['key']);
        self::assertSame(['registered' => 'DESC'], $args['orderby']);
        self::assertSame(10, $args['number']);
    }

    public function testPageResultCalculatesPaginationState(): void
    {
        $page = new PageResult(['a', 'b'], 25, 10, 2);

        self::assertSame(3, $page->totalPages());
        self::assertTrue($page->hasPrev());
        self::assertTrue($page->hasNext());
        self::assertFalse($page->isEmpty());
        self::assertCount(2, $page);
    }
}
