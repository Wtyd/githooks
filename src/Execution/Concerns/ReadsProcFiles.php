<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Concerns;

/**
 * Best-effort reads of /proc pseudo-files, shared by the Linux RSS sampler
 * and the Linux process tree so both tolerate the same races against procfs.
 */
trait ReadsProcFiles
{
    /**
     * Best-effort read of a /proc pseudo-file. Returns null on any failure
     * (file gone, permission denied, transient I/O error). The error
     * control operator is intentional and necessary here:
     *
     *  - With `@`, error_reporting() drops to 0 inside the expression and
     *    the standard Symfony/Laravel-Zero error handler short-circuits
     *    its warning-to-ErrorException upgrade, so the read fails quietly
     *    by returning false. This is the hot path each second under
     *    --threads=10 mutation testing — keeping it allocation-free and
     *    throw-free matters.
     *  - The try/catch is the safety net for the rarer case of a strict
     *    handler that ignores error_reporting() and throws anyway. We
     *    catch \Throwable so any vendor-specific exception type gets
     *    swallowed too.
     *
     * PHPMD's ErrorControlOperator rule is correct in general but not for
     * race-prone reads against procfs that we explicitly want to swallow.
     *
     * Protected (non-static) seam: tests subclass the user of the trait to
     * feed synthetic /proc content through this method without spawning
     * real subprocesses. Mirrors the override pattern of MacOsRssSampler.
     *
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    protected function readProcFile(string $path): ?string
    {
        try {
            $contents = @file_get_contents($path);
        } catch (\Throwable $e) {
            return null;
        }
        return $contents === false ? null : $contents;
    }
}
