<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

/**
 * Native `pint` job type. Wraps `vendor/bin/pint` — Laravel Pint, Laravel's
 * default code-style fixer, built on PHP CS Fixer — so it becomes a first-class
 * fixer instead of a `custom` script, completing the Symfony/Laravel fixer pair
 * with {@see PhpCsFixerJob}.
 *
 * Exit-code semantics (verified against Pint 1.30; they do NOT mirror
 * php-cs-fixer's bit flags despite the shared engine):
 *   - fix mode:  0 whether or not fixes were applied; 1 only on errors (a file
 *     with a parse error — the parseable files still get fixed).
 *   - `--test`:  0 clean, 1 when style issues are found (normalized to 1, not
 *     php-cs-fixer's 8).
 *
 * Pint's own `--dirty` flag is deliberately not mapped: file selection is
 * governed by GitHooks (fast mode injects the staged files as explicit paths),
 * and two competing selection mechanisms would blur which files ran.
 *
 * Pint keeps its cache in the system temp directory, never in the project, so
 * there is nothing for cache:clear here (no getCachePaths() override).
 */
class PintJob extends JobAbstract
{
    public const SUPPORTS_FAST = true;

    protected const ARGUMENT_MAP = [
        'config' => ['flag' => '--config', 'type' => 'value'],
        'test'   => ['flag' => '--test', 'type' => 'boolean'],
        'paths'  => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'pint';
    }

    public function mayApplyFixes(): bool
    {
        return empty($this->args['test']);
    }

    /**
     * In fix mode, exit code 0 means Pint ran successfully and may have applied
     * fixes (it also exits 0 when there was nothing to fix) — re-staging is
     * safe (idempotent). In `--test` mode no files are changed.
     */
    public function isFixApplied(int $exitCode): bool
    {
        if (!empty($this->args['test'])) {
            return false;
        }

        return $exitCode === 0;
    }
}
