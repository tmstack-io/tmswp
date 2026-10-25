<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = (new Finder())
    ->in([
        __DIR__ . '/packages',
        __DIR__ . '/starters',
        __DIR__ . '/tests',
        __DIR__ . '/bin',
        __DIR__ . '/tools',
    ])
    ->name('*.php')
    ->notPath('#^core/src/Exception/#')
    ->notPath('#^core/tests/fixtures/#')
    // packages/*/languages/ の .l10n.php は `wp i18n make-php` の生成物で、出力をそのままコミットする
    ->notPath('#^[^/]+/languages/#')
    ->append([__FILE__, __DIR__ . '/monorepo-builder.php', __DIR__ . '/packages/acf-bridge/bin/tms-acf']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'void_return' => true,
        'final_class' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'fully_qualified_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            // `\call_user_func_array()` はコンパイル時に直接の呼び出しへ置き換わり、呼び出し元の strict_types が
            // コールバックの引数に効く。Kernel はフックメソッドを WordPress と同じ弱い型変換で呼ぶため、名前空間を付けない
            'exclude' => ['call_user_func_array'],
            'scope' => 'namespaced',
        ],
        'array_syntax' => ['syntax' => 'short'],
        'list_syntax' => ['syntax' => 'short'],
        'modernize_strpos' => true,
        'modernize_types_casting' => true,
        'get_class_to_class_keyword' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'single_quote' => true,
        'binary_operator_spaces' => true,
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'phpdoc_separation' => true,
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'php_unit_data_provider_static' => true,
        'php_unit_data_provider_return_type' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
    ]);
