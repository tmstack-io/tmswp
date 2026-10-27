<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/app', __DIR__ . '/blocks', __DIR__ . '/dev', __DIR__ . '/parts', __DIR__ . '/tests'])
    // app/Acf/はtms-acfの生成物(手で編集しない)。整形もtms-acf syncの出力のままにする
    ->exclude('Acf')
    ->append([
        __FILE__,
        __DIR__ . '/footer.php',
        __DIR__ . '/front-page.php',
        __DIR__ . '/functions.php',
        __DIR__ . '/header.php',
        __DIR__ . '/index.php',
        __DIR__ . '/page-contact.php',
        __DIR__ . '/page.php',
        __DIR__ . '/single-product.php',
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
