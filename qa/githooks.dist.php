<?php
return [
    'Options' => [
        'execution' => 'full', // Optional: default full. Values: full or fast
        'processes' => 1, // Optional: default 1. Number of parallel processes
    ],
    'Tools' => [
        'security-checker',
        'phpstan',
        'parallel-lint',
        'phpcbf',
        'phpcs',
        'phpmd',
        'phpcpd',
        'script',
    ],
    // Configuration of each tool
    // Each tool supports an optional 'execution' key to override the global mode.
    // Values: 'full' or 'fast'. If omitted, inherits the global Options.execution value.
    // CLI execution argument (e.g. `githooks tool all fast`) overrides both global and per-tool settings.

    // 'security-checker' => [
    //     'executablePath' => 'composer audit',
    //     'otherArguments' => '-format json',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    // 'phpstan' => [
    //     'executablePath' => 'phpstan',
    //     'execution' => 'fast', // Optional: override global execution mode for this tool
    //     'config' => './qa/phpstan.neon',
    //     // 'memory-limit' => '1G', // Examples: 1M 2000M 1G 5G
    //     'paths' => ['src'],
    //     // 'level' => 9, // level 0-9 (0 default, 9 max)
    //     'error-format' => 'table', // Output format: table, json, raw, github, gitlab, junit, checkstyle, etc.
    //     'no-progress' => true, // Suppress progress output
    //     'clear-result-cache' => false, // Clear result cache before analysis
    //     'otherArguments' => '--ansi',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    // 'parallel-lint' => [
    //     'executablePath' => 'parallel-lint',
    //     'paths' => ['./'],
    //     'exclude' => ['vendor'],
    //     'otherArguments' => '--colors',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    // 'phpcs' => [
    //     'executablePath' => 'phpcs',
    //     'paths' => ['./'],
    //     'standard' => './myRules.xml', // or predefined rules: Squiz, PSR12, Generic, PEAR
    //     'ignore' => ['vendor'],
    //     'error-severity' => 1,
    //     'warning-severity' => 6,
    //     'cache' => true, // Enable result caching
    //     'no-cache' => false, // Disable caching (overrides cache)
    //     'report' => 'summary', // Report format: full, summary, json, csv, checkstyle, etc.
    //     'parallel' => 2, // Number of parallel processes
    //     'otherArguments' => '--colors',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    // 'phpcbf' => [
    //     'usePhpcsConfiguration' => true, // if true no more configuration is needed. It graves the arguments of phpcs configuration
    //     'execution' => 'fast', // Optional: run phpcbf only against modified files
    //     'executablePath' => 'phpcbf',
    //     'paths' => ['./'],
    //     'standard' => './myRules.xml', // or predefined rules: Squiz, PSR12, Generic, PEAR
    //     'ignore' => ['vendor'],
    //     'error-severity' => 1,
    //     'warning-severity' => 6,
    //     'cache' => true, // Enable result caching
    //     'no-cache' => false, // Disable caching (overrides cache)
    //     'report' => 'summary', // Report format: full, summary, json, csv, checkstyle, etc.
    //     'parallel' => 2, // Number of parallel processes
    //     'otherArguments' => '--colors',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    // 'phpmd' => [
    //     'executablePath' => 'phpmd',
    //     'paths' => ['./src/'],
    //     'rules' => './myRules.xml', // or predefined rules cleancode,codesize,controversial,design,naming,unusedcode
    //     'exclude' => ['vendor'],
    //     'cache' => true, // Enable caching (PHPMD 2.13.0+)
    //     'cache-file' => '.phpmd.cache', // Custom cache file path
    //     'cache-strategy' => 'content', // Cache strategy: content or timestamp
    //     'suffixes' => 'php', // File suffixes to check (comma-separated, default: php)
    //     'baseline-file' => 'phpmd-baseline.xml', // Baseline file to ignore known violations
    //     'otherArguments' => '--strict',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    // 'phpcpd' => [
    //     'executablePath' => 'phpcpd',
    //     'paths' => ['./'],
    //     'exclude' => ['vendor'],
    //     'min-lines' => 5, // Minimum number of identical lines to detect as copy-paste
    //     'min-tokens' => 70, // Minimum number of identical tokens to detect as copy-paste
    //     'otherArguments' => '--fuzzy',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    // 'script' => [
    //     'name' => 'php-cs-fixer', // Optional: custom name for Tools array and CLI (e.g. githooks tool php-cs-fixer)
    //     'executablePath' => 'vendor/bin/php-cs-fixer', // Required: path to the executable
    //     'otherArguments' => 'fix --dry-run --config=.php-cs-fixer.php',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],

];