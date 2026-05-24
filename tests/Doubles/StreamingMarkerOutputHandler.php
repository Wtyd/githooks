<?php

declare(strict_types=1);

namespace Tests\Doubles;

/**
 * Inner OutputHandler that streams human-readable lines around each hook
 * (start banner, raw chunk passthrough, close banner). Mirrors what a
 * streaming/dashboard handler would write so a wrapping decorator can be
 * tested against realistic content (multi-line, with newlines, sometimes
 * without).
 */
class StreamingMarkerOutputHandler extends NoOpOutputHandler
{
    public function onJobStart(string $jobName): void
    {
        echo "  --- $jobName ---\n";
    }

    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
    {
        echo $chunk;
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        echo "  $jobName OK $time\n";
    }
}
