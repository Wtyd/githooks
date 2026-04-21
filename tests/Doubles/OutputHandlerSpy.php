<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Output\OutputHandler;

/**
 * OutputHandler double that records every event in public lists, enabling
 * strict equality/order assertions over the event stream emitted by
 * FlowExecutor during a flow run.
 *
 * Prefer this over `Mockery::spy(OutputHandler::class)` when the test
 * needs to reason about event order or the exact argument values — the
 * interface has eight methods and mockery-style expectations become
 * brittle.
 */
class OutputHandlerSpy implements OutputHandler
{
    /** @var int[] sequence of totalJobs values passed to onFlowStart */
    public array $flowStarts = [];

    /** @var string[] job names in the exact order they were started */
    public array $startedJobs = [];

    /** @var array<int, array{job: string, chunk: string, isStderr: bool}> */
    public array $outputs = [];

    /** @var array<int, array{job: string, time: string}> */
    public array $successfulJobs = [];

    /** @var array<int, array{job: string, time: string, output: string}> */
    public array $errorJobs = [];

    /** @var array<int, array{job: string, reason: string}> */
    public array $skippedJobs = [];

    /** @var array<int, array{job: string, command: string}> */
    public array $dryRunJobs = [];

    public int $flushCount = 0;

    public function onFlowStart(int $totalJobs): void
    {
        $this->flowStarts[] = $totalJobs;
    }

    public function onJobStart(string $jobName): void
    {
        $this->startedJobs[] = $jobName;
    }

    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
    {
        $this->outputs[] = ['job' => $jobName, 'chunk' => $chunk, 'isStderr' => $isStderr];
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        $this->successfulJobs[] = ['job' => $jobName, 'time' => $time];
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        $this->errorJobs[] = ['job' => $jobName, 'time' => $time, 'output' => $output];
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
        $this->skippedJobs[] = ['job' => $jobName, 'reason' => $reason];
    }

    public function onJobDryRun(string $jobName, string $command): void
    {
        $this->dryRunJobs[] = ['job' => $jobName, 'command' => $command];
    }

    public function flush(): void
    {
        $this->flushCount++;
    }

    /**
     * Return the list of job names that received any stderr output.
     *
     * @return string[]
     */
    public function jobNamesWithStderrOutput(): array
    {
        $names = [];
        foreach ($this->outputs as $entry) {
            if ($entry['isStderr'] && !in_array($entry['job'], $names, true)) {
                $names[] = $entry['job'];
            }
        }
        return $names;
    }

    /**
     * Return the list of skipped job names.
     *
     * @return string[]
     */
    public function skippedJobNames(): array
    {
        return array_map(function (array $entry): string {
            return $entry['job'];
        }, $this->skippedJobs);
    }
}
