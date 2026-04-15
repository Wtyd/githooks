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
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) OutputHandler interface (8) + registerJobs + tick
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

    private int $dashboardLines = 0;

    private bool $isTty;

    /** @var array<array{jobName: string, output: string}> */
    private array $errorBuffer = [];

    public function __construct(?bool $forceTty = null)
    {
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

        if (!$this->isTty) {
            echo "  ⏳ $jobName...\n";
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
            echo "  $jobName - OK. Time: $time\n";
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
            echo "  $jobName - KO. Time: $time\n";
        }
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
        $this->queued = array_values(array_filter($this->queued, function (string $name) use ($jobName) {
            return $name !== $jobName;
        }));
        $this->completed[$jobName] = "  ⏩ $jobName ($reason)";

        if (!$this->isTty) {
            echo "  ⏩ $jobName ($reason)\n";
        }
    }

    public function onJobDryRun(string $jobName, string $command): void
    {
        echo "  \e[36m$jobName\e[0m\n";
        echo "     $command\n";
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
            echo "\n";
            foreach ($this->errorBuffer as $entry) {
                if (!empty(trim($entry['output']))) {
                    $this->printFramedError($entry['jobName'], $entry['output']);
                    echo "\n";
                }
            }
            $this->errorBuffer = [];
        }
    }

    private function renderDashboard(): void
    {
        $this->clearDashboard();

        $lines = [];
        $now = microtime(true);

        // Completed jobs
        foreach ($this->allJobs as $jobName) {
            if (isset($this->completed[$jobName])) {
                $lines[] = $this->completed[$jobName];
            }
        }

        // Running jobs with timer
        foreach ($this->allJobs as $jobName) {
            if (isset($this->running[$jobName])) {
                $elapsed = $now - $this->running[$jobName];
                $timer = number_format($elapsed, 1) . 's';
                $lines[] = "  \e[33m⏳\e[0m $jobName [\e[33m{$timer}\e[0m]";
            }
        }

        // Queued jobs
        foreach ($this->queued as $jobName) {
            $lines[] = "  \e[90m⏺ $jobName\e[0m";
        }

        foreach ($lines as $line) {
            echo $line . "\n";
        }

        $this->dashboardLines = count($lines);
    }

    private function clearDashboard(): void
    {
        if ($this->dashboardLines > 0) {
            // Move cursor up N lines and clear each
            echo "\033[{$this->dashboardLines}A";
            for ($i = 0; $i < $this->dashboardLines; $i++) {
                echo "\033[2K\n";
            }
            echo "\033[{$this->dashboardLines}A";
        }
    }

    private function printFinalResults(): void
    {
        foreach ($this->allJobs as $jobName) {
            if (isset($this->completed[$jobName])) {
                echo $this->completed[$jobName] . "\n";
            }
        }
    }

    private function printFramedError(string $jobName, string $output): void
    {
        $width = 79;
        echo "  \e[31m┌" . str_repeat('─', $width) . "\e[0m\n";
        echo "  \e[31m│\e[0m \e[1m$jobName\e[0m\n";
        echo "  \e[31m│\e[0m\n";
        foreach (explode("\n", $output) as $line) {
            echo "  \e[31m│\e[0m " . $line . "\n";
        }
        echo "  \e[31m└" . str_repeat('─', $width) . "\e[0m\n";
    }

    private function detectTty(): bool
    {
        if (defined('STDOUT') && function_exists('posix_isatty')) {
            return posix_isatty(STDOUT);
        }

        return false;
    }
}
