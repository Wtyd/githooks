<?php

/**
 * GitHooks v3 configuration file.
 *
 * Structure: hooks -> flows -> jobs
 *   - hooks:  Map git events to flows/jobs (optional, only needed for git hooks)
 *   - flows:  Named groups of jobs with shared options
 *   - jobs:   Individual QA tasks with their configuration
 *
 * Usage:
 *   githooks flow <name>       Run a flow
 *   githooks job <name>        Run a single job
 *   githooks hook              Install git hooks from the 'hooks' section
 *
 * All jobs support these common options:
 *   - executablePath:       Path to the tool binary (default: tool name from PATH)
 *   - otherArguments:       Extra CLI arguments not natively supported
 *   - ignoreErrorsOnExit:   Don't fail the flow when this job fails (default: false)
 *   - failFast:             Stop remaining jobs on failure (default: false)
 */
return [
    // 'hooks' => [
    //     'pre-commit' => ['lint'],
    //     'pre-push'   => ['lint', 'test'],
    // ],

    'flows' => [
        'options' => [
            'fail-fast' => false,
            // Number of jobs to run simultaneously. Some tools (phpstan, parallel-lint,
            // phpcs, psalm) spawn their own worker processes internally, so actual OS
            // processes may be higher than this value. Keep low on machines with few cores.
            'processes' => 1,
        ],
        'lint' => [
            // 'options' => ['fail-fast' => true],  // Override global options per flow
            'jobs' => ['phpcs-src'],
        ],
        // 'test' => [
        //     'jobs' => ['phpunit-all'],
        // ],
    ],

    'jobs' => [
        // --- PHP_CodeSniffer (phpcs) ---
        'phpcs-src' => [
            'type' => 'phpcs',
            'paths' => ['src'],
            'standard' => 'PSR12',           // or path to ruleset: './qa/psr12-ruleset.xml'
            // 'ignore' => ['vendor'],
            // 'error-severity' => 1,
            // 'warning-severity' => 6,
            // 'cache' => true,
            // 'no-cache' => false,
            // 'report' => 'summary',
            // 'parallel' => 2,
        ],

        // --- PHP Code Beautifier and Fixer (phpcbf) ---
        // 'phpcbf-src' => [
        //     'type' => 'phpcbf',
        //     'paths' => ['src'],
        //     'standard' => 'PSR12',
        //     'ignore' => ['vendor'],
        // ],

        // --- PHPStan ---
        // 'phpstan-src' => [
        //     'type' => 'phpstan',
        //     'config' => 'phpstan.neon',
        //     'paths' => ['src'],
        //     // 'level' => 9,                // 0-10 (default: 0)
        //     // 'memory-limit' => '1G',
        //     // 'error-format' => 'table',
        //     // 'no-progress' => true,
        //     // 'clear-result-cache' => false,
        // ],

        // --- PHP Mess Detector (phpmd) ---
        // 'phpmd-src' => [
        //     'type' => 'phpmd',
        //     'paths' => ['src'],
        //     'rules' => 'cleancode,codesize,controversial,design,naming,unusedcode',
        //     // 'exclude' => ['vendor'],
        //     // 'cache' => true,              // PHPMD 2.13.0+
        //     // 'cache-file' => '.phpmd.cache',
        //     // 'cache-strategy' => 'content',
        //     // 'suffixes' => 'php',
        //     // 'baseline-file' => 'phpmd-baseline.xml',
        // ],

        // --- Parallel-Lint ---
        // 'parallel-lint' => [
        //     'type' => 'parallel-lint',
        //     'paths' => ['./'],
        //     'exclude' => ['vendor'],
        //     // 'jobs' => 10,                 // Number of parallel jobs (-j flag)
        // ],

        // --- PHP Copy/Paste Detector (phpcpd) ---
        // 'phpcpd-all' => [
        //     'type' => 'phpcpd',
        //     'paths' => ['./'],
        //     'exclude' => ['vendor'],
        //     // 'min-lines' => 5,
        //     // 'min-tokens' => 70,
        // ],

        // --- PHPUnit ---
        // 'phpunit-all' => [
        //     'type' => 'phpunit',
        //     // 'config' => 'phpunit.xml',
        //     // 'group' => 'integration',
        //     // 'exclude-group' => 'slow',
        //     // 'filter' => 'testSomething',
        //     // 'log-junit' => 'junit.xml',
        // ],

        // --- Psalm ---
        // 'psalm-src' => [
        //     'type' => 'psalm',
        //     'config' => 'psalm.xml',
        //     'paths' => ['src'],
        //     // 'memory-limit' => '1G',
        //     // 'threads' => 4,
        //     // 'no-diff' => true,
        //     // 'output-format' => 'console',
        //     // 'plugin' => 'path/to/plugin.php',
        //     // 'use-baseline' => 'psalm-baseline.xml',
        //     // 'report' => 'psalm-report.xml',
        // ],

        // --- Custom job (replaces security-checker; use for any non-native tool) ---
        // 'composer-audit' => [
        //     'type' => 'custom',
        //     'script' => 'composer audit',
        // ],
        // 'eslint' => [
        //     'type' => 'custom',
        //     'script' => 'npx eslint src/',
        // ],
    ],
];
