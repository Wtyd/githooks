<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use LogicException;
use Symfony\Component\Process\Process;
use Wtyd\GitHooks\Configuration\AllocatorStrategy;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\TimeBudgetConfiguration;
use Wtyd\GitHooks\Execution\Admission\AdmissionStrategy;
use Wtyd\GitHooks\Execution\Admission\FifoAdmission;
use Wtyd\GitHooks\Execution\Admission\GreedyAdmission;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Output\DashboardOutputHandler;
use Wtyd\GitHooks\Output\HumanIssueFormatter;
use Wtyd\GitHooks\Output\OutputHandler;
use Wtyd\GitHooks\Output\ToolOutputParser\ToolOutputParserRegistry;
use Wtyd\GitHooks\Execution\ThreadBudgetAllocator;
use Wtyd\GitHooks\Utils\GitStagerInterface;
use Wtyd\GitHooks\Utils\IsoTimestamp;

/**
 * Orchestrates flow execution: runs jobs respecting processes limit and fail-fast.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Orchestrator with sequential+parallel+threading+restaging
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Top-level coordinator: by design touches every Execution and Output collaborator
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) FEAT-3 added needs-driven propagation (parallel drain, sequential
 *   propagation, fail-fast descendant classification, and the helper methods that build the propagated skipReason).
 *   Splitting these would scatter the single concern of "what to do when a job's needs failed/skipped" across files.
 * @SuppressWarnings(PHPMD.TooManyMethods) Each public/private method maps to a specific responsibility on the
 *   sequential/parallel paths; collapsing two reduces clarity without reducing breadth of behaviour.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) executeParallel is a single loop that orchestrates the pool tick,
 *   memory sampler, dashboard refresh, and FEAT-3 needs handling. Extracting parts of the loop body would force
 *   shared state into method arguments.
 */
class FlowExecutor
{
    private OutputHandler $outputHandler;

    private ?GitStagerInterface $gitStager;

    private HumanIssueFormatter $humanFormatter;

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

