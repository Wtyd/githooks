<?php

return [
    'hooks' => [
        'command' => 'php7.4 githooks',
        'pre-commit' => ['qa'],
    ],

    'flows' => [
        'options' => [
            'fail-fast' => false,
            // Number of jobs to run simultaneously. Some tools (phpstan, parallel-lint,
            // phpcs, psalm) spawn their own worker processes internally, so actual OS
            // processes may be higher than this value. Keep low on machines with few cores.
            'processes' => 10,
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
        'schedule' => [
            'options' => [
                'processes' => 1,
                'fail-fast' => true,
            ],
            'jobs' => [
                'Composer Update',
                'Coverage',
                'Infection',
                'PhpMetrics',
                'Composer Downgrade',
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
            'paths' => ['src', 'app', 'config', 'bootstrap'],
            'exclude' => ['vendor'],
            'otherArguments' => '--colors',
        ],
        'Phpcs' => [
            'type' => 'phpcs',
            'executablePath' => 'vendor/bin/phpcs',
            'paths' => ['./'],
            'standard' => './qa/psr12-ruleset.xml',
            'ignore' => ['vendor', 'tools'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2',
        ],
        'Phpcbf' => [
            'type' => 'phpcbf',
            'executablePath' => 'vendor/bin/phpcbf',
            'paths' => ['./'],
            'standard' => './qa/psr12-ruleset.xml',
            'ignore' => ['vendor', 'tools'],
            'error-severity' => 1,
            'warning-severity' => 6,
        ],
        'Phpmd Src' => [
            'type' => 'phpmd',
            'executablePath' => 'vendor/bin/phpmd',
            'paths' => ['./src/'],
            'rules' => './qa/phpmd-ruleset.xml',
            'exclude' => ['vendor'],
        ],
        'Phpcpd' => [
            'type' => 'phpcpd',
            'executablePath' => 'vendor/bin/phpcpd',
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
        'Composer Update' => [
            'type' => 'custom',
            'script' => 'php8.4 tools/composer update',
        ],
        'Composer Downgrade' => [
            'type' => 'custom',
            'script' => 'php7.4 tools/composer update',
        ],
        'Coverage' => [
            'type' => 'phpunit',
            'executablePath' => 'vendor/bin/phpunit',
            'executable-prefix' => 'php8.5 -d xdebug.mode=coverage',
            'otherArguments' => '--coverage-html reports/coverage/coverage-html --coverage-xml reports/coverage/coverage-xml --log-junit reports/coverage/junit.xml --testdox-html reports/coverage/documentation.html',
        ],
        'Infection' => [
            'type' => 'custom',
            'executable-prefix' => 'php8.4',
            'script' => 'tools/infection --threads=10 --skip-initial-tests --no-progress --show-mutations=0 --coverage=reports/coverage',
        ],
        'PhpMetrics' => [
            'type' => 'custom',
            'executable-prefix' => 'php8.4',
            'script' => 'tools/phpmetrics --report-html=reports/phpmetrics --junit=reports/coverage/junit.xml ./src',
        ],
    ],
];
