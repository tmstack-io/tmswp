<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Tests\Support\IntegrationTestCase;
use WP_Post_Type;
use WP_Role;
use WP_Taxonomy;

final class EntityRegistrationIntegrationTest extends IntegrationTestCase
{
    public function testPostTypeIsRegisteredOnInit(): void
    {
        self::assertTrue(post_type_exists('tmswp_product'));
        $postType = get_post_type_object('tmswp_product');
        self::assertInstanceOf(WP_Post_Type::class, $postType);
        self::assertTrue($postType->has_archive);
        self::assertTrue($postType->show_in_rest);
        self::assertTrue(post_type_supports('tmswp_product', 'thumbnail'));
    }

    public function testTaxonomyIsRegisteredForPostTypeOnInit(): void
    {
        self::assertTrue(taxonomy_exists('tmswp_product_cat'));
        $taxonomy = get_taxonomy('tmswp_product_cat');
        self::assertInstanceOf(WP_Taxonomy::class, $taxonomy);
        self::assertTrue($taxonomy->hierarchical);
        self::assertTrue($taxonomy->show_in_rest);
        self::assertContains('tmswp_product', $taxonomy->object_type);
    }

    public function testUserRoleIsRegisteredOnInit(): void
    {
        $role = get_role('tmswp_member');
        self::assertInstanceOf(WP_Role::class, $role);
        self::assertTrue($role->has_cap('read'));
        self::assertTrue($role->has_cap('tmswp_member'));
    }
}
