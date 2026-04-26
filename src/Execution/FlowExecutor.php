<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Process\Process;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Output\DashboardOutputHandler;
use Wtyd\GitHooks\Output\OutputHandler;
use Wtyd\GitHooks\Execution\ThreadBudgetAllocator;
use Wtyd\GitHooks\Utils\GitStagerInterface;

/**
 * Orchestrates flow execution: runs jobs respecting processes limit and fail-fast.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Orchestrator with sequential+parallel+threading+restaging
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Top-level coordinator: by design touches every Execution and Output collaborator
 */
class FlowExecutor
{
    private OutputHandler $outputHandler;

    private ?GitStagerInterface $gitStager;

    private bool $structuredFormat = false;

    private int $peakEstimatedThreads = 0;

    /** @var array<string, int> jobName => estimated threads */
    private array $threadAllocations = [];

    private ?InputFilesResolution $inputFilesContext = null;

    public function __construct(OutputHandler $outputHandler, ?GitStagerInterface $gitStager = null)
    {
        $this->outputHandler = $outputHandler;
        $this->gitStager = $gitStager;
    }

    public function getOutputHandler(): OutputHandler
    {
        return $this->outputHandler;
    }

    public function setOutputHandler(OutputHandler $outputHandler): void
    {
        $this->outputHandler = $outputHandler;
    }

    public function setStructuredFormat(bool $structured): void
    {
        $this->structuredFormat = $structured;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Orchestrates thread budget + sequential/parallel dispatch
     * @SuppressWarnings(PHPMD.NPathComplexity) Structured format + thread budget + sequential/parallel paths
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) dry-run is a simple mode toggle, not an SRP violation
     */
    public function execute(FlowPlan $plan, bool $dryRun = false): FlowResult
    {
        $start = microtime(true);
        $maxProcesses = $plan->getOptions()->getProcesses();
        $failFast = $plan->getOptions()->isFailFast();
        $jobs = $plan->getJobs();
        $context = $plan->getContext();
        $this->inputFilesContext = $plan->getInputFiles();

        foreach ($jobs as $job) {
            $this->propagateContext($job, $context);
        }

        if ($this->structuredFormat) {
            foreach ($jobs as $job) {
                $job->applyStructuredOutputFormat();
            }
        }

        $maxProcesses = $this->resolveThreadBudget($jobs, $maxProcesses);

        $inputFiles = $this->inputFilesContext;

        if ($dryRun) {
            return $this->executeDryRun($plan->getFlowName(), $jobs, $plan->getExecutionMode(), $inputFiles);
        }

        // Total = executable jobs + plan-level skipped jobs (fast-mode filtering etc.).
        // Both contribute events to the handler, so the denominator must cover both.
        $this->outputHandler->onFlowStart(count($jobs) + count($plan->getSkippedJobs()));

        if ($maxProcesses <= 1 || count($jobs) <= 1) {
            $results = $this->executeSequential($jobs, $failFast);
        } else {
            $results = $this->executeParallel($jobs, $maxProcesses, $failFast);
        }

        // Include skipped jobs from plan (fast mode filtering / files mode mismatch)
        foreach ($plan->getSkippedJobs() as $name => $info) {
            $this->outputHandler->onJobSkipped($name, $info['reason']);
            $skippedResult = JobResult::skipped($name, $info['type'], $info['reason'], $info['paths']);
            if ($inputFiles !== null && ($info['accelerable'] ?? false)) {
                $skippedResult = $skippedResult->withInputFiles(
                    new InputFilesPerJob([], $inputFiles->getTotalValid())
                );
            }
            $results[] = $skippedResult;
        }

        $this->outputHandler->flush();

        $elapsed = microtime(true) - $start;
        $totalTime = number_format($elapsed, 2) . 's';
        $budget = $plan->getOptions()->getProcesses();

        return new FlowResult(
            $plan->getFlowName(),
            $results,
            $totalTime,
            $this->peakEstimatedThreads,
            $budget,
            $plan->getExecutionMode(),
            $inputFiles
        );
    }

