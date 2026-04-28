<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Process\Process;
use Wtyd\GitHooks\Execution\Admission\AdmissionContext;
use Wtyd\GitHooks\Execution\Admission\AdmissionStrategy;
use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Manages a pool of concurrent processes. Without an AdmissionStrategy the
 * pool admits jobs in FIFO order up to maxProcesses (legacy 1D behaviour).
 * When a strategy is provided, the pool tracks live cores and memory
 * reservations so the strategy can decide which queued job, if any, fits.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor wires every optional dimension of the 2D allocator.
 */
class ProcessPool
{
    private int $maxProcesses;

    private ?AdmissionStrategy $strategy;

    private ?int $memoryBudget;

    /** @var array<string, int> jobName → cores it consumes when admitted */
    private array $coresByJob;

    /** @var array<string, ?int> jobName → memory reservation in MB (null when not declared in short form) */
    private array $memoryReserveByJob;

    /** @var array<string, array{process: Process, job: JobAbstract, start: float}> */
    private array $running = [];

    /** @var array<int, JobAbstract> Sequentially-indexed queue (reindexed on every modification). */
    private array $queue = [];

    private int $coresInUse = 0;

    private int $memoryReservedInUse = 0;

    /**
     * @param array<string, int>  $coresByJob
     * @param array<string, ?int> $memoryReserveByJob
     */
    public function __construct(
        int $maxProcesses,
        ?AdmissionStrategy $strategy = null,
        ?int $memoryBudget = null,
        array $coresByJob = [],
        array $memoryReserveByJob = []
    ) {
        $this->maxProcesses = max(1, $maxProcesses);
        $this->strategy = $strategy;
        $this->memoryBudget = $memoryBudget;
        $this->coresByJob = $coresByJob;
        $this->memoryReserveByJob = $memoryReserveByJob;
    }

    /**
     * Enqueue jobs to be processed. Indices are reset to be 0..N-1 so the
     * AdmissionStrategy gets a deterministic key space.
     *
     * @param JobAbstract[] $jobs
     */
    public function enqueue(array $jobs): void
    {
        $this->queue = array_values($jobs);
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

        if ($this->strategy === null) {
            $this->fillPoolFifo($started);
            return $started;
        }

        $running = count($this->running);
        while (!empty($this->queue) && $running < $this->maxProcesses) {
            $context = $this->buildAdmissionContext();
            $index = $this->strategy->pickNext($this->queue, $context);
            if ($index === null) {
                break;
            }

            $picked = array_splice($this->queue, $index, 1)[0];
            $entry = $this->startJob($picked);
            $this->running[$picked->getName()] = $entry;
            $started[$picked->getName()] = $entry;

            $this->coresInUse += $this->coresByJob[$picked->getName()] ?? 1;
            $this->memoryReservedInUse += (int) ($this->memoryReserveByJob[$picked->getName()] ?? 0);
            $running++;
        }

        return $started;
    }

    /**
     * @param array<string, array{process: Process, job: JobAbstract, start: float}> $started
     */
    private function fillPoolFifo(array &$started): void
    {
        $runningCount = count($this->running);
        while (!empty($this->queue) && $runningCount < $this->maxProcesses) {
            $job = array_shift($this->queue);
            $entry = $this->startJob($job);
            $this->running[$job->getName()] = $entry;
            $started[$job->getName()] = $entry;
            $runningCount++;
        }
    }

    /**
     * @return array{process: Process, job: JobAbstract, start: float}
     */
    private function startJob(JobAbstract $job): array
    {
        $command = $job->buildCommand();
        $process = Process::fromShellCommandLine($command);
        // Disable Symfony's 60s default: QA jobs (phpstan, phpunit over large
        // codebases) can legitimately run longer. Removing this line makes
        // long jobs die silently with ProcessTimedOutException.
        $process->setTimeout(null);
        $process->start();

        return [
            'process' => $process,
            'job'     => $job,
            'start'   => microtime(true),
        ];
    }

    private function buildAdmissionContext(): AdmissionContext
    {
        $coresLimit = $this->maxProcesses;
        $coresFree = max(0, $coresLimit - $this->coresInUse);
        $coresFree = min($coresFree, $coresLimit - count($this->running));

        $memoryFree = null;
        if ($this->memoryBudget !== null) {
            $memoryFree = max(0, $this->memoryBudget - $this->memoryReservedInUse);
        }

        return new AdmissionContext(
            $coresFree,
            $memoryFree,
            $this->coresByJob,
            $this->memoryReserveByJob
        );
    }

    /**
     * Check running processes for completion.
     * Returns completed entries, removes them from the running set and
     * releases their reserved cores and memory back to the pool.
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
            $this->releaseReservation($name);
            unset($this->running[$name]);
        }

        return $completed;
    }

    private function releaseReservation(string $jobName): void
    {
        $this->coresInUse = max(0, $this->coresInUse - ($this->coresByJob[$jobName] ?? 1));
        $reserve = (int) ($this->memoryReserveByJob[$jobName] ?? 0);
        $this->memoryReservedInUse = max(0, $this->memoryReservedInUse - $reserve);
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
        $this->coresInUse = 0;
        $this->memoryReservedInUse = 0;

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

    /**
     * Live PIDs of running processes, keyed by job name. Symfony's
     * Process::getPid() may return null briefly between start() and the
     * actual fork — those entries are filtered out (the sampler will pick
     * them up in the next tick).
     *
     * @return array<string, int>
     */
    public function getRunningPids(): array
    {
        $pids = [];
        foreach ($this->running as $name => $entry) {
            $pid = $entry['process']->getPid();
            if ($pid !== null) {
                $pids[$name] = $pid;
            }
        }
        return $pids;
    }
}
