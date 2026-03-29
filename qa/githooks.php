<?php
return [
    'Options' => [
        'execution' => 'full', // full (default), fast
        'processes' => 2,
    ],
    'Tools' => [
        'phpstan',
        'parallel-lint',
        'phpmd',
        'phpcpd',
        'phpcbf',
        'phpcs',
        'phpunit',
        // 'psalm',
    ],
    'phpstan' => [
        'executablePath' => 'vendor/bin/phpstan',
        'config' => './qa/phpstan.neon',
        // 'memory-limit' => '1G', // Examples: 1M 2000M 1G 5G
        'paths' => ['src'],
        // 'level' => 8, // level 0-10 (0 default). Max depends on PHPStan version
        'otherArguments' => '--no-progress --ansi',
    ],
    'parallel-lint' => [
        'executablePath' => 'vendor/bin/parallel-lint',
        'paths' => ['./'],
        'exclude' => ['vendor', 'qa', 'tools'],
        'otherArguments' => '--colors',
        // 'ignoreErrorsOnExit' => true,
    ],
    'phpcs' => [
        'executablePath' => 'tools/php71/phpcs',
        'paths' => ['./'],
        'standard' => './qa/psr12-ruleset.xml', // or predefined rules: Squiz, PSR12, Generic, PEAR
        'ignore' => ['vendor', 'tools'],
        'error-severity' => 1,
        'warning-severity' => 6,
        'otherArguments' => '--report=summary --parallel=2',
    ],
    'phpcbf' => [
        'usePhpcsConfiguration' => true,
        // 'executablePath' => 'tools/php71/phpcbf',
        // 'paths' => ['./'],
        // 'standard' => './qa/psr12-ruleset.xml', // or predefined rules: Squiz, PSR12, Generic, PEAR
        // 'ignore' => ['vendor'], // Se podría configurar en el standard directamente
        // 'error-severity' => 1,
        // 'warning-severity' => 6,
    ],
    'phpmd' => [
        'executablePath' => 'tools/php71/phpmd',
        'paths' => ['./src/'],
        'rules' => './qa/phpmd-ruleset.xml', // or predefined rules cleancode,codesize,controversial,design,naming,unusedcode
        'exclude' => ['vendor'], // Se podría configurar en las rules directamente
        // 'otherArguments' => '--strict',
        // 'ignoreErrorsOnExit' => true,
    ],
    'phpcpd' => [
        'executablePath' => 'tools/php71/phpcpd',
        'paths' => ['./'],
        'exclude' => ['vendor', 'tests', 'tools'],
        // 'otherArguments' => '--min-lines=5',
    ],
    'security-checker' => [
        'executablePath' => 'tools/php71/local-php-security-checker',
        // 'otherArguments' => '-format json',
    ],
    'phpunit' => [
        'executablePath' => 'php7.1 vendor/bin/phpunit',
        // 'group' => 'integration',
        // 'exclude-group' => 'slow',
        // 'filter' => 'testSomething',
        // 'configuration' => 'path/to/configuration.xml',
        'log-junit' => 'junit.xml',
        'otherArguments' => '--colors=always',
        // 'ignoreErrorsOnExit' => true,
    ],
    'psalm' => [
        'executablePath' => 'php7.1 vendor/bin/psalm',
        'config' => 'qa/psalm.xml',
        // 'memory-limit' => '1G', // Examples: 1M 2000M 1G 5G
        // 'threads' => 4,
        // 'no-diff' => true,
        // 'output-format' => 'json',
        // 'plugin' => 'psalm-plugin',
        // 'use-baseline' => 'path/to/baseline.xml',
        // 'report' => 'psalm-report.xml',
        // 'otherArguments' => '--no-progress',
        // 'ignoreErrorsOnExit' => true,
        'paths' => ['src', 'app'],
    ],
];