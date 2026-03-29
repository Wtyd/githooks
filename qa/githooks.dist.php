<?php
return [
    'Options' => [
        'execution' => 'full', // Optional: default full. Values: full or fast
        // Number of tools to run simultaneously. Some tools (phpstan, parallel-lint,
        // phpcs, psalm) spawn their own worker processes internally, so actual OS
        // processes may be higher than this value. Keep low on machines with few cores.
        'processes' => 1,
    ],
    'Tools' => [
        'security-checker',
        'phpstan',
        'parallel-lint',
        'phpcbf',
        'phpcs',
        'phpmd',
        'phpcpd',
        'phpunit',
        'psalm',
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
    //     'failFast' => false, // Optional: default false. Stop remaining tools on failure
    // ],
    // 'phpstan' => [
    //     'executablePath' => 'phpstan',
    //     'execution' => 'fast', // Optional: override global execution mode for this tool
    //     'config' => './qa/phpstan.neon',
    //     // 'memory-limit' => '1G', // Examples: 1M 2000M 1G 5G
    //     'paths' => ['src'],
    //     // 'level' => 9, // level 0-10 (0 default). Max depends on PHPStan version
    //     'error-format' => 'table', // Output format: table, json, raw, github, gitlab, junit, checkstyle, etc.
    //     'no-progress' => true, // Suppress progress output
    //     'clear-result-cache' => false, // Clear result cache before analysis
    //     'otherArguments' => '--ansi',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    //     'failFast' => false, // Optional: default false. Stop remaining tools on failure
    // ],
    // 'parallel-lint' => [
    //     'executablePath' => 'parallel-lint',
    //     'paths' => ['./'],
    //     'exclude' => ['vendor'],
    //     'jobs' => 10, // Number of parallel jobs (-j flag)
    //     'otherArguments' => '--colors',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    //     'failFast' => false, // Optional: default false. Stop remaining tools on failure
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
    //     'failFast' => false, // Optional: default false. Stop remaining tools on failure
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
    //     'failFast' => false, // Optional: default false. Stop remaining tools on failure
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
    //     'failFast' => false, // Optional: default false. Stop remaining tools on failure
    // ],
    // 'phpcpd' => [
    //     'executablePath' => 'phpcpd',
    //     'paths' => ['./'],
    //     'exclude' => ['vendor'],
    //     'min-lines' => 5, // Minimum number of identical lines to detect as copy-paste
    //     'min-tokens' => 70, // Minimum number of identical tokens to detect as copy-paste
    //     'otherArguments' => '--fuzzy',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    //     'failFast' => false, // Optional: default false. Stop remaining tools on failure
    // ],
    // 'phpunit' => [
    //     'executablePath' => 'phpunit',
    //     'group' => 'integration', // Run only tests from the specified group(s), comma-separated
    //     'exclude-group' => 'slow', // Exclude tests from the specified group(s), comma-separated
    //     'filter' => 'testSomething', // Filter which tests to run by regex pattern
    //     'configuration' => 'phpunit.xml', // Path to PHPUnit XML configuration file
    //     'log-junit' => 'junit.xml', // Log test execution in JUnit XML format
    //     'otherArguments' => '--colors=always',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    //     'failFast' => false, // Optional: default false. Stop remaining tools on failure
    // ],
    // 'psalm' => [
    //     'executablePath' => 'psalm',
    //     'config' => 'psalm.xml', // Path to Psalm XML configuration file
    //     'memory-limit' => '1G', // Examples: 1M 2000M 1G 5G
    //     'threads' => 4, // Number of threads for parallel analysis
    //     'no-diff' => true, // Disable diff mode (analyze all files)
    //     'output-format' => 'console', // Output format: console, json, xml, checkstyle, junit, etc.
    //     'plugin' => 'path/to/plugin.php', // Path to a Psalm plugin
    //     'use-baseline' => 'psalm-baseline.xml', // Path to baseline file to ignore known issues
    //     'report' => 'psalm-report.xml', // Generate a report file (format inferred from extension)
    //     'paths' => ['src', 'app'], // Directories to analyze
    //     'otherArguments' => '--no-progress',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    //     'failFast' => false, // Optional: default false. Stop remaining tools on failure
    // ],
    // 'script' => [
    //     'name' => 'php-cs-fixer', // Optional: custom name for Tools array and CLI (e.g. githooks tool php-cs-fixer)
    //     'executablePath' => 'vendor/bin/php-cs-fixer', // Required: path to the executable
    //     'otherArguments' => 'fix --dry-run --config=.php-cs-fixer.php',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    //     'failFast' => false, // Optional: default false. Stop remaining tools on failure
    // ],

];