    /**
     * @param JobAbstract[] $jobs
     */
    private function executeDryRun(
        string $flowName,
        array $jobs,
        string $executionMode,
        ?InputFilesResolution $inputFiles
    ): FlowResult {
        $results = [];
        foreach ($jobs as $job) {
            $command = $job->buildCommand();
            $this->outputHandler->onJobDryRun($job->getDisplayName(), $command);
            $results[] = new JobResult(
                $job->getName(),
                true,
                '',
                '0ms',
                false,
                $command,
                $job->getType(),
                null,
                $job->getConfiguredPaths(),
                false,
                null,
                null,
                $this->buildPerJobInputFiles($job)
            );
        }
        return new FlowResult($flowName, $results, '0ms', 0, 0, $executionMode, $inputFiles);
    }

    /**
     * Distribute the thread budget across jobs and return the effective max
     * parallel jobs. Runs before dry-run so printed commands reflect the
     * allocations (cores override + reparto). Resets internal counters.
     *
     * @param JobAbstract[] $jobs
     */
    private function resolveThreadBudget(array $jobs, int $maxProcesses): int
    {
        $this->peakEstimatedThreads = 0;
        $this->threadAllocations = [];

        $this->applyExplicitCoresOverrides($jobs);

        if ($maxProcesses > 1 && count($jobs) > 1) {
            return $this->allocateParallelBudget($jobs, $maxProcesses);
        }

        $this->fillSequentialAllocations($jobs);
        return $maxProcesses;
    }

    /**
     * Apply explicit `cores: N` overrides — always honoured, regardless of mode.
     *
     * @param JobAbstract[] $jobs
     */
    private function applyExplicitCoresOverrides(array $jobs): void
    {
        foreach ($jobs as $job) {
            $override = $job->getCoresOverride();
            if ($override !== null) {
                $job->applyThreadLimit($override);
                $this->threadAllocations[$job->getName()] = $override;
            }
        }
    }

    /**
     * Allocate the parallel thread budget via ThreadBudgetAllocator and
     * return the effective number of parallel jobs.
     *
     * @param JobAbstract[] $jobs
     */
    private function allocateParallelBudget(array $jobs, int $maxProcesses): int
    {
        $budgetPlan = (new ThreadBudgetAllocator())->allocate($maxProcesses, $jobs);

        foreach ($jobs as $job) {
            if (isset($this->threadAllocations[$job->getName()])) {
                continue;
            }
            $allocation = $budgetPlan->getAllocation($job->getName());
            if ($allocation !== null) {
                $job->applyThreadLimit($allocation);
                $this->threadAllocations[$job->getName()] = $allocation;
            }
        }

        return $budgetPlan->getMaxParallelJobs();
    }

    /**
     * Sequential mode allocation — each job uses its capability default
     * unless an explicit override is already set.
     *
     * @param JobAbstract[] $jobs
     */
    private function fillSequentialAllocations(array $jobs): void
    {
        foreach ($jobs as $job) {
            if (isset($this->threadAllocations[$job->getName()])) {
                continue;
            }
            $cap = $job->getThreadCapability();
            $this->threadAllocations[$job->getName()] = $cap !== null ? $cap->getDefaultThreads() : 1;
        }
    }

    /**
     * Build the per-job input-files slice for accelerable jobs only. Returns null
     * when there is no inputFiles context (regular --fast/--fast-branch/full)
     * or when the job is non-accelerable (REQ-008/REQ-009).
     */
    private function buildPerJobInputFiles(JobAbstract $job): ?InputFilesPerJob
    {
        if ($this->inputFilesContext === null) {
            return null;
        }
        if (!$job->isAccelerable()) {
            return null;
        }
        // After FlowPreparer::filterJobForMode(), getConfiguredPaths() returns the
        // matched subset of input files for this job (or empty if it was skipped).
        return new InputFilesPerJob(
            $job->getConfiguredPaths(),
            $this->inputFilesContext->getTotalValid()
        );
    }


