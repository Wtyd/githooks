<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Output\OutputHandler;

/**
 * Base double with every OutputHandler hook implemented as a no-op.
 *
 * Subclasses override only the hooks they care about, removing the 9 stub
 * methods that anonymous-class doubles repeat in every test.
 */
abstract class NoOpOutputHandler implements OutputHandler
{
    public function onFlowStart(int $totalJobs): void
    {
    }

    public function onJobStart(string $jobName): void
    {
    }

    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
    {
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
    }

    /**
     * @param string[] $waitingFor
     */
    public function onJobWaiting(string $jobName, array $waitingFor): void
    {
    }

    public function onJobDryRun(string $jobName, string $command): void
    {
    }

    public function flush(): void
    {
    }
}
