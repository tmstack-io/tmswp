<?php

declare(strict_types=1);

function tmswp_set_404_violation_fixture(): void
{
    global $wp_query;

    $wp_query->set_404();
}
