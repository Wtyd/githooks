<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\CI;

use Wtyd\GitHooks\Output\OutputHandler;

/**
 * Base decorator for CI-specific output annotations.
 * Delegates all calls to the inner handler and adds CI annotations.
 */
abstract class CIOutputDecorator implements OutputHandler
{
    protected OutputHandler $inner;

    public function __construct(OutputHandler $inner)
    {
        $this->inner = $inner;
    }

    public function onFlowStart(int $totalJobs): void
    {
        $this->inner->onFlowStart($totalJobs);
    }

    public function onJobStart(string $jobName): void
    {
        $this->inner->onJobStart($jobName);
    }

    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
    {
        $this->inner->onJobOutput($jobName, $chunk, $isStderr);
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        $this->inner->onJobSuccess($jobName, $time);
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        $this->inner->onJobError($jobName, $time, $output);
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
        $this->inner->onJobSkipped($jobName, $reason);
    }

    public function onJobDryRun(string $jobName, string $command): void
    {
        $this->inner->onJobDryRun($jobName, $command);
    }

    public function flush(): void
    {
        $this->inner->flush();
    }
}
