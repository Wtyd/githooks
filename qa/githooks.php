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
                'Phpcbf',
                'Phpstan Src',
                'Parallel-lint',
                'Phpmd Src',
                'Phpcpd',
                'Phpcs',
                'Phpunit',
                'Composer Audit',
                // 'psalm_src',
            ],
        ],
    ],

    'jobs' => [
        'Phpstan Src' => [
            'type' => 'phpstan',
            'executablePath' => 'vendor/bin/phpstan',
            'config' => './qa/phpstan.neon',
            'paths' => ['src'],
            'otherArguments' => '--no-progress --ansi',
        ],
        'Parallel-lint' => [
            'type' => 'parallel-lint',
            'executablePath' => 'vendor/bin/parallel-lint',
            'paths' => ['./'],
            'exclude' => ['vendor', 'qa', 'tools'],
            'otherArguments' => '--colors',
        ],
        'Phpcs' => [
            'type' => 'phpcs',
            'executablePath' => 'tools/php74/phpcs',
            'paths' => ['./'],
            'standard' => './qa/psr12-ruleset.xml',
            'ignore' => ['vendor', 'tools'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2',
        ],
        'Phpcbf' => [
            'type' => 'phpcbf',
            'executablePath' => 'tools/php74/phpcbf',
            'paths' => ['./'],
            'standard' => './qa/psr12-ruleset.xml',
            'ignore' => ['vendor', 'tools'],
            'error-severity' => 1,
            'warning-severity' => 6,
        ],
        'Phpmd Src' => [
            'type' => 'phpmd',
            'executablePath' => 'tools/php74/phpmd',
            'paths' => ['./src/'],
            'rules' => './qa/phpmd-ruleset.xml',
            'exclude' => ['vendor'],
        ],
        'Phpcpd' => [
            'type' => 'phpcpd',
            'executablePath' => 'tools/php80/phpcpd',
            'paths' => ['./'],
            'exclude' => ['vendor', 'tests', 'tools', 'src/Tools'],
        ],
        'Phpunit' => [
            'type' => 'phpunit',
            'executablePath' => 'vendor/bin/phpunit',
            'log-junit' => 'junit.xml',
            'otherArguments' => '--colors=always',
        ],
        'psalm_src' => [
            'type' => 'psalm',
            'executablePath' => 'vendor/bin/psalm',
            'config' => 'qa/psalm.xml',
            'paths' => ['src', 'app'],
        ],
        'Composer Audit' => [
            'type' => 'custom',
            'script' => 'tools/composer audit',
            'ignoreErrorsOnExit' => true,
        ],
    ],
];
