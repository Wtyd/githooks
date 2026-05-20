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

    /**
     * FEAT-3: called when a job is queued but cannot start because one or
     * more declared `needs` are still pending. Only the parallel dashboard
     * pays attention to this signal today — every other handler trait-defaults
     * to no-op via `OutputHandlerWaitingNoOp`.
     *
     * @param string[] $waitingFor list of still-pending dependency names
     */
    public function onJobWaiting(string $jobName, array $waitingFor): void;

    public function onJobDryRun(string $jobName, string $command): void;

    /**
     * Called after all jobs have completed. Implementations that buffer
     * output (e.g. grouped errors) should print here.
     */
    public function flush(): void;
}
