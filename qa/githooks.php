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
            'memory-budget' => [
                'warn-above' => 600,
                // 'fail-above' => 8,
            ],
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
        'ci-tests' => [
            'jobs' => [
                'phpunit',
                'phpunit-git',
                'phpunit-windows',
                'phpunit-integration',
            ],
        ],
    ],

    'jobs' => [
        'phpstan-src' => [
            'type' => 'phpstan',
            'executable-path' => 'vendor/bin/phpstan',
            'config' => './qa/phpstan.neon',
            'paths' => ['src'],
            'other-arguments' => '--no-progress --ansi',
        ],
        'parallel-lint' => [
            'type' => 'parallel-lint',
            'executable-path' => 'vendor/bin/parallel-lint',
            'paths' => ['src', 'app', 'config', 'bootstrap'],
            'exclude' => ['vendor'],
            'other-arguments' => '--colors',
        ],
        'phpcs' => [
            'type' => 'phpcs',
            'executable-path' => 'vendor/bin/phpcs',
            'paths' => ['./'],
            'standard' => './qa/psr12-ruleset.xml',
            'ignore' => ['vendor', 'tools'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'other-arguments' => '--report=summary --parallel=2',
        ],
        'phpcbf' => [
            'type' => 'phpcbf',
            'executable-path' => 'vendor/bin/phpcbf',
            'paths' => ['./'],
            'standard' => './qa/psr12-ruleset.xml',
            'ignore' => ['vendor', 'tools'],
            'error-severity' => 1,
            'warning-severity' => 6,
        ],
        'phpmd-src' => [
            'type' => 'phpmd',
            'executable-path' => 'vendor/bin/phpmd',
            'paths' => ['./src/'],
            'rules' => './qa/phpmd-ruleset.xml',
            'exclude' => ['vendor'],
        ],
        'phpcpd' => [
            'type' => 'phpcpd',
            'executable-path' => 'vendor/bin/phpcpd',
            'paths' => ['./'],
            // Phase 2c: Job/Flow/Flows Runner & Preparation classes follow the same
            // adapter shape by design (parallel pipeline contracts). phpcpd flags
            // them as duplication, but flattening into a single class would erase
            // the type-level distinction between the three Runners.
            // Same rationale for FlowCommand vs FlowsCommand: identical Command
            // shape on purpose, distinct DTOs (FlowRunRequest vs FlowsRunRequest).
            'exclude' => [
                'vendor', 'tests', 'tools', 'src/Tools',
                'src/Execution',
                'app/Commands/FlowsCommand.php',
            ],
        ],
        'phpunit' => [
            'type' => 'phpunit',
            'executable-path' => 'vendor/bin/phpunit',
            'log-junit' => 'junit.xml',
            'other-arguments' => '--colors=always',
        ],
        'phpunit-git' => [
            'extends' => 'phpunit',
            'group' => 'git',
        ],
        'phpunit-windows' => [
            'extends' => 'phpunit',
            'group' => 'windows',
            // On Windows the shell treats `vendor/bin/phpunit` as a command lookup,
            // not a relative path, so composer's unix-style script is not resolved.
            // Prefixing with `php` makes the interpreter run the script explicitly.
            'executable-prefix' => 'php',
        ],
        'phpunit-integration' => [
            'extends' => 'phpunit',
            'group' => 'integration',
        ],
        'psalm-src' => [
            'type' => 'psalm',
            'executable-path' => 'vendor/bin/psalm',
            'config' => 'qa/psalm.xml',
            'paths' => ['src', 'app'],
        ],
        'composer-audit' => [
            'type' => 'custom',
            'script' => 'tools/composer audit',
            'ignore-errors-on-exit' => true,
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
            'executable-path' => 'vendor/bin/phpunit',
            'executable-prefix' => 'PHPUNIT_SPEEDTRAP=disabled php8.5 -d xdebug.mode=coverage',
            'other-arguments' => '--coverage-html reports/coverage/coverage-html --coverage-xml reports/coverage/coverage-xml --log-junit reports/coverage/junit.xml --testdox-html reports/coverage/documentation.html',
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
