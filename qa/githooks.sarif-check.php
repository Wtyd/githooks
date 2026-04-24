<?php

/**
 * SARIF contract configuration.
 *
 * This config targets the intentionally broken fixture under
 * tests/Fixtures/sarif-broken-code/ to force a deterministic set of
 * locatable violations that exercise the SARIF formatter end-to-end.
 * It is consumed by:
 *
 *   - The unit test SarifResultFormatterSchemaTest (schema + golden).
 *   - The on-demand workflow .github/workflows/sarif-contract.yml
 *     (refresh/verify the golden against GitHub Code Scanning).
 *
 * Only phpstan is used: it reliably produces 3 locatable issues on the
 * fixture (no-return-type, no-param-type, undefined $y) which is enough
 * to cover every SARIF field the formatter emits (tool.driver, rules,
 * results, physicalLocation, region, level). Adding phpcs/phpmd would
 * add noise without increasing coverage.
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
            ],
        ],
    ],

    'jobs' => [
        'phpstan-broken' => [
            'type' => 'phpstan',
            'executablePath' => 'vendor/bin/phpstan',
            'config' => './qa/phpstan.neon',
            'paths' => ['tests/Fixtures/sarif-broken-code'],
            'otherArguments' => '--no-progress',
            'ignoreErrorsOnExit' => true,
        ],
    ],
];
