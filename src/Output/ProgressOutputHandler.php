<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

/**
 * Progress output on stderr for structured formats (json, junit, codeclimate, sarif).
 *
 * All progress goes to stderr so stdout stays clean for structured data.
 * The handler is silent when the stream is not a TTY (pipe, CI, agent) unless
 * force-enabled — consumers parsing stdout get clean output without `2>/dev/null`.
 * Pass `$forceEnabled = true` (wired to `--show-progress` in FormatsOutput) to
 * emit progress regardless of TTY detection.
 */
class ProgressOutputHandler implements OutputHandler
{
    /** @var resource */
    private $stream;

    private bool $enabled;

    private int $total = 0;

    private int $completed = 0;

    /**
     * @param resource $stream Defaults to STDERR
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) forceEnabled is a configuration toggle, not a branching flag
     */
    public function __construct($stream = null, bool $forceEnabled = false)
    {
        if ($stream !== null) {
            $this->stream = $stream;
        } elseif (defined('STDERR')) {
            $this->stream = STDERR;
        } else {
            /** @var resource */
            $fallback = fopen('php://stderr', 'w');
            $this->stream = $fallback;
        }

        $this->enabled = $forceEnabled || stream_isatty($this->stream);
    }

    public function onFlowStart(int $totalJobs): void
    {
        $this->total = $totalJobs;
        $this->completed = 0;
    }

    public function onJobStart(string $jobName): void
    {
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) Progress handler discards raw output */
    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
    {
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        $this->completed++;
        $this->write("  \e[32mOK\e[0m $jobName ($time)  [{$this->completed}/{$this->total}]");
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        $this->completed++;
        $this->write("  \e[31mKO\e[0m $jobName ($time)  [{$this->completed}/{$this->total}]");
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
        $this->completed++;
        $this->write("  \e[33mSKIP\e[0m $jobName ($reason)  [{$this->completed}/{$this->total}]");
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) No dry-run output on stderr */
    public function onJobDryRun(string $jobName, string $command): void
    {
    }

    public function flush(): void
    {
        $this->write("Done. {$this->completed}/{$this->total} completed.");
    }

    private function write(string $message): void
    {
        if (!$this->enabled) {
            return;
        }
        fwrite($this->stream, $message . "\n");
    }
}
