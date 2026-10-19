<?php

declare(strict_types=1);

function tmswp_output_violations_fixture(): void
{
    echo 'debug';
    print 'debug';
    printf('%s', 'debug');
    print_r(['debug'], false);
    exit;
}
?>
raw
