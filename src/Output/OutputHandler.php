<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

/**
 * Controls how job execution results are presented to the user.
 *
 * FlowExecutor calls these methods as jobs complete; the implementation
 * decides whether to print immediately or buffer for later.
 */
interface OutputHandler
{
    /** Called when flow execution begins, with the total number of jobs. */
    public function onFlowStart(int $totalJobs): void;

    /** Called before a job starts executing (sequential mode only). */
    public function onJobStart(string $jobName): void;

    /** Called with real-time output chunks during job execution (sequential mode only). */
    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void;

    public function onJobSuccess(string $jobName, string $time): void;

    public function onJobError(string $jobName, string $time, string $output): void;

    public function onJobSkipped(string $jobName, string $reason): void;

    public function onJobDryRun(string $jobName, string $command): void;

    /**
     * Called after all jobs have completed. Implementations that buffer
     * output (e.g. grouped errors) should print here.
     */
    public function flush(): void;
}
