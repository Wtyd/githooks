<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

/**
 * Interactive dashboard for parallel execution in TTY terminals.
 *
 * Shows three states per job:
 *   ⏺ job_name           — queued (waiting for slot)
 *   ⏳ job_name [3.2s]    — running (timer updates in place)
 *   ✓ job_name - OK/KO   — completed (permanent line)
 *
 * Uses ANSI cursor movement to update running timers in place.
 * Falls back to append-only output in non-TTY (CI) environments.
 *
 * Testing notes:
 *   - Emitted ANSI sequences (\e[33m, \e[90m, \e[42m…, \033[NA, \033[2K),
 *     state transitions (queued → running → done), redraw line counts, and
 *     the final collapse after flush() are covered by DashboardOutputHandlerTest
 *     via `$forceTty=true` + `php://memory` stream injection.
 *   - What those unit tests cannot cover, and which a human still validates
 *     through the qa-tester skill (TESTS.md V32-017):
 *     glyph rendering (⏳/⏺/✓/✗ look like emojis, not `?`), color perception,
 *     visual fluidity of the timer tick, and the perceived "redraw in place"
 *     of cursor movement on a real terminal.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) OutputHandler interface (8) + registerJobs + tick
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) FEAT-3 added the waiting-state lane (onJobWaiting +
 *   waitingLines + per-state filters) without breaking the single-class invariant: the dashboard owns
 *   the four lanes (completed / running / waiting / queued) and the cursor bookkeeping. Splitting would
 *   force coordinating the same internal state across collaborators.
 */
class DashboardOutputHandler implements OutputHandler
{
    /** @var string[] Job names in original order */
    private array $allJobs = [];

    /** @var array<string, float> jobName => start time */
    private array $running = [];

    /** @var array<string, string> jobName => formatted result line */
    private array $completed = [];

    /** @var string[] jobName list */
    private array $queued = [];

    /** @var array<string, string[]> FEAT-3: jobName → list of pending needs */
    private array $waiting = [];

    private int $dashboardLines = 0;

    private bool $isTty;

    /** @var resource */
    private $output;

    /** @var array<array{jobName: string, output: string}> */
    private array $errorBuffer = [];

    /**
     * @param resource|null $output Output stream. Defaults to `php://output` so
     *                              tests can capture writes via ob_start(); inject a
     *                              php://memory stream to capture without buffering.
     */
    public function __construct(?bool $forceTty = null, $output = null)
    {
        if ($output === null) {
            $default = fopen('php://output', 'w');
            $output = $default !== false ? $default : STDOUT;
        }
        $this->output = $output;
        $this->isTty = $forceTty ?? $this->detectTty();
    }

    public function onFlowStart(int $totalJobs): void
    {
    }

    /**
     * Register all jobs before execution starts.
     *
     * @param string[] $jobNames
     */
    public function registerJobs(array $jobNames): void
    {
        $this->allJobs = $jobNames;
        $this->queued = $jobNames;
        $this->running = [];
        $this->completed = [];

        if ($this->isTty) {
            $this->renderDashboard();
        }
    }

    public function onJobStart(string $jobName): void
    {
        $this->running[$jobName] = microtime(true);
        $this->queued = array_values(array_filter($this->queued, function (string $name) use ($jobName) {
            return $name !== $jobName;
        }));
        // FEAT-3: a job leaves the `waiting` set as soon as it actually starts.
        unset($this->waiting[$jobName]);

        if (!$this->isTty) {
            fwrite($this->output, "  ⏳ $jobName...\n");
        }
    }

    /**
     * FEAT-3: register a job as waiting for one or more `needs`. Renders as
     * `⏸ jobName (waiting yarn-install, ...)` in TTY mode. In non-TTY mode
     * we stay silent — the line would just be noise in CI logs.
     *
     * @param string[] $waitingFor
     */
    public function onJobWaiting(string $jobName, array $waitingFor): void
    {
        if (empty($waitingFor)) {
            unset($this->waiting[$jobName]);
        } else {
            $this->waiting[$jobName] = $waitingFor;
        }

        if ($this->isTty) {
            $this->renderDashboard();
        }
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) Dashboard doesn't show raw output */
    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
    {
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        unset($this->running[$jobName]);
        $this->completed[$jobName] = "  \e[42m\e[30m $jobName - OK. Time: $time \e[0m";

        if ($this->isTty) {
            $this->renderDashboard();
        } else {
            fwrite($this->output, "  $jobName - OK. Time: $time\n");
        }
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        unset($this->running[$jobName]);
        $this->completed[$jobName] = "  \e[41m\e[30m $jobName - KO. Time: $time \e[0m";
        $this->errorBuffer[] = ['jobName' => $jobName, 'output' => $output];

        if ($this->isTty) {
            $this->renderDashboard();
        } else {
            fwrite($this->output, "  $jobName - KO. Time: $time\n");
        }
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
        $this->queued = array_values(array_filter($this->queued, function (string $name) use ($jobName) {
            return $name !== $jobName;
        }));
        unset($this->waiting[$jobName]);
        $this->completed[$jobName] = "  ⏩ $jobName ($reason)";

        if ($this->isTty) {
            $this->renderDashboard();
        } else {
            fwrite($this->output, "  ⏩ $jobName ($reason)\n");
        }
    }

