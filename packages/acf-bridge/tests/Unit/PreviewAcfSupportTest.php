<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use TMSWP\AcfBridge\PreviewAcfSupport;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class PreviewAcfSupportTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public function testRegisterHooksWpInsertPost(): void
    {
        Actions\expectAdded('wp_insert_post')->once();

        PreviewAcfSupport::register();
    }

    public function testItUpdatesRevisionValuesAfterNonceAndCapabilityChecks(): void
    {
        $_POST['acf'] = ['field_price' => '1200'];

        Functions\expect('wp_is_post_revision')
            ->once()
            ->with(200)
            ->andReturn(100);
        Functions\expect('acf_verify_nonce')
            ->once()
            ->with('post')
            ->andReturn(true);
        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 100)
            ->andReturn(true);
        Functions\expect('wp_unslash')
            ->once()
            ->with(['field_price' => '1200'])
            ->andReturn(['field_price' => '1200']);
        Functions\expect('acf_update_values')
            ->once()
            ->with(['field_price' => '1200'], 200);

        (new PreviewAcfSupport())->saveRevisionFields(200);
    }

    public function testItReturnsBeforeNonceForNonRevisionPosts(): void
    {
        $_POST['acf'] = ['field_price' => '1200'];

        Functions\expect('wp_is_post_revision')
            ->once()
            ->with(200)
            ->andReturn(false);

        (new PreviewAcfSupport())->saveRevisionFields(200);
    }
}
