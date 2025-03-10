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
    ],
    // Configuration of each tool
    // 'security-checker' => [
    //     'executablePath' => 'composer audit',
    //     'otherArguments' => '-format json',
    //     'ignoreErrorsOnExit' => false // Optional: default false
    // ],
    // 'phpstan' => [
    //     'executablePath' => 'phpstan',
    //     'config' => './qa/phpstan.neon',
    //     // 'memory-limit' => '1G', // Examples: 1M 2000M 1G 5G
    //     'paths' => ['src'],
    //     // 'level' => 9, // level 0-9 (0 default, 9 max)
    //     'otherArguments' => '--no-progress',
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
    //     'otherArguments' => '--report=summary --parallel=2',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    // 'phpcbf' => [
    //     'usePhpcsConfiguration' => true, // if true no more configuration is needed. It graves the arguments of phpcs configuration
    //     'executablePath' => 'phpcbf',
    //     'paths' => ['./'],
    //     'standard' => './myRules.xml', // or predefined rules: Squiz, PSR12, Generic, PEAR
    //     'ignore' => ['vendor'],
    //     'error-severity' => 1,
    //     'warning-severity' => 6,
    //     'otherArguments' => '--report=summary --parallel=2',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    // 'phpmd' => [
    //     'executablePath' => 'phpmd',
    //     'paths' => ['./src/'],
    //     'rules' => './myRules.xml', // or predefined rules cleancode,codesize,controversial,design,naming,unusedcode
    //     'exclude' => ['vendor'],
    //     'otherArguments' => '--strict',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    // 'phpcpd' => [
    //     'executablePath' => 'phpcpd',
    //     'paths' => ['./'],
    //     'exclude' => ['vendor'],
    //     'otherArguments' => '--min-lines=5',
    //     'ignoreErrorsOnExit' => false, // Optional: default false
    // ],
    
];