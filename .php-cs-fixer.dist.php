<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRiskyAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRules([
        '@PSR12' => true,

        'heredoc_indentation' => ['indentation' => 'same_as_start'],

        'operator_linebreak' => ['position' => 'beginning'],

        'cast_spaces' => ['space' => 'single'],

        'trailing_comma_in_multiline' => ['elements' => ['arrays']],

        'function_declaration' => [
            'closure_function_spacing' => 'one',
            'closure_fn_spacing' => 'none',
        ],

        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => [
                'class',
                'function',
                'const',
            ],
        ],

        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],

        'blank_line_before_statement' => true,
        'not_operator_with_successor_space' => true,
        'single_line_empty_body' => true,
        'method_chaining_indentation' => true,
        'no_unused_imports' => true,
        'single_quote' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
