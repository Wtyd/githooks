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
                'phpcbf',
                'phpstan-src',
                'parallel-lint',
                'phpmd-src',
                'phpcpd',
                'phpcs',
                'phpunit',
                'composer-audit',
                // 'psalm-src',
            ],
        ],
        'schedule' => [
            'options' => [
                'processes' => 1,
                'fail-fast' => true,
            ],
            'jobs' => [
                'composer-update',
                'coverage',
                'infection',
                'phpmetrics',
                'composer-downgrade',
            ],
        ],
    ],

    'jobs' => [
        'phpstan-src' => [
            'type' => 'phpstan',
            'executablePath' => 'vendor/bin/phpstan',
            'config' => './qa/phpstan.neon',
            'paths' => ['src'],
            'otherArguments' => '--no-progress --ansi',
        ],
        'parallel-lint' => [
            'type' => 'parallel-lint',
            'executablePath' => 'vendor/bin/parallel-lint',
            'paths' => ['src', 'app', 'config', 'bootstrap'],
            'exclude' => ['vendor'],
            'otherArguments' => '--colors',
        ],
        'phpcs' => [
            'type' => 'phpcs',
            'executablePath' => 'vendor/bin/phpcs',
            'paths' => ['./'],
            'standard' => './qa/psr12-ruleset.xml',
            'ignore' => ['vendor', 'tools'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2',
        ],
        'phpcbf' => [
            'type' => 'phpcbf',
            'executablePath' => 'vendor/bin/phpcbf',
            'paths' => ['./'],
            'standard' => './qa/psr12-ruleset.xml',
            'ignore' => ['vendor', 'tools'],
            'error-severity' => 1,
            'warning-severity' => 6,
        ],
        'phpmd-src' => [
            'type' => 'phpmd',
            'executablePath' => 'vendor/bin/phpmd',
            'paths' => ['./src/'],
            'rules' => './qa/phpmd-ruleset.xml',
            'exclude' => ['vendor'],
        ],
        'phpcpd' => [
            'type' => 'phpcpd',
            'executablePath' => 'vendor/bin/phpcpd',
            'paths' => ['./'],
            'exclude' => ['vendor', 'tests', 'tools', 'src/Tools'],
        ],
        'phpunit' => [
            'type' => 'phpunit',
            'executablePath' => 'vendor/bin/phpunit',
            'log-junit' => 'junit.xml',
            'otherArguments' => '--colors=always',
        ],
        'psalm-src' => [
            'type' => 'psalm',
            'executablePath' => 'vendor/bin/psalm',
            'config' => 'qa/psalm.xml',
            'paths' => ['src', 'app'],
        ],
        'composer-audit' => [
            'type' => 'custom',
            'script' => 'tools/composer audit',
            'ignoreErrorsOnExit' => true,
        ],
        'composer-update' => [
            'type' => 'custom',
            'script' => 'php8.4 tools/composer update',
        ],
        'composer-downgrade' => [
            'type' => 'custom',
            'script' => 'php7.4 tools/composer update',
        ],
        'coverage' => [
            'type' => 'phpunit',
            'executablePath' => 'vendor/bin/phpunit',
            'executable-prefix' => 'php8.5 -d xdebug.mode=coverage',
            'otherArguments' => '--coverage-html reports/coverage/coverage-html --coverage-xml reports/coverage/coverage-xml --log-junit reports/coverage/junit.xml --testdox-html reports/coverage/documentation.html',
        ],
        'infection' => [
            'type' => 'custom',
            'executable-prefix' => 'php8.4',
            'script' => 'tools/infection --threads=10 --skip-initial-tests --no-progress --show-mutations=0 --coverage=reports/coverage',
        ],
        'phpmetrics' => [
            'type' => 'custom',
            'executable-prefix' => 'php8.4',
            'script' => 'tools/phpmetrics --report-html=reports/phpmetrics --junit=reports/coverage/junit.xml ./src',
        ],
    ],
];