    public function onJobDryRun(string $jobName, string $command): void
    {
        fwrite($this->output, "  \e[36m$jobName\e[0m\n");
        fwrite($this->output, "     $command\n");
    }

    /**
     * Update running job timers. Called from the polling loop.
     */
    public function tick(): void
    {
        if (!$this->isTty || empty($this->running)) {
            return;
        }

        $this->renderDashboard();
    }

    public function flush(): void
    {
        if ($this->isTty && $this->dashboardLines > 0) {
            $this->clearDashboard();
            $this->printFinalResults();
        }

        if (!empty($this->errorBuffer)) {
            fwrite($this->output, "\n");
            foreach ($this->errorBuffer as $entry) {
                if (!empty(trim($entry['output']))) {
                    $this->printFramedError($entry['jobName'], $entry['output']);
                    fwrite($this->output, "\n");
                }
            }
            $this->errorBuffer = [];
        }
    }

    private function renderDashboard(): void
    {
        $this->clearDashboard();

        $lines = array_merge(
            $this->completedLines(),
            $this->runningLines(microtime(true)),
            $this->waitingLines(),
            $this->queuedLines()
        );

        foreach ($lines as $line) {
            fwrite($this->output, $line . "\n");
        }

        $this->dashboardLines = count($lines);
    }

    /** @return string[] */
    private function completedLines(): array
    {
        $lines = [];
        foreach ($this->allJobs as $jobName) {
            if (isset($this->completed[$jobName])) {
                $lines[] = $this->completed[$jobName];
            }
        }
        return $lines;
    }

    /** @return string[] */
    private function runningLines(float $now): array
    {
        $lines = [];
        foreach ($this->allJobs as $jobName) {
            if (isset($this->running[$jobName])) {
                $elapsed = $now - $this->running[$jobName];
                $timer = number_format($elapsed, 1) . 's';
                $lines[] = "  \e[33m⏳\e[0m $jobName [\e[33m{$timer}\e[0m]";
            }
        }
        return $lines;
    }

    /**
     * FEAT-3: queued jobs blocked by pending `needs`.
     * @return string[]
     */
    private function waitingLines(): array
    {
        $lines = [];
        foreach ($this->allJobs as $jobName) {
            if (isset($this->waiting[$jobName])) {
                $deps = implode(', ', $this->waiting[$jobName]);
                $lines[] = "  \e[90m⏸ $jobName (waiting $deps)\e[0m";
            }
        }
        return $lines;
    }

    /** @return string[] */
    private function queuedLines(): array
    {
        $lines = [];
        foreach ($this->queued as $jobName) {
            if (isset($this->waiting[$jobName])) {
                continue;  // already rendered by waitingLines()
            }
            $lines[] = "  \e[90m⏺ $jobName\e[0m";
        }
        return $lines;
    }

    private function clearDashboard(): void
    {
        if ($this->dashboardLines > 0) {
            // Move cursor up N lines and clear each
            fwrite($this->output, "\033[{$this->dashboardLines}A");
            for ($i = 0; $i < $this->dashboardLines; $i++) {
                fwrite($this->output, "\033[2K\n");
            }
            fwrite($this->output, "\033[{$this->dashboardLines}A");
        }
    }

    private function printFinalResults(): void
    {
        foreach ($this->allJobs as $jobName) {
            if (isset($this->completed[$jobName])) {
                fwrite($this->output, $this->completed[$jobName] . "\n");
            }
        }
    }

    private function printFramedError(string $jobName, string $output): void
    {
        $width = 79;
        fwrite($this->output, "  \e[31m┌" . str_repeat('─', $width) . "\e[0m\n");
        fwrite($this->output, "  \e[31m│\e[0m \e[1m$jobName\e[0m\n");
        fwrite($this->output, "  \e[31m│\e[0m\n");
        foreach (explode("\n", $output) as $line) {
            fwrite($this->output, "  \e[31m│\e[0m " . $line . "\n");
        }
        fwrite($this->output, "  \e[31m└" . str_repeat('─', $width) . "\e[0m\n");
    }

    private function detectTty(): bool
    {
        if (defined('STDOUT') && function_exists('posix_isatty')) {
            return posix_isatty(STDOUT);
        }

        return false;
    }
}
