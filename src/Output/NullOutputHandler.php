<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

/**
 * Silent output handler for structured formats (JSON, JUnit).
 * Captures nothing — the final output comes from a ResultFormatter.
 */
class NullOutputHandler implements OutputHandler
{
    public function onJobSuccess(string $jobName, string $time): void
    {
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
    }

    public function flush(): void
    {
    }
}
