<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Process\Process;
use Wtyd\GitHooks\Execution\Admission\AdmissionContext;
use Wtyd\GitHooks\Execution\Admission\AdmissionStrategy;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Manages a pool of concurrent processes. Without an AdmissionStrategy the
 * pool admits jobs in FIFO order up to maxProcesses (legacy 1D behaviour).
 * When a strategy is provided, the pool tracks live cores and memory
 * reservations so the strategy can decide which queued job, if any, fits.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor wires every optional dimension of the 2D allocator.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) FEAT-3 added the `needs` admission gate and propagation
 *   primitives (drainBlockedByFailedDeps, getWaitingByJob, classifyTerminalBlockers, formatPropagatedSkipReason).
 *   These belong here because they read the same internal state the pool already owns; splitting them across
 *   helpers would force exposing the internal sets.
 */
class ProcessPool
{
    private int $maxProcesses;

    private int $coresBudget;

    private ?AdmissionStrategy $strategy;

    private ?int $memoryBudget;

    /** @var array<string, int> jobName → cores it consumes when admitted */
    private array $coresByJob;

    /** @var array<string, ?int> jobName → memory reservation in MB (null when not declared in short form) */
    private array $memoryReserveByJob;

    /** @var array<string, array{process: ?Process, job: JobAbstract, start: float, result?: JobResult}> */
    private array $running = [];

    /** @var array<int, JobAbstract> Sequentially-indexed queue (reindexed on every modification). */
    private array $queue = [];

    private int $coresInUse = 0;

    private int $memoryReservedInUse = 0;

    /** @var array<string, string[]> FEAT-3: jobName → declared needs */
    private array $needsByJob = [];

    /** @var string[] FEAT-3: jobs whose process finished with success */
    private array $completedJobs = [];

    /** @var string[] FEAT-3: jobs whose process finished with failure */
    private array $failedJobs = [];

    /** @var string[] FEAT-3: jobs that were skipped (only-files, fail-fast, or upstream propagation) */
    private array $skippedJobs = [];

