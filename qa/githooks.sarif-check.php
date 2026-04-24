<?php

/**
 * SARIF contract configuration.
 *
 * This config targets the intentionally broken fixture under
 * tests/Fixtures/sarif-broken-code/ to force a deterministic set of
 * locatable violations across the four tool parsers the SARIF formatter
 * supports end-to-end (phpstan, phpcs, phpmd, psalm).
 *
 * Consumed by:
 *   - The unit test SarifResultFormatterSchemaTest (schema + golden).
 *   - The on-demand workflow .github/workflows/sarif-contract.yml
 *     (refresh/verify the golden against GitHub Code Scanning).
 *
 * Each job uses built-in tool standards/rulesets (PSR12 / unusedcode /
 * level=max / psalm.xml) instead of project-level configuration so results
 * stay deterministic across local and CI runners regardless of which tool
 * minor version gets installed.
 *
 * Psalm uses the composer-installed `vendor/bin/psalm`. Composer does
 * not commit composer.lock, so each environment resolves its own
 * compatible version: PHP 7.4 runners install psalm 5.26.1 together
 * with laravel-zero 8 / illuminate 8 (old signatures, no deprecation
 * on PHP 7.4), PHP 8.5 runners install psalm 6.16.1 together with
 * laravel-zero 10 / illuminate 10 (modern signatures, nothing to
 * deprecate). Both paths generate a clean SARIF run.
 *
 * In the contributor's local container the default `php` may be 8.4+
 * while a stale composer.lock still has illuminate 8; running
 * `vendor/bin/psalm` then trips psalm's ErrorHandler on the
 * `optional(callable $callback = null)` deprecation. Switch with
 * `cambioPhp 7.4` (or `update-alternatives --set php /usr/bin/php7.4`)
 * before running the flow locally, or refresh composer.lock with an
 * up-to-date laravel-zero/illuminate combo.
 *
 * Do NOT add this config to any regular flow or pre-commit hook.
 */
return [
    'flows' => [
        'sarif-check' => [
            'options' => [
                'processes' => 1,
                'fail-fast' => false,
            ],
            'jobs' => [
                'phpstan-broken',
                'phpcs-broken',
                'phpmd-broken',
                'psalm-broken',
            ],
        ],
    ],

    'jobs' => [
        'phpstan-broken' => [
            'type' => 'phpstan',
            'executablePath' => 'vendor/bin/phpstan',
            'level' => 'max',
            'paths' => ['tests/Fixtures/sarif-broken-code'],
            'otherArguments' => '--no-progress',
            'ignoreErrorsOnExit' => true,
        ],
        'phpcs-broken' => [
            'type' => 'phpcs',
            'executablePath' => 'vendor/bin/phpcs',
            'standard' => 'PSR12',
            'paths' => ['tests/Fixtures/sarif-broken-code'],
            'ignoreErrorsOnExit' => true,
        ],
        'phpmd-broken' => [
            'type' => 'phpmd',
            'executablePath' => 'vendor/bin/phpmd',
            'rules' => 'unusedcode',
            'paths' => ['tests/Fixtures/sarif-broken-code'],
            'ignoreErrorsOnExit' => true,
        ],
        'psalm-broken' => [
            'type' => 'psalm',
            'executablePath' => 'vendor/bin/psalm',
            'config' => 'qa/psalm-sarif-check.xml',
            'paths' => ['tests/Fixtures/sarif-broken-code'],
            'ignoreErrorsOnExit' => true,
        ],
    ],
];
