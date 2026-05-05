<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Process\Process;
use Wtyd\GitHooks\Configuration\AllocatorStrategy;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\TimeBudgetConfiguration;
use Wtyd\GitHooks\Execution\Admission\AdmissionStrategy;
use Wtyd\GitHooks\Execution\Admission\FifoAdmission;
use Wtyd\GitHooks\Execution\Admission\GreedyAdmission;
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

    private bool $thresholdsDisabled = false;

    private bool $memoryBudgetDisabled = false;

    private int $peakEstimatedThreads = 0;

    /** @var array<string, int> jobName => estimated threads */
    private array $threadAllocations = [];

    private ?InputFilesResolution $inputFilesContext = null;

    private ?FlowMemoryHandler $memoryHandler = null;

    /** @var (callable():float)|null */
    private $clockOverride = null;

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
     * Disable per-job and flow time-budget evaluation for this execution.
     * The duration is still measured and emitted; only the WARN/FAIL state
     * is suppressed. Used by `--no-time-budget` (CLI override).
     */
    public function setThresholdsDisabled(bool $disabled): void
    {
        $this->thresholdsDisabled = $disabled;
    }

    /**
     * Disable per-job and flow memory-budget evaluation for this execution.
     * The sampler still runs when --stats is on so the cores axis (and the
     * memory axis if available) is reported, but no thresholds fire and
     * no jobs are killed. Used by `--no-memory-budget` (CLI override).
     */
    public function setMemoryBudgetDisabled(bool $disabled): void
    {
        $this->memoryBudgetDisabled = $disabled;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Orchestrates thread budget + sequential/parallel dispatch
     * @SuppressWarnings(PHPMD.NPathComplexity) Structured format + thread budget + sequential/parallel paths
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) dry-run is a simple mode toggle, not an SRP violation
     */
    public function execute(FlowPlan $plan, bool $dryRun = false): FlowResult
    {
        $start = $this->now();
        $this->memoryHandler = null;
        $coresBudget = $plan->getOptions()->getProcesses();
        $maxProcesses = $coresBudget;
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

        // After resolveThreadBudget, $maxProcesses becomes the slot limit
        // (max parallel jobs from the budget plan). $coresBudget keeps the
        // original total cores so admission can check uncontrollable jobs
        // whose per-job cost may exceed the slot limit.
        $maxProcesses = $this->resolveThreadBudget($jobs, $maxProcesses);

        $inputFiles = $this->inputFilesContext;

        if ($dryRun) {
            return $this->executeDryRun(
                $plan->getFlowName(),
                $jobs,
                $plan->getExecutionMode(),
                $inputFiles,
                $plan->getExpandedFlows(),
                $plan->getEffectiveOptions()
            );
        }

        // Total = executable jobs + plan-level skipped jobs (fast-mode filtering etc.).
        // Both contribute events to the handler, so the denominator must cover both.
        $this->outputHandler->onFlowStart(count($jobs) + count($plan->getSkippedJobs()));

        if (($maxProcesses <= 1 || count($jobs) <= 1) && !$this->shouldSampleMemory($jobs, $plan->getOptions())) {
            $results = $this->executeSequential($jobs, $failFast);
        } else {
            $results = $this->executeParallel($jobs, max(1, $maxProcesses), $coresBudget, $failFast, $plan->getOptions());
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

        $elapsed = $this->now() - $start;
        $totalTime = number_format($elapsed, 2) . 's';
        $budget = $plan->getOptions()->getProcesses();

        $timeBudgetState = $this->buildTimeBudgetState($plan->getOptions()->getTimeBudget(), $results);

        if ($this->memoryHandler !== null) {
            $results = $this->memoryHandler->enrichResults($results, $plan->getJobs());
        }

        $flowResult = new FlowResult(
            $plan->getFlowName(),
            $results,
            $totalTime,
            $this->peakEstimatedThreads,
            $budget,
            $plan->getExecutionMode(),
            $inputFiles,
            $plan->getExpandedFlows(),
            $plan->getEffectiveOptions(),
            $timeBudgetState
        );
        if ($this->memoryHandler !== null) {
            $this->memoryHandler->attachStats($flowResult);
        }

        return $flowResult;
    }

    /**
     * @param JobAbstract[] $jobs
     * @param string[]|null $expandedFlows
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the FlowResult constructor for dry-run output.
     */
    private function executeDryRun(
        string $flowName,
        array $jobs,
        string $executionMode,
        ?InputFilesResolution $inputFiles,
        ?array $expandedFlows = null,
        ?EffectiveOptionsResolution $effectiveOptions = null
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
        return new FlowResult(
            $flowName,
            $results,
            '0ms',
            0,
            0,
            $executionMode,
            $inputFiles,
            $expandedFlows,
            $effectiveOptions
        );
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

        $this->applyExplicitCoresOverrides($jobs, $maxProcesses);

        if ($maxProcesses > 1 && count($jobs) > 1) {
            return $this->allocateParallelBudget($jobs, $maxProcesses);
        }

        $this->fillSequentialAllocations($jobs, $maxProcesses);
        return $maxProcesses;
    }

    /**
     * Apply the cores override declared by the job (explicit `cores: N` or the
     * tool's native threading flag promoted via JobAbstract::extractCoresOverride).
     * The flow's `processes` budget is the absolute ceiling: a job declaring
     * more cores than the flow allows is clamped to the budget so the args
     * propagated to the tool match what the pool's admission accounts for.
     * Same job can live in flows with different budgets — the flow rules.
     *
     * @param JobAbstract[] $jobs
     */
    private function applyExplicitCoresOverrides(array $jobs, int $coresBudget): void
    {
        foreach ($jobs as $job) {
            $override = $job->getCoresOverride();
            if ($override !== null) {
                $clamped = max(1, min($override, $coresBudget));
                $job->applyThreadLimit($clamped);
                $this->threadAllocations[$job->getName()] = $clamped;
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
     * Sequential mode allocation — each job without an explicit override
     * gets `min(capability.defaultThreads, coresBudget)` so the flow's
     * `processes` budget is the absolute ceiling, even when the job has
     * a default capability (parallel-lint's default `jobs: 10`, paratest's
     * default `processes: 4`, etc.). For controllable capabilities the
     * clamped value is propagated to the tool via applyThreadLimit so the
     * args match the pool's admission accounting.
     *
     * @param JobAbstract[] $jobs
     */
    private function fillSequentialAllocations(array $jobs, int $coresBudget): void
    {
        foreach ($jobs as $job) {
            if (isset($this->threadAllocations[$job->getName()])) {
                continue;
            }
            $cap = $job->getThreadCapability();
            if ($cap === null) {
                $this->threadAllocations[$job->getName()] = 1;
                continue;
            }
            $allocation = max(
                $cap->getMinimumThreads(),
                min($cap->getDefaultThreads(), $coresBudget)
            );
            if ($cap->isControllable()) {
                $job->applyThreadLimit($allocation);
            }
            $this->threadAllocations[$job->getName()] = $allocation;
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
    /**
     * Build a ProcessPool wired with the right admission strategy and 2D
     * tracker according to the resolved OptionsConfiguration. Activates 2D
     * bin-packing only when the flow declares a memory-budget AND at least
     * one job has a short-form `memory:` reservation (REQ-009 / REQ-020).
     *
     * @param JobAbstract[] $jobs
     */
    private function buildProcessPool(int $maxProcesses, int $coresBudget, array $jobs, OptionsConfiguration $options): ProcessPool
    {
        $coresByJob = [];
        $memoryReserveByJob = [];
        $hasReservation = false;
        foreach ($jobs as $job) {
            // Clamp the per-job cores cost to the absolute budget. Without this
            // clamp, fillSequentialAllocations (taken when count(jobs) === 1 or
            // budget === 1) writes the capability's defaultThreads verbatim —
            // e.g. PHPStan's 4 — which can exceed the budget and deadlock
            // FifoAdmission whenever shouldSampleMemory() forces executeParallel
            // (REQ-022: memory-budget declared, --stats, or per-job memory).
            // ThreadBudgetAllocator already clamps in the parallel path; this
            // mirrors that invariant for the sequential allocations path.
            $allocation = $this->threadAllocations[$job->getName()] ?? 1;
            $coresByJob[$job->getName()] = max(1, min($allocation, $coresBudget));
            $reserve = $job->getMemoryReserve();
            $memoryReserveByJob[$job->getName()] = $reserve;
            if ($reserve !== null) {
                $hasReservation = true;
            }
        }

        $strategy = $this->resolveAdmissionStrategy($options);
        $memoryBudgetMb = null;
        $budget = $options->getMemoryBudget();
        if ($budget !== null && $hasReservation) {
            $memoryBudgetMb = $budget->getBinPackingReference();
        }

        // Clamp per-job memory reservations to the bin-packing reference. Mirrors
        // the cores clamp above: without this, a single job whose declared
        // `memory: N` exceeds `memory-budget.warn-above` (or `--memory-warn-above`
        // from CLI) would never satisfy AdmissionContext::fits(), leaving
        // FifoAdmission to spin at 100% CPU forever (BUG-002 — the executeParallel
        // loop only sleeps when `hasRunning()` is true). The reported
        // `memoryReserved` in JobResult still reflects the unclamped value
        // (FlowMemoryHandler::enrichSingle reads JobAbstract::getMemoryReserve()
        // directly), so user-facing accounting is unaffected.
        if ($memoryBudgetMb !== null) {
            foreach ($memoryReserveByJob as $name => $reserve) {
                if ($reserve !== null && $reserve > $memoryBudgetMb) {
                    $memoryReserveByJob[$name] = $memoryBudgetMb;
                }
            }
        }

        return new ProcessPool(
            $maxProcesses,
            $strategy,
            $memoryBudgetMb,
            $coresByJob,
            $memoryReserveByJob,
            $coresBudget
        );
    }

    private function resolveAdmissionStrategy(OptionsConfiguration $options): AdmissionStrategy
    {
        return $options->getAllocator() === AllocatorStrategy::GREEDY
            ? new GreedyAdmission()
            : new FifoAdmission();
    }

    /**
     * Whether the memory sampler must run for this execution (REQ-022).
     * Forces parallel mode even when there is only one job: the parallel
     * loop is the only path that ticks the sampler.
     *
     * @param JobAbstract[] $jobs
     */
    private function shouldSampleMemory(array $jobs, OptionsConfiguration $options): bool
    {
        if ($options->isStats()) {
            return true;
        }
        if (!$this->memoryBudgetDisabled && $options->getMemoryBudget() !== null) {
            return true;
        }
        foreach ($jobs as $job) {
            if ($job->getMemoryReserve() !== null || $job->getMemoryThreshold() !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param JobAbstract[] $jobs
     * @return JobResult[]
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Orchestrates pool + dashboard + fail-fast
     * @SuppressWarnings(PHPMD.NPathComplexity) Dashboard tick + pool fill + fail-fast paths
     */
    private function executeParallel(array $jobs, int $maxProcesses, int $coresBudget, bool $failFast, OptionsConfiguration $options): array
    {
        $results = [];
        $pool = $this->buildProcessPool($maxProcesses, $coresBudget, $jobs, $options);
        $pool->enqueue($jobs);
        $failFastTriggered = false;
        $dashboard = $this->outputHandler instanceof DashboardOutputHandler ? $this->outputHandler : null;
        $lastTick = $this->now();

        $memoryHandler = new FlowMemoryHandler(
            $options,
            $this->memoryBudgetDisabled,
            $this->now(),
            $this->threadAllocations
        );
        $memoryHandler->setup($jobs);
        $this->memoryHandler = $memoryHandler;

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

            // Sample memory BEFORE pollCompleted so jobs that finish during
            // this iteration still contribute at least one /proc read while
            // they are alive. Without this, jobs shorter than the throttle
            // window (~1s) reported peak=0 because no tick fired during their
            // lifetime (BUG-7).
            if ($memoryHandler->isActive() && $pool->hasRunning()) {
                $memoryHandler->tick($pool->getRunning());
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

            if ($memoryHandler->shouldKill()) {
                $this->triggerMemoryBudgetKill($pool, $results);
                break;
            }

            if ($pool->hasRunning()) {
                $now = $this->now();
                if ($dashboard !== null && ($now - $lastTick) >= 0.2) {
                    $dashboard->tick();
                    $lastTick = $now;
                }
                // Memory sampling lives at the top of the iteration (above
                // pollCompleted) so jobs finishing within a single tick still
                // get sampled before being polled out of `running`.
                usleep(10000);
            }
        }

        if ($memoryHandler->isActive()) {
            $memoryHandler->tick($pool->getRunning());
        }

        return $results;
    }

    /**
     * Terminate jobs in flight and mark them as killed-by-budget; skip the
     * remaining queue with the same reason (REQ-013).
     *
     * @param JobResult[] $results passed by reference; appended to.
     */
    private function triggerMemoryBudgetKill(ProcessPool $pool, array &$results): void
    {
        fwrite(STDERR, '✗ Flow memory-budget exceeded — terminating jobs in flight' . PHP_EOL);

        foreach ($pool->terminateAll() as $entry) {
            $results[] = $this->collectResult($entry)
                ->withKilled('flow memory-budget exceeded');
        }
        foreach ($pool->getQueuedJobs() as $skippedJob) {
            $this->outputHandler->onJobSkipped(
                $skippedJob->getDisplayName(),
                'flow memory-budget exceeded'
            );
            $results[] = $this->attachInputFilesIfApplicable(
                JobResult::skipped(
                    $skippedJob->getName(),
                    $skippedJob->getType(),
                    'flow memory-budget exceeded',
                    $skippedJob->getConfiguredPaths()
                ),
                $skippedJob
            );
        }
        $pool->clearQueue();
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
        $start = $this->now();

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
        $elapsed = $this->now() - $start;
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

        [$thresholdState, $thresholdReason, $warnAfter, $failAfter] = $this->evaluateThreshold($job, $elapsed);

        // Per-job FAIL by threshold flips OK→KO ONLY when the tool itself succeeded;
        // if the tool already failed (KO real), the threshold is informational and
        // does not alter success/exitCode (matrix §4.4.1, RAT-006).
        if ($success && $thresholdState === JobResult::THRESHOLD_FAILED) {
            $success = false;
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
            $this->buildPerJobInputFiles($job),
            $elapsed,
            $thresholdState,
            $thresholdReason,
            $warnAfter,
            $failAfter
        );
    }

    /**
     * Compare the elapsed duration against the per-job warn-after/fail-after
     * thresholds and return the resulting state.
     *
     * Returns [state, reason, warnAfter, failAfter]. The configured values are
     * surfaced regardless of the resulting state so the JSON formatter can emit
     * the threshold object even when neither warn nor fail crossed.
     *
     * @return array{0: int, 1: ?string, 2: ?int, 3: ?int}
     */
    private function evaluateThreshold(JobAbstract $job, float $elapsed): array
    {
        if ($this->thresholdsDisabled) {
            return [JobResult::THRESHOLD_NONE, null, null, null];
        }

        $warnAfter = $job->getWarnAfter();
        $failAfter = $job->getFailAfter();

        if ($warnAfter === null && $failAfter === null) {
            return [JobResult::THRESHOLD_NONE, null, null, null];
        }

        if ($failAfter !== null && $elapsed >= $failAfter) {
            return [JobResult::THRESHOLD_FAILED, JobResult::THRESHOLD_REASON_FAIL, $warnAfter, $failAfter];
        }

        if ($warnAfter !== null && $elapsed >= $warnAfter) {
            return [JobResult::THRESHOLD_WARNED, JobResult::THRESHOLD_REASON_WARN, $warnAfter, $failAfter];
        }

        return [JobResult::THRESHOLD_NONE, null, $warnAfter, $failAfter];
    }

    /**
     * Build the post-hoc TimeBudgetState from the configured time-budget and
     * the executed-jobs duration sum. Returns null when there is no time-budget
     * configured or thresholds have been disabled for this run.
     *
     * @param JobResult[] $results
     */
    private function buildTimeBudgetState(?TimeBudgetConfiguration $budget, array $results): ?TimeBudgetState
    {
        if ($this->thresholdsDisabled || $budget === null || $budget->isEmpty()) {
            return null;
        }

        $sum = 0.0;
        foreach ($results as $result) {
            if ($result->isSkipped()) {
                continue;
            }
            $sum += $result->getDurationSeconds();
        }

        $warnAfter = $budget->getWarnAfter();
        $failAfter = $budget->getFailAfter();
        $failed = $failAfter !== null && $sum >= $failAfter;
        $warned = !$failed && $warnAfter !== null && $sum >= $warnAfter;

        return new TimeBudgetState($warnAfter, $failAfter, $sum, $warned, $failed);
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

    /**
     * Single source of wallclock time for this executor. Production goes
     * straight to microtime(true); test subclasses inject a closure via
     * setClock() to drive elapsed durations deterministically without
     * spawning sleep subprocesses.
     */
    protected function now(): float
    {
        return $this->clockOverride !== null ? ($this->clockOverride)() : microtime(true);
    }

    /**
     * Test seam: replace the wallclock with a closure. Each call to now()
     * delegates to the closure. Production never calls this method.
     */
    protected function setClock(callable $clock): void
    {
        $this->clockOverride = $clock;
    }
}