    /**
     * @param int $maxProcesses Slot limit: how many jobs may run in parallel.
     *                          Typically `ThreadBudgetPlan::getMaxParallelJobs()`.
     * @param int|null $coresBudget Total cores available for admission. Defaults
     *                              to $maxProcesses for back-compat, but callers
     *                              that derived $maxProcesses from a budget plan
     *                              MUST pass the original budget here — otherwise
     *                              an uncontrollable job whose cores cost exceeds
     *                              the slot limit would never fit and FifoAdmission
     *                              would spin forever.
     * @param array<string, int>  $coresByJob
     * @param array<string, ?int> $memoryReserveByJob
     */
    public function __construct(
        int $maxProcesses,
        ?AdmissionStrategy $strategy = null,
        ?int $memoryBudget = null,
        array $coresByJob = [],
        array $memoryReserveByJob = [],
        ?int $coresBudget = null
    ) {
        $this->maxProcesses = max(1, $maxProcesses);
        $this->coresBudget = max(1, $coresBudget ?? $this->maxProcesses);
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
     * @return array<string, array{process: ?Process, job: JobAbstract, start: float, result?: JobResult}>
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
     * @param array<string, array{process: ?Process, job: JobAbstract, start: float, result?: JobResult}> $started
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
     * @return array{process: ?Process, job: JobAbstract, start: float, result?: JobResult}
     */
    protected function startJob(JobAbstract $job): array
    {
        // FEAT-16: inline jobs (commit-msg) validate in-process — no shell, no
        // process. Run synchronously here and carry the result on the entry; the
        // pool then treats it as immediately completed (pollCompleted) and the
        // executor returns the stored result (collectResult). PAT-001.
        if ($job->isInline()) {
            return [
                'process' => null,
                'job'     => $job,
                'start'   => microtime(true),
                'result'  => $job->runInline(),
            ];
        }

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
        // coresLimit is the ABSOLUTE cores budget, not the slot limit.
        // Using $this->maxProcesses here would deadlock when a single
        // uncontrollable job (e.g. PHPStan with defaultThreads=4) reserves
        // more cores than the slot count: $coresFree could never reach the
        // job's cost, and FifoAdmission would spin forever.
        $coresLimit = $this->coresBudget;
        $coresFree = max(0, $coresLimit - $this->coresInUse);

        $memoryFree = null;
        if ($this->memoryBudget !== null) {
            $memoryFree = max(0, $this->memoryBudget - $this->memoryReservedInUse);
        }

        return new AdmissionContext(
            $coresFree,
            $memoryFree,
            $this->coresByJob,
            $this->memoryReserveByJob,
            $this->needsByJob,
            $this->completedJobs,
            $this->failedJobs,
            $this->skippedJobs
        );
    }

    /**
     * FEAT-3: register the dependency graph for the run. Called once after
     * `enqueue()` so the pool knows which `needs` each job declares.
     *
     * @param array<string, string[]> $needsByJob
     */
    public function setNeedsByJob(array $needsByJob): void
    {
        $this->needsByJob = $needsByJob;
    }

    /**
     * FEAT-3: notify the pool that a job finished. The pool keeps three
     * disjoint sets (completed / failed / skipped) used by the admission
     * gate and by `drainBlockedByFailedDeps()` to propagate.
     */
    public function notifyResult(string $jobName, bool $success, bool $skipped): void
    {
        if ($skipped) {
            $this->skippedJobs[] = $jobName;
            return;
        }
        if ($success) {
            $this->completedJobs[] = $jobName;
            return;
        }
        $this->failedJobs[] = $jobName;
    }

    /**
     * FEAT-3: remove from the queue every job whose `needs` include at least
     * one failed or skipped dependency, returning a `JobResult::skipped()` for
     * each. Mutates the pool state: the drained jobs are also added to the
     * skippedJobs set so their own dependents propagate downstream.
     *
     * @return JobResult[]
     */
    public function drainBlockedByFailedDeps(): array
    {
        $drained = [];
        $stillQueued = [];
        foreach ($this->queue as $job) {
            $blockers = $this->classifyTerminalBlockers($job->getName());
            if ($blockers === []) {
                $stillQueued[] = $job;
                continue;
            }
            $reason = self::formatPropagatedSkipReason($blockers);
            $drained[] = JobResult::skipped(
                $job->getName(),
                $job->getType(),
                $reason,
                []
            );
            $this->skippedJobs[] = $job->getName();
        }
        $this->queue = $stillQueued;
        return $drained;
    }

    /**
     * FEAT-3: jobs in the queue still waiting for one or more `needs` to
     * complete. Used by the executor to surface `onJobWaiting` events in the
     * dashboard. The result includes the still-pending blockers (failed and
     * skipped ones are filtered out — those go through drainBlockedByFailedDeps).
     *
     * @return array<string, string[]>  jobName → list of pending needs
     */
    public function getWaitingByJob(): array
    {
        $waiting = [];
        foreach ($this->queue as $job) {
            $name = $job->getName();
            $needs = $this->needsByJob[$name] ?? [];
            $pending = [];
            foreach ($needs as $dep) {
                if (
                    in_array($dep, $this->completedJobs, true)
                    || in_array($dep, $this->failedJobs, true)
                    || in_array($dep, $this->skippedJobs, true)
                ) {
                    continue;
                }
                $pending[] = $dep;
            }
            if ($pending !== []) {
                $waiting[$name] = $pending;
            }
        }
        return $waiting;
    }

    /**
     * @return array<string, string>  blockerName → 'failed' | 'skipped'
     */
    private function classifyTerminalBlockers(string $jobName): array
    {
        $needs = $this->needsByJob[$jobName] ?? [];
        $blockers = [];
        foreach ($needs as $dep) {
            if (in_array($dep, $this->failedJobs, true)) {
                $blockers[$dep] = 'failed';
            } elseif (in_array($dep, $this->skippedJobs, true)) {
                $blockers[$dep] = 'skipped';
            }
        }
        return $blockers;
    }

    /**
     * Build the human-readable propagated skip reason according to D2:
     *   - all failed:    "needs A, B failed"
     *   - all skipped:   "needs A, B were skipped"
     *   - mixed:         "needs A failed, B was skipped"
     *
     * @param array<string, string> $blockers
     */
    private static function formatPropagatedSkipReason(array $blockers): string
    {
        $failed = [];
        $skipped = [];
        foreach ($blockers as $name => $kind) {
            if ($kind === 'failed') {
                $failed[] = $name;
            } else {
                $skipped[] = $name;
            }
        }

        if ($failed === []) {
            $verb = count($skipped) === 1 ? 'was skipped' : 'were skipped';
            return 'needs ' . implode(', ', $skipped) . ' ' . $verb;
        }
        if ($skipped === []) {
            return 'needs ' . implode(', ', $failed) . ' failed';
        }
        $skipVerb = count($skipped) === 1 ? 'was skipped' : 'were skipped';
        return 'needs '
            . implode(', ', $failed) . ' failed, '
            . implode(', ', $skipped) . ' ' . $skipVerb;
    }

    /**
     * Check running processes for completion.
     * Returns completed entries, removes them from the running set and
     * releases their reserved cores and memory back to the pool.
     *
     * @return array<string, array{process: ?Process, job: JobAbstract, start: float, result?: JobResult}>
     */
    public function pollCompleted(): array
    {
        $completed = [];

        foreach ($this->running as $name => $entry) {
            // Inline jobs (process === null) ran synchronously at admission and
            // are complete the moment they enter the pool.
            if ($entry['process'] === null || !$entry['process']->isRunning()) {
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
     * @return array<string, array{process: ?Process, job: JobAbstract, start: float, result?: JobResult}>
     */
    public function terminateAll(): array
    {
        foreach ($this->running as $entry) {
            if ($entry['process'] !== null && $entry['process']->isRunning()) {
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
     * @return array<string, array{process: ?Process, job: JobAbstract, start: float, result?: JobResult}>
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
            if ($entry['process'] === null) {
                continue;
            }
            $pid = $entry['process']->getPid();
            if ($pid !== null) {
                $pids[$name] = $pid;
            }
        }
        return $pids;
    }
}
