<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/*
 * TMSWPのmonorepoと同じ規則(PER Coding Style 2.0と、final_class・strict_comparison等の厳密性の規則)で検査する。
 * 対象はapp/・tests/と、プラグインの本体ファイル・tmswp-kernel.phpである。templates/のテンプレートは、
 * WordPress標準の書き方を保つため対象に含めない。
 * 本体ファイルの名前(plugin.php)は、bin/setup.phpがファイルの名前とともに<スラッグ>.phpに書き換える。
 */
$finder = (new Finder())
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->append([
        __FILE__,
        __DIR__ . '/plugin.php',
        __DIR__ . '/tmswp-kernel.php',
    ]);

return (new Config())
    ->setRiskyAllowed(true)
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