    public function __construct(
        OutputHandler $outputHandler,
        ?GitStagerInterface $gitStager = null,
        ?HumanIssueFormatter $humanFormatter = null
    ) {
        $this->outputHandler = $outputHandler;
        $this->gitStager = $gitStager;
        $this->humanFormatter = $humanFormatter ?? new HumanIssueFormatter(new ToolOutputParserRegistry());
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
                $plan->getEffectiveOptions(),
                $plan->getSkippedJobs()
            );
        }

        // Total = executable jobs + plan-level skipped jobs (fast-mode filtering etc.).
        // Both contribute events to the handler, so the denominator must cover both.
        $this->outputHandler->onFlowStart(count($jobs) + count($plan->getSkippedJobs()));

        // BUG-19: emit plan-skipped results BEFORE running the executors so the
        // executors can seed their bookkeeping (`$skippedNames`,
        // `ProcessPool::$skippedJobs`) with these names. Descendants that have
        // `needs: [<plan-skipped>]` then propagate the skip instead of running.
        // The emit-order swap is intentional: discards first, runtime work
        // second — matches the actual cronology of decisions.
        $preSkipped = $this->emitPlanSkipped($plan->getSkippedJobs(), $inputFiles);
        $preSkippedNames = array_keys($plan->getSkippedJobs());

        if (($maxProcesses <= 1 || count($jobs) <= 1) && !$this->shouldSampleMemory($jobs, $plan->getOptions())) {
            $results = $this->executeSequential(
                $jobs,
                $failFast,
                $plan->getDependencyGraph(),
                $preSkippedNames
            );
        } else {
            $results = $this->executeParallel(
                $jobs,
                max(1, $maxProcesses),
                $coresBudget,
                $failFast,
                $plan->getOptions(),
                $plan->getDependencyGraph(),
                $preSkippedNames
            );
        }

        $results = array_merge($preSkipped, $results);

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
     * Emit and build JobResults for the plan-level skipped jobs (fast-mode
     * filtering, propagated needs, etc.). Shared by execute() and
     * executeDryRun() so a dry-run reaches parity with a real run (FEAT-6):
     * the plan already carries the discards, both paths must surface them.
     *
     * @param array<string, array{reason: string, type: string, paths: string[], accelerable?: bool}> $skippedJobs
     * @return JobResult[]
     */
    private function emitPlanSkipped(array $skippedJobs, ?InputFilesResolution $inputFiles): array
    {
        $results = [];
        foreach ($skippedJobs as $name => $info) {
            $this->outputHandler->onJobSkipped($name, $info['reason']);
            $skippedResult = JobResult::skipped($name, $info['type'], $info['reason'], $info['paths']);
            if ($inputFiles !== null && ($info['accelerable'] ?? false)) {
                $skippedResult = $skippedResult->withInputFiles(
                    new InputFilesPerJob([], $inputFiles->getTotalValid())
                );
            }
            $results[] = $skippedResult;
        }
        return $results;
    }

    /**
     * @param JobAbstract[] $jobs
     * @param string[]|null $expandedFlows
     * @param array<string, array{reason: string, type: string, paths: string[], accelerable?: bool}> $skippedJobs
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the FlowResult constructor for dry-run output.
     */
    private function executeDryRun(
        string $flowName,
        array $jobs,
        string $executionMode,
        ?InputFilesResolution $inputFiles,
        ?array $expandedFlows = null,
        ?EffectiveOptionsResolution $effectiveOptions = null,
        array $skippedJobs = []
    ): FlowResult {
        // FEAT-6: surface the plan-level discards first (parity with execute()),
        // then the jobs that would run with their resolved command.
        $skipped = $this->emitPlanSkipped($skippedJobs, $inputFiles);
        $results = [];
        foreach ($jobs as $job) {
            $command = $job->isInline() ? '(inline commit-msg validation)' : $job->buildCommand();
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
        $flowResult = new FlowResult(
            $flowName,
            array_merge($skipped, $results),
            '0ms',
            0,
            0,
            $executionMode,
            $inputFiles,
            $expandedFlows,
            $effectiveOptions
        );
        $flowResult->markDryRun();
        return $flowResult;
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
     * @param string[] $preSkippedNames BUG-19: jobs descarded by FlowPreparer
     *        (only-files / exclude-files / execution-mode filter). Seeded into
     *        $skippedNames so descendants with `needs: [<one of them>]`
     *        propagate the skip instead of running.
     * @return JobResult[]
     */
    private function executeSequential(
        array $jobs,
        bool $failFast,
        ?\Wtyd\GitHooks\Configuration\FlowDependencyGraph $dependencyGraph = null,
        array $preSkippedNames = []
    ): array {
        $results = [];
        $failedNames = [];     // FEAT-3: jobs whose result was a failure
        $skippedNames = $preSkippedNames; // FEAT-3 + BUG-19: include plan-skipped upstreams
        $consumed = $preSkippedNames;     // jobs already processed (executed or skipped)

        // Register the job names for dashboard display so the running lane has a
        // row to animate. A sequential run on an interactive TTY (single job or
        // `processes: 1`) reaches this path with a dashboard; without this the
        // dashboard's allJobs stays empty and renders nothing. Off-TTY uses the
        // streaming handler, where this is a harmless no-op.
        $this->registerJobsOnDashboard($jobs);

        foreach ($jobs as $job) {
            $name = $job->getName();
            $consumed[] = $name;

            // FEAT-3 propagation: if any of this job's `needs` failed or were
            // skipped, propagate immediately without running the job.
            if ($dependencyGraph !== null) {
                $blockers = self::classifyTerminalBlockersFrom(
                    $dependencyGraph->getNeedsOf($name),
                    $failedNames,
                    $skippedNames
                );
                if ($blockers !== []) {
                    $reason = self::formatPropagatedSkipReasonStatic($blockers);
                    $this->outputHandler->onJobSkipped($job->getDisplayName(), $reason);
                    $results[] = $this->attachInputFilesIfApplicable(
                        JobResult::skipped($name, $job->getType(), $reason, $job->getConfiguredPaths()),
                        $job
                    );
                    $skippedNames[] = $name;
                    continue;
                }
            }

            $threads = $this->threadAllocations[$name] ?? 1;
            $this->peakEstimatedThreads = max($this->peakEstimatedThreads, $threads);

            $result = $this->runJob($job);
            $results[] = $result;
            if (!$result->isSuccess()) {
                $failedNames[] = $name;
            }

            if ($failFast && !$result->isSuccess()) {
                $descendants = $dependencyGraph !== null
                    ? $dependencyGraph->descendantsOf($name)
                    : [];
                foreach ($this->reportSkippedFailFast($jobs, $consumed, $descendants, $name) as $skippedResult) {
                    $results[] = $skippedResult;
                }
                break;
            }
        }

        return $this->attachNeedsToResults($results, $dependencyGraph);
    }

    /**
     * @param JobAbstract[] $jobs
     * @param string[] $consumed jobs already processed (executed or skipped)
     * @param string[] $descendants descendants of the failed job in the DAG
     * @return JobResult[]
     */
    private function reportSkippedFailFast(
        array $jobs,
        array $consumed,
        array $descendants,
        string $failedJobName
    ): array {
        $skipped = [];
        foreach ($jobs as $job) {
            if (in_array($job->getName(), $consumed, true)) {
                continue;
            }
            $reason = in_array($job->getName(), $descendants, true)
                ? "needs $failedJobName failed"
                : 'skipped by fail-fast';
            $this->outputHandler->onJobSkipped($job->getDisplayName(), $reason);
            $skipped[] = $this->attachInputFilesIfApplicable(
                JobResult::skipped(
                    $job->getName(),
                    $job->getType(),
                    $reason,
                    $job->getConfiguredPaths()
                ),
                $job
            );
        }
        return $skipped;
    }

    /**
     * @param string[] $needs
     * @param string[] $failedNames
     * @param string[] $skippedNames
     * @return array<string, string>  blockerName → 'failed' | 'skipped'
     */
    private static function classifyTerminalBlockersFrom(array $needs, array $failedNames, array $skippedNames): array
    {
        $blockers = [];
        foreach ($needs as $dep) {
            if (in_array($dep, $failedNames, true)) {
                $blockers[$dep] = 'failed';
            } elseif (in_array($dep, $skippedNames, true)) {
                $blockers[$dep] = 'skipped';
            }
        }
        return $blockers;
    }

    /**
     * @param array<string, string> $blockers
     */
    private static function formatPropagatedSkipReasonStatic(array $blockers): string
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
    protected function buildProcessPool(int $maxProcesses, int $coresBudget, array $jobs, OptionsConfiguration $options): ProcessPool
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
     * @param string[] $preSkippedNames BUG-19: jobs descarded by FlowPreparer.
     *        Notified into the pool BEFORE the loop so descendants resolve
     *        their `needs` against the same set sequential executor sees.
     * @return JobResult[]
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Orchestrates pool + dashboard + fail-fast
     * @SuppressWarnings(PHPMD.NPathComplexity) Dashboard tick + pool fill + fail-fast paths
     */
    private function executeParallel(
        array $jobs,
        int $maxProcesses,
        int $coresBudget,
        bool $failFast,
        OptionsConfiguration $options,
        ?\Wtyd\GitHooks\Configuration\FlowDependencyGraph $dependencyGraph = null,
        array $preSkippedNames = []
    ): array {
        $results = [];
        $pool = $this->buildProcessPool($maxProcesses, $coresBudget, $jobs, $options);
        $pool->enqueue($jobs);
        // FEAT-3: register needs map so the admission gate and the drain
        // primitive know each job's dependencies.
        if ($dependencyGraph !== null) {
            $needsByJob = [];
            foreach ($jobs as $job) {
                $needsByJob[$job->getName()] = $dependencyGraph->getNeedsOf($job->getName());
            }
            $pool->setNeedsByJob($needsByJob);
        }
        // BUG-19: seed the pool's skipped set with plan-skipped jobs so the
        // first drain pass propagates the skip to their descendants. Without
        // this, descendants with `needs: [<plan-skipped>]` run anyway in
        // sequential mode and can deadlock the pool in parallel mode (their
        // needs never reach a terminal state).
        foreach ($preSkippedNames as $name) {
            $pool->notifyResult($name, false, true);
        }
        $failFastTriggered = false;
        $waitingShown = [];  // FEAT-3: jobName → list-of-blockers last announced
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
                // FEAT-3: drain jobs whose `needs` already failed/skipped BEFORE
                // attempting admission. Drained jobs surface as JobResults with
                // a propagated `skipReason` (D2).
                if ($dependencyGraph !== null) {
                    foreach ($pool->drainBlockedByFailedDeps() as $drained) {
                        $reason = $drained->getSkipReason() ?? 'needs were not satisfied';
                        $this->outputHandler->onJobSkipped($drained->getJobName(), $reason);
                        $results[] = $drained;
                    }
                    // Emit `onJobWaiting` for jobs still queued with pending
                    // needs. Re-emit only when the blocker set changed since
                    // the last announcement to avoid spam.
                    foreach ($pool->getWaitingByJob() as $name => $blockers) {
                        $last = $waitingShown[$name] ?? null;
                        if ($last !== $blockers) {
                            $this->outputHandler->onJobWaiting($name, $blockers);
                            $waitingShown[$name] = $blockers;
                        }
                    }
                }

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
                $pool->notifyResult($result->getJobName(), $result->isSuccess(), false);

                if ($failFast && !$result->isSuccess() && !$failFastTriggered) {
                    $failFastTriggered = true;
                    // FEAT-3 (D6): jobs in running terminate naturally; we no
                    // longer terminateAll(). The remaining queue is drained
                    // with descendant-aware skip reasons.
                    // We must NOT `break` here: pollCompleted() already removed
                    // every entry of this batch from `running`, so abandoning
                    // the foreach would drop the JobResults of jobs that
                    // finished in the SAME poll as the failing one (missing from
                    // the report/JSON). The `!$failFastTriggered` guard keeps the
                    // drain a one-shot; the rest of the batch is still collected.
                    $descendants = $dependencyGraph !== null
                        ? $dependencyGraph->descendantsOf($result->getJobName())
                        : [];
                    foreach ($pool->getQueuedJobs() as $skippedJob) {
                        $reason = in_array($skippedJob->getName(), $descendants, true)
                            ? "needs {$result->getJobName()} failed"
                            : 'skipped by fail-fast';
                        $this->outputHandler->onJobSkipped($skippedJob->getDisplayName(), $reason);
                        $results[] = $this->attachInputFilesIfApplicable(
                            JobResult::skipped(
                                $skippedJob->getName(),
                                $skippedJob->getType(),
                                $reason,
                                $skippedJob->getConfiguredPaths()
                            ),
                            $skippedJob
                        );
                        $pool->notifyResult($skippedJob->getName(), false, true);
                    }
                    $pool->clearQueue();
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

        return $this->attachNeedsToResults($results, $dependencyGraph);
    }

    /**
     * FEAT-3: stamp each JobResult with its declared `needs` so the JSON v2
     * formatter can emit the field. No-op when the flow has no dependency
     * graph (most flows; `attach` returns the array unchanged).
     *
     * @param JobResult[] $results
     * @return JobResult[]
     */
    private function attachNeedsToResults(
        array $results,
        ?\Wtyd\GitHooks\Configuration\FlowDependencyGraph $graph
    ): array {
        if ($graph === null) {
            return $results;
        }
        $stamped = [];
        foreach ($results as $result) {
            $needs = $graph->getNeedsOf($result->getJobName());
            $stamped[] = $needs === [] ? $result : $result->withNeeds($needs);
        }
        return $stamped;
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

    /**
     * Register the job names on the dashboard (when the active handler is one)
     * so the running lane has rows to animate. No-op for any other handler.
     *
     * @param JobAbstract[] $jobs
     */
    private function registerJobsOnDashboard(array $jobs): void
    {
        $dashboard = $this->outputHandler instanceof DashboardOutputHandler ? $this->outputHandler : null;
        if ($dashboard === null) {
            return;
        }
        $names = array_map(function (JobAbstract $job): string {
            return $job->getDisplayName();
        }, $jobs);
        $dashboard->registerJobs($names);
    }

    private function runJob(JobAbstract $job): JobResult
    {
        if ($job->isInline()) {
            return $this->runJobInline($job);
        }

        $command = $job->buildCommand();
        $start = $this->now();

        $this->outputHandler->onJobStart($job->getDisplayName());

        $process = Process::fromShellCommandLine($command);
        $process->setTimeout(null);

        $displayName = $job->getDisplayName();
        $handler = $this->outputHandler;
        $dashboard = $handler instanceof DashboardOutputHandler ? $handler : null;

        $process->start(function (string $type, string $buffer) use ($displayName, $handler): void {
            $handler->onJobOutput($displayName, $buffer, $type === Process::ERR);
        });

        // Poll while the job runs so the dashboard spinner/timer animate in
        // place at the same 0.2s cadence as the parallel pool. Without a
        // dashboard the loop just waits out the process — output still streams
        // through the start() callback, so the streaming handler is unaffected.
        // now() is only read when a dashboard is present so non-dashboard runs
        // keep their exact clock-call count (timing tests script a fixed clock).
        $lastTick = $dashboard !== null ? $this->now() : 0.0;
        while ($process->isRunning()) {
            if ($dashboard !== null && ($this->now() - $lastTick) >= 0.2) {
                $dashboard->tick();
                $lastTick = $this->now();
            }
            usleep(10000);
        }
        $process->wait();

        return $this->buildResult($job, $process, $start);
    }

    /**
     * Run an inline job (FEAT-16): no shell, no process. The job validates
     * in-process and returns its own JobResult; we only emit the lifecycle
     * events so the dashboard/streaming output stays consistent (PAT-001).
     */
    private function runJobInline(JobAbstract $job): JobResult
    {
        $this->outputHandler->onJobStart($job->getDisplayName());
        $result = $job->runInline();
        $this->emitJobResult($job, $result);
        return $result;
    }

    /**
     * Emit the success/error/skip lifecycle event matching a finished JobResult.
     * Shared by the inline path and the parallel pool, which both obtain a
     * fully-formed JobResult without going through {@see buildResult()}.
     */
    private function emitJobResult(JobAbstract $job, JobResult $result): void
    {
        $displayName = $job->getDisplayName();
        if ($result->isSkipped()) {
            $this->outputHandler->onJobSkipped($displayName, $result->getSkipReason() ?? '');
            return;
        }
        if ($result->isSuccess()) {
            $this->outputHandler->onJobSuccess($displayName, $result->getExecutionTime());
            return;
        }
        // Inline jobs never stream through Process; push the failure block via
        // onJobOutput so the streaming handler shows it, then onJobError so the
        // buffered handler shows it (each handler honours exactly one). Mirrors
        // how a shell job's output reaches both channels.
        if ($result->getOutput() !== '') {
            $this->outputHandler->onJobOutput($displayName, $result->getOutput() . "\n", true);
        }
        $this->outputHandler->onJobError($displayName, $result->getExecutionTime(), $result->getOutput());
    }

    /**
     * @param array<string, array{process: ?Process, job: JobAbstract, start: float, result?: JobResult}> $running
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
     * @param array{process: ?Process, job: JobAbstract, start: float, result?: JobResult} $entry
     */
    private function collectResult(array $entry): JobResult
    {
        // FEAT-16: inline jobs carry their pre-computed JobResult (the pool ran
        // them synchronously at admission, no process); emit the lifecycle event
        // here so parallel runs surface them like any other completed job.
        if (isset($entry['result'])) {
            $this->emitJobResult($entry['job'], $entry['result']);
            return $entry['result'];
        }

        $process = $entry['process'];
        if ($process === null) {
            throw new LogicException('Non-inline pool entry is missing its process.');
        }

        return $this->buildResult($entry['job'], $process, $entry['start']);
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Aggregates exit-code, fix-applied,
     *   empty-input tolerance, ignoreErrorsOnExit, threshold and output dispatch in
     *   a single pass; each branch corresponds to one independent outcome of a single job.
     * @SuppressWarnings(PHPMD.NPathComplexity) Same reason.
     */
    private function buildResult(JobAbstract $job, Process $process, float $start): JobResult
    {
        $end = $this->now();
        $elapsed = $end - $start;
        $time = $this->formatTime($elapsed);
        $exitCode = $process->getExitCode() ?? 1;
        $stdout = $process->getOutput();
        $output = $stdout . $process->getErrorOutput();
        $fixApplied = $job->isFixApplied($exitCode);
        $emptyInputTolerated = $job->isEmptyInputTolerated($exitCode, $output);
        $success = $exitCode === 0 || $fixApplied || $emptyInputTolerated;

        if ($job->isIgnoreErrorsOnExit() && !$success) {
            $success = true;
        }

        // Re-stage fixed files so the commit includes the auto-fixes (e.g. phpcbf)
        if ($fixApplied && $this->gitStager !== null) {
            $this->gitStager->stageTrackedFiles();
        }

        // Skip threshold for empty-input-tolerated jobs: the tool didn't do real
        // work, comparing its (near-zero) duration against warn-after/fail-after
        // would be meaningless and could fail a legitimate skip.
        if ($emptyInputTolerated) {
            [$thresholdState, $thresholdReason, $warnAfter, $failAfter] = [JobResult::THRESHOLD_NONE, null, null, null];
        } else {
            [$thresholdState, $thresholdReason, $warnAfter, $failAfter] = $this->evaluateThreshold($job, $elapsed);
        }

        // Per-job FAIL by threshold flips OK→KO ONLY when the tool itself succeeded;
        // if the tool already failed (KO real), the threshold is informational and
        // does not alter success/exitCode (matrix §4.4.1, RAT-006).
        if ($success && $thresholdState === JobResult::THRESHOLD_FAILED) {
            $success = false;
        }

        $skipReason = null;
        $displayName = $job->getDisplayName();

        if ($emptyInputTolerated) {
            $skipReason = 'tool reported no input files after applying internal exclusions';
            $this->outputHandler->onJobSkipped($displayName, $skipReason);
        } elseif ($success) {
            $this->outputHandler->onJobSuccess($displayName, $time);
        } else {
            // BUG-18: when structuredFormat is on the tools emit JSON for the
            // SARIF/CodeClimate file formatters; humanise it before passing to
            // the display layer, but keep the raw $output in the JobResult so
            // file-based formatters keep getting the JSON they parse.
            $displayOutput = $this->structuredFormat
                ? $this->humanFormatter->format($job->getType(), $output)
                : $output;
            $this->outputHandler->onJobError($displayName, $time, $displayOutput);
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
            $emptyInputTolerated,
            $skipReason,
            $stdout,
            $this->buildPerJobInputFiles($job),
            $elapsed,
            $thresholdState,
            $thresholdReason,
            $warnAfter,
            $failAfter,
            IsoTimestamp::fromMicrotime($start),
            IsoTimestamp::fromMicrotime($end)
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
