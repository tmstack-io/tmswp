<?php

declare(strict_types=1);

function tmswp_good_action_fixture(): array
{
    $debug = print_r(['ok'], true);

    if ($debug === 'redirect') {
        wp_safe_redirect('/thanks/');
        exit;
    }

    return ['debug' => $debug];
}
