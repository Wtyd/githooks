<?php

return [
    'hooks' => [
        'pre-commit' => ['qa'],
    ],

    'flows' => [
        'options' => [
            'fail-fast' => false,
            // Number of jobs to run simultaneously. Some tools (phpstan, parallel-lint,
            // phpcs, psalm) spawn their own worker processes internally, so actual OS
            // processes may be higher than this value. Keep low on machines with few cores.
            'processes' => 2,
        ],
        'qa' => [
            'jobs' => [
                'phpstan_src',
                'parallel_lint',
                'phpmd_src',
                'phpcpd_all',
                'phpcbf_all',
                'phpcs_all',
                'phpunit_all',
                // 'psalm_src',
            ],
        ],
    ],

    'jobs' => [
        'phpstan_src' => [
            'type' => 'phpstan',
            'executablePath' => 'php8.1 vendor/bin/phpstan',
            'config' => './qa/phpstan.neon',
            'paths' => ['src'],
            'otherArguments' => '--no-progress --ansi',
        ],
        'parallel_lint' => [
            'type' => 'parallel-lint',
            'executablePath' => 'php7.4 vendor/bin/parallel-lint',
            'paths' => ['./'],
            'exclude' => ['vendor', 'qa', 'tools'],
            'otherArguments' => '--colors',
        ],
        'phpcs_all' => [
            'type' => 'phpcs',
            'executablePath' => 'tools/php74/phpcs',
            'paths' => ['./'],
            'standard' => './qa/psr12-ruleset.xml',
            'ignore' => ['vendor', 'tools'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2',
        ],
        'phpcbf_all' => [
            'type' => 'phpcbf',
            'executablePath' => 'tools/php74/phpcbf',
            'paths' => ['./'],
            'standard' => './qa/psr12-ruleset.xml',
            'ignore' => ['vendor', 'tools'],
            'error-severity' => 1,
            'warning-severity' => 6,
        ],
        'phpmd_src' => [
            'type' => 'phpmd',
            'executablePath' => 'tools/php74/phpmd',
            'paths' => ['./src/'],
            'rules' => './qa/phpmd-ruleset.xml',
            'exclude' => ['vendor'],
        ],
        'phpcpd_all' => [
            'type' => 'phpcpd',
            'executablePath' => 'tools/php80/phpcpd',
            'paths' => ['./'],
            'exclude' => ['vendor', 'tests', 'tools'],
        ],
        'phpunit_all' => [
            'type' => 'phpunit',
            'executablePath' => 'php7.4 vendor/bin/phpunit',
            'log-junit' => 'junit.xml',
            'otherArguments' => '--colors=always',
        ],
        'psalm_src' => [
            'type' => 'psalm',
            'executablePath' => 'php7.4 vendor/bin/psalm',
            'config' => 'qa/psalm.xml',
            'paths' => ['src', 'app'],
        ],
        // Example: replace security-checker with composer audit via custom job
        // 'composer_audit' => [
        //     'type' => 'custom',
        //     'script' => 'composer audit',
        // ],
    ],
];