    /**
     * @param JobAbstract[] $jobs
     * @return JobResult[]
     */
    private function executeSequential(array $jobs, bool $failFast): array
    {
        $results = [];

        foreach ($jobs as $job) {
            $threads = $this->threadAllocations[$job->getName()] ?? 1;
            $this->peakEstimatedThreads = max($this->peakEstimatedThreads, $threads);

            $result = $this->runJob($job);
            $results[] = $result;

            if ($failFast && !$result->isSuccess()) {
                foreach ($this->reportSkipped($jobs, $job) as $skippedResult) {
                    $results[] = $skippedResult;
                }
                break;
            }
        }

        return $results;
    }

    /**
     * @param JobAbstract[] $jobs
     * @return JobResult[]
     */
    /**
     * @param JobAbstract[] $jobs
     * @return JobResult[]
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Orchestrates pool + dashboard + fail-fast
     * @SuppressWarnings(PHPMD.NPathComplexity) Dashboard tick + pool fill + fail-fast paths
     */
    private function executeParallel(array $jobs, int $maxProcesses, bool $failFast): array
    {
        $results = [];
        $pool = new ProcessPool($maxProcesses);
        $pool->enqueue($jobs);
        $failFastTriggered = false;
        $dashboard = $this->outputHandler instanceof DashboardOutputHandler ? $this->outputHandler : null;
        $lastTick = microtime(true);

        // Register all job names for dashboard display
        if ($dashboard !== null) {
            $names = array_map(function (JobAbstract $job): string {
                return $job->getDisplayName();
            }, $jobs);
            $dashboard->registerJobs($names);
        }

        while ($pool->hasWork()) {
            if (!$failFastTriggered) {
                $started = $pool->fillPool();
                $this->updatePeakThreads($pool->getRunning());

                // Notify handler about newly started jobs
                foreach ($started as $entry) {
                    $this->outputHandler->onJobStart($entry['job']->getDisplayName());
                }
            }

            foreach ($pool->pollCompleted() as $entry) {
                $result = $this->collectResult($entry);
                $results[] = $result;

                if ($failFast && !$result->isSuccess()) {
                    $failFastTriggered = true;
                    foreach ($pool->terminateAll() as $terminatedEntry) {
                        $results[] = $this->collectResult($terminatedEntry);
                    }
                    foreach ($pool->getQueuedJobs() as $skippedJob) {
                        $this->outputHandler->onJobSkipped($skippedJob->getDisplayName(), 'skipped by fail-fast');
                        $results[] = $this->attachInputFilesIfApplicable(
                            JobResult::skipped(
                                $skippedJob->getName(),
                                $skippedJob->getType(),
                                'skipped by fail-fast',
                                $skippedJob->getConfiguredPaths()
                            ),
                            $skippedJob
                        );
                    }
                    $pool->clearQueue();
                    break;
                }
            }

            if ($pool->hasRunning()) {
                // Update dashboard timer at ~200ms intervals
                $now = microtime(true);
                if ($dashboard !== null && ($now - $lastTick) >= 0.2) {
                    $dashboard->tick();
                    $lastTick = $now;
                }
                usleep(10000);
            }
        }

        return $results;
    }

    private function propagateContext(JobAbstract $job, ?ExecutionContext $context): void
    {
        if ($context !== null) {
            $job->setExecutionContext($context);
        }
    }

    private function runJob(JobAbstract $job): JobResult
    {
        $command = $job->buildCommand();
        $start = microtime(true);

        $this->outputHandler->onJobStart($job->getDisplayName());

        $process = Process::fromShellCommandLine($command);
        $process->setTimeout(null);

        $displayName = $job->getDisplayName();
        $handler = $this->outputHandler;

        $process->run(function (string $type, string $buffer) use ($displayName, $handler): void {
            $handler->onJobOutput($displayName, $buffer, $type === Process::ERR);
        });

        return $this->buildResult($job, $process, $start);
    }

