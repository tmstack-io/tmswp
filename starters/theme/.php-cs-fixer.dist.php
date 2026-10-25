<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->append([
        __FILE__,
        __DIR__ . '/footer.php',
        __DIR__ . '/functions.php',
        __DIR__ . '/header.php',
        __DIR__ . '/index.php',
        __DIR__ . '/tmswp-kernel.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'single_line_empty_body' => true,
        'strict_param' => true,
    ])
    ->setFinder($finder);
