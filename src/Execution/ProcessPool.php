<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Process\Process;
use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Manages a pool of concurrent processes with a configurable max size.
 * Handles process start, completion detection, and forced termination.
 */
class ProcessPool
{
    private int $maxProcesses;

    /** @var array<string, array{process: Process, job: JobAbstract, start: float}> */
    private array $running = [];

    /** @var JobAbstract[] */
    private array $queue = [];

    public function __construct(int $maxProcesses)
    {
        $this->maxProcesses = max(1, $maxProcesses);
    }

    /**
     * Enqueue jobs to be processed.
     *
     * @param JobAbstract[] $jobs
     */
    public function enqueue(array $jobs): void
    {
        $this->queue = $jobs;
    }

    /**
     * Try to fill available pool slots with queued jobs.
     * Returns the newly started entries.
     *
     * @return array<string, array{process: Process, job: JobAbstract, start: float}>
     */
    public function fillPool(): array
    {
        $started = [];

        $runningCount = count($this->running);
        while (!empty($this->queue) && $runningCount < $this->maxProcesses) {
            $job = array_shift($this->queue);
            $command = $job->buildCommand();
            $process = Process::fromShellCommandLine($command);
            $process->setTimeout(null);
            $process->start();

            $entry = [
                'process' => $process,
                'job'     => $job,
                'start'   => microtime(true),
            ];

            $this->running[$job->getName()] = $entry;
            $started[$job->getName()] = $entry;
            $runningCount++;
        }

        return $started;
    }

    /**
     * Check running processes for completion.
     * Returns completed entries and removes them from the running set.
     *
     * @return array<string, array{process: Process, job: JobAbstract, start: float}>
     */
    public function pollCompleted(): array
    {
        $completed = [];

        foreach ($this->running as $name => $entry) {
            if (!$entry['process']->isRunning()) {
                $completed[$name] = $entry;
            }
        }

        foreach (array_keys($completed) as $name) {
            unset($this->running[$name]);
        }

        return $completed;
    }

    /**
     * Terminate all running processes and return their entries.
     *
     * @return array<string, array{process: Process, job: JobAbstract, start: float}>
     */
    public function terminateAll(): array
    {
        foreach ($this->running as $entry) {
            if ($entry['process']->isRunning()) {
                $entry['process']->stop(0);
            }
        }

        $terminated = $this->running;
        $this->running = [];

        return $terminated;
    }

    /**
     * Get remaining queued jobs (not yet started).
     *
     * @return JobAbstract[]
     */
    public function getQueuedJobs(): array
    {
        return $this->queue;
    }

    /**
     * Clear the queue (e.g. after fail-fast).
     */
    public function clearQueue(): void
    {
        $this->queue = [];
    }

    public function hasWork(): bool
    {
        return !empty($this->queue) || !empty($this->running);
    }

    public function hasRunning(): bool
    {
        return !empty($this->running);
    }

    /**
     * @return array<string, array{process: Process, job: JobAbstract, start: float}>
     */
    public function getRunning(): array
    {
        return $this->running;
    }
}
