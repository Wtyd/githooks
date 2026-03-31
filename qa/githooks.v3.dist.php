<?php

return [
    'hooks' => [
        'pre-commit' => ['lint'],
    ],

    'flows' => [
        'options' => [
            'fail-fast' => false,
            // Number of jobs to run simultaneously. Some tools (phpstan, parallel-lint,
            // phpcs, psalm) spawn their own worker processes internally, so actual OS
            // processes may be higher than this value. Keep low on machines with few cores.
            'processes' => 1,
        ],
        'lint' => [
            'jobs' => ['phpcs_src'],
        ],
    ],

    'jobs' => [
        'phpcs_src' => [
            'type'     => 'phpcs',
            'paths'    => ['src'],
            'standard' => 'PSR12',
        ],
        // 'phpstan_src' => [
        //     'type'   => 'phpstan',
        //     'config' => 'phpstan.neon',
        //     'paths'  => ['src'],
        // ],
        // 'phpunit_all' => [
        //     'type'   => 'phpunit',
        //     'config' => 'phpunit.xml',
        // ],
        // 'custom_lint' => [
        //     'type'   => 'custom',
        //     'script' => 'npm run lint',
        // ],
    ],
];
