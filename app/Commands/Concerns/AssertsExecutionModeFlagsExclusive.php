<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Execution\ExecutionMode;

/**
 * Shared mutual-exclusion check for the four set-defining flags:
 *
 *   --fast | --fast-branch | --fast-dirty | --files | --files-from
 *
 * Each defines a different file set; combining two has no semantics. Per
 * FEAT-13 we reject the combination explicitly at parse time and emit a
 * deterministic error so users (and the system tests in
 * tests/System/Commands/{Flow,Job}Command/FastDirtyTest.php) can rely on
 * the wording.
 *
 * The trait is consumed by FlowCommand, JobCommand and FlowsCommand. It
 * sits next to {@see ResolvesInputFiles} (which handles --files/--files-from
 * resolution) and only validates flag pairs — it doesn't resolve any mode.
 */
trait AssertsExecutionModeFlagsExclusive
{
    /**
     * Returns false (and writes the error to the console) when any pair of
     * set-defining flags is present simultaneously. Returns true otherwise.
     */
    private function assertExecutionModeFlagsExclusive(): bool
    {
        $present = [];
        // --fast-dirty is checked first so the error message names it as the
        // primary flag — the FEAT-13 contract is "you used --fast-dirty AND
        // one of {fast, fast-branch, files, files-from}; pick one". The other
        // pairwise conflicts among the pre-existing flags fall back to
        // declaration order (their tests don't depend on it).
        if ($this->option('fast-dirty')) {
            $present[] = '--fast-dirty';
        }
        if ($this->option('fast')) {
            $present[] = '--fast';
        }
        if ($this->option('fast-branch')) {
            $present[] = '--fast-branch';
        }
        if (is_string($this->option('files')) && $this->option('files') !== '') {
            $present[] = '--files';
        }
        if (is_string($this->option('files-from')) && $this->option('files-from') !== '') {
            $present[] = '--files-from';
        }

        if (count($present) < 2) {
            return true;
        }

        // Stable ordering so the message is deterministic regardless of
        // option order on the command line. We surface the *first two*
        // conflicting flags only; if the user passed three, fixing one
        // pair at a time keeps the feedback loop tight.
        $this->error($present[0] . ' and ' . $present[1] . ' are mutually exclusive');
        return false;
    }

    /**
     * Translate the chosen mode flag (`--fast`, `--fast-branch`, `--fast-dirty`)
     * into the corresponding {@see ExecutionMode} string, or null when no
     * mode flag is present. Caller responsibility: invoke
     * {@see assertExecutionModeFlagsExclusive()} first so combinations are
     * rejected before this returns.
     */
    private function resolveInvocationModeFromCli(): ?string
    {
        if ($this->option('fast')) {
            return ExecutionMode::FAST;
        }
        if ($this->option('fast-branch')) {
            return ExecutionMode::FAST_BRANCH;
        }
        if ($this->option('fast-dirty')) {
            return ExecutionMode::FAST_DIRTY;
        }
        return null;
    }
}