    /**
     * @param array<string, array{process: Process, job: JobAbstract, start: float}> $running
     */
    private function updatePeakThreads(array $running): void
    {
        $currentThreads = 0;
        foreach (array_keys($running) as $name) {
            $currentThreads += $this->threadAllocations[$name] ?? 1;
        }
        $this->peakEstimatedThreads = max($this->peakEstimatedThreads, $currentThreads);
    }

    /**
     * @param array{process: Process, job: JobAbstract, start: float} $entry
     */
    private function collectResult(array $entry): JobResult
    {
        return $this->buildResult($entry['job'], $entry['process'], $entry['start']);
    }

    private function buildResult(JobAbstract $job, Process $process, float $start): JobResult
    {
        $elapsed = microtime(true) - $start;
        $time = $this->formatTime($elapsed);
        $exitCode = $process->getExitCode() ?? 1;
        $stdout = $process->getOutput();
        $output = $stdout . $process->getErrorOutput();
        $fixApplied = $job->isFixApplied($exitCode);
        $success = $exitCode === 0 || $fixApplied;

        if ($job->isIgnoreErrorsOnExit() && !$success) {
            $success = true;
        }

        // Re-stage fixed files so the commit includes the auto-fixes (e.g. phpcbf)
        if ($fixApplied && $this->gitStager !== null) {
            $this->gitStager->stageTrackedFiles();
        }

        $displayName = $job->getDisplayName();

        if ($success) {
            $this->outputHandler->onJobSuccess($displayName, $time);
        } else {
            $this->outputHandler->onJobError($displayName, $time, $output);
        }

        $command = $job->buildCommand();

        return new JobResult(
            $job->getName(),
            $success,
            $output,
            $time,
            $fixApplied,
            $command,
            $job->getType(),
            $exitCode,
            $job->getConfiguredPaths(),
            false,
            null,
            $stdout,
            $this->buildPerJobInputFiles($job)
        );
    }

    /**
     * Report remaining jobs as skipped after a fail-fast trigger (sequential mode).
     * Returns JobResult::skipped entries so the caller can append them to the
     * results array — structured formats (JSON/JUnit/SARIF) need the full plan.
     *
     * @param JobAbstract[] $allJobs
     * @param JobAbstract $failedJob
     * @return JobResult[]
     */
    private function reportSkipped(array $allJobs, JobAbstract $failedJob): array
    {
        $skipped = [];
        $found = false;
        foreach ($allJobs as $job) {
            if ($job === $failedJob) {
                $found = true;
                continue;
            }
            if ($found) {
                $this->outputHandler->onJobSkipped($job->getDisplayName(), 'skipped by fail-fast');
                $skipped[] = $this->attachInputFilesIfApplicable(
                    JobResult::skipped(
                        $job->getName(),
                        $job->getType(),
                        'skipped by fail-fast',
                        $job->getConfiguredPaths()
                    ),
                    $job
                );
            }
        }
        return $skipped;
    }

    /**
     * Attach an InputFilesPerJob slice to a JobResult when the executor is in
     * "files" mode and the job is accelerable. Otherwise returns the result
     * unchanged.
     */
    private function attachInputFilesIfApplicable(JobResult $result, JobAbstract $job): JobResult
    {
        $slice = $this->buildPerJobInputFiles($job);
        return $slice === null ? $result : $result->withInputFiles($slice);
    }

    private function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000) . 'ms';
        }
        if ($seconds < 60) {
            return number_format($seconds, 2) . 's';
        }
        $minutes = floor($seconds / 60);
        $secs = (int) ($seconds - ($minutes * 60));
        return "{$minutes}m {$secs}s";
    }
}
