<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = (new Finder())
    ->in([
        __DIR__ . '/packages',
        __DIR__ . '/tests',
        __DIR__ . '/bin',
        __DIR__ . '/tools',
        // スターターは、スターター自身の.php-cs-fixer.dist.phpと同じ対象(app/・tests/・テンプレートでないルートのPHP)と
        // bin/setup.phpに限る。テンプレート(テーマのテンプレート階層のファイル・parts/・ブロックのrender.php、
        // プラグインのtemplates/)と、ビルドの生成物(build/)は含めない
        __DIR__ . '/starters/theme/app',
        __DIR__ . '/starters/theme/tests',
        __DIR__ . '/starters/plugin/app',
        __DIR__ . '/starters/plugin/tests',
    ])
    ->name('*.php')
    // 例外の階層は、具体クラス(RuntimeException・LogicException等)をFWの例外が継承するため、final_classを適用できない
    ->notPath('#^core/src/Exception/#')
    // coreのテストのfixtureは、外部のコードの写し(古いpsr/log・psr/container)と、PHPStanの拡張の検査の入力を含む
    ->notPath('#^core/tests/fixtures/#')
    // packages/*/languages/ の .l10n.php は `wp i18n make-php` の生成物で、出力をそのままコミットする
    ->notPath('#^[^/]+/languages/#')
    ->append([
        __FILE__,
        __DIR__ . '/monorepo-builder.php',
        __DIR__ . '/packages/acf-bridge/bin/tms-acf',
        __DIR__ . '/starters/theme/functions.php',
        __DIR__ . '/starters/theme/tmswp-kernel.php',
        __DIR__ . '/starters/theme/bin/setup.php',
        __DIR__ . '/starters/plugin/plugin.php',
        __DIR__ . '/starters/plugin/tmswp-kernel.php',
        __DIR__ . '/starters/plugin/bin/setup.php',
    ]);

return (new Config())
    ->setRiskyAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        // PER Coding Style 2.0(PSR-12の後継規格)。php-cs-fixerではドット表記の
        // @PER-CS2.0識別子が非推奨(4.0で削除予定)のため'x'表記を用いる
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,

        // --- 厳密性(コーディング規約の機械的強制) ---
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'void_return' => true,
        'final_class' => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // --- import規律 ---
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

        // --- モダン構文 ---
        'array_syntax' => ['syntax' => 'short'],
        'list_syntax' => ['syntax' => 'short'],
        'modernize_strpos' => true,
        'modernize_types_casting' => true,
        'get_class_to_class_keyword' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,

        // --- 表記統一 ---
        // concat_space(one)/ cast_spaces(single)/ trailing_comma_in_multilineは
        // @PER-CS2x0基底に内包されるため手動指定しない。trailing commaの適用対象は
        // 基底の既定(array_destructuringを含む)へ拡張される(意図した挙動確定)
        'single_quote' => true,
        'binary_operator_spaces' => true,
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
        'class_attributes_separation' => ['elements' => ['method' => 'one']],

        // --- PHPDoc ---
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'phpdoc_separation' => true,

        // --- PHPUnit ---
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'php_unit_data_provider_static' => true,
        'php_unit_data_provider_return_type' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
    ]);
