<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\JobRef;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Hooks\PatternMatcher;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Jobs\JobRegistry;

/**
 * Pure function: resolves a flow configuration into an executable plan (list of job instances).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Single owner of mode resolution +
 *   accelerability gating + path filtering + skip-reason mapping for both the multi-job
 *   prepare() and prepareSingleJob() entry points; cohesive by design.
 */
class FlowPreparer
{
    private JobRegistry $jobRegistry;

    private PatternMatcher $patternMatcher;

    public function __construct(JobRegistry $jobRegistry, ?PatternMatcher $patternMatcher = null)
    {
        $this->jobRegistry = $jobRegistry;
        $this->patternMatcher = $patternMatcher ?? new PatternMatcher();
    }

    /**
     * @param string[] $excludeJobs Job names to exclude from the plan
     * @param string[] $onlyJobs    If non-empty, only these job names are included
     * @param string|null $invocationMode CLI flag or HookRef execution mode (overrides config)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Orchestrates filtering, execution modes, and job instantiation
     * @SuppressWarnings(PHPMD.NPathComplexity) Each execution mode path adds an independent branch
     */
    public function prepare(
        FlowConfiguration $flow,
        ConfigurationResult $config,
        ?ExecutionContext $context = null,
        array $excludeJobs = [],
        array $onlyJobs = [],
        ?string $invocationMode = null,
        string $cliExtraArgs = ''
    ): FlowPlan {
        // BUG-20: per-key cascade for executable-prefix/fast-branch-fallback/reports.
        $options = OptionsConfiguration::cascadeBlockKeysFromFlow($flow->getOptions(), $config->getGlobalOptions());

        $jobs = [];
        $skippedJobs = [];

        // Backward compatibility: if context was created via forFastMode() and no invocationMode,
        // treat it as fast invocation (old behavior)
        $effectiveInvocation = $invocationMode;
        if ($effectiveInvocation === null && $context !== null && $context->isFastMode()) {
            $effectiveInvocation = ExecutionMode::FAST;
        }

        // FEAT-3: when the flow has a dependency graph, iterate in topological
        // order so executeSequential() respects `needs` automatically (it has
        // no admission strategy, only iterates the array verbatim). The
        // parallel path also benefits — the FIFO queue stays consistent with
        // the declared dependency order.
        $orderedRefs = $this->orderJobRefsTopologically($flow);

        foreach ($orderedRefs as $jobRef) {
            $jobName = $jobRef->getTarget();
            if (!empty($onlyJobs) && !in_array($jobName, $onlyJobs, true)) {
                continue;
            }
            if (in_array($jobName, $excludeJobs, true)) {
                continue;
            }
            $jobConfig = $config->getJob($jobName);
            if ($jobConfig === null) {
                continue;
            }

            $originalConfig = $jobConfig;

            $admissionMode = $this->resolveMode($effectiveInvocation, $jobConfig, $flow);
            $admissionSkipReason = $this->resolveAdmissionSkipReason($jobRef, $admissionMode, $context);
            if ($admissionSkipReason !== null) {
                $config->getValidation()->addWarning("Job '$jobName' was skipped: $admissionSkipReason.");
                $skippedJobs[$jobName] = [
                    'type' => $originalConfig->getType(),
                    'reason' => $admissionSkipReason,
                    'paths' => $originalConfig->getPaths(),
                    'accelerable' => $originalConfig->isAccelerable($this->jobRegistry),
                ];
                continue;
            }

            $jobConfig = $this->applyExecutionMode($jobConfig, $flow, $effectiveInvocation, $context, $options, $config);
            if ($jobConfig === null) {
                // Job was skipped by execution mode filtering — record it.
                // Match the reason filterJobForMode() emitted as a warning so
                // structured outputs (JSON / SARIF / JUnit) and the validation
                // log stay consistent. BUG-15: the universal "no changes to
                // validate" branch is checked first; otherwise fall back to the
                // existing per-paths messages.
                $reason = $this->resolveSkipReason($context, $effectiveInvocation, $flow, $originalConfig);
                $skippedJobs[$jobName] = [
                    'type' => $originalConfig->getType(),
                    'reason' => $reason,
                    'paths' => $originalConfig->getPaths(),
                    'accelerable' => $originalConfig->isAccelerable($this->jobRegistry),
                ];
                continue;
            }

            $job = $this->jobRegistry->create($jobConfig);
            if ($cliExtraArgs !== '') {
                $job->applyCliExtraArguments($cliExtraArgs);
            }
            $this->applyExecutablePrefix($job, $jobConfig, $options);
            $jobs[] = $job;
        }

        return new FlowPlan(
            $flow->getName(),
            $jobs,
            $options,
            $context,
            $skippedJobs,
            $effectiveInvocation ?? ExecutionMode::FULL,
            $context !== null ? $context->getInputFilesResolution() : null,
            null,
            null,
            $flow->getDependencyGraph()
        );
    }

    /**
     * Prepare a multi-flow run (githooks flows <a> <b> ...).
     *
     * Receives names already validated to exist as either normal flow or meta-flow,
     * and options already resolved by EffectiveOptionsResolver. Performs meta-flow
     * expansion + dedup of flow names + dedup of jobs (REQ-003 / spec §4.3) and
     * delegates the actual job assembly to prepare() via a synthetic aggregate flow.
     *
     * @param string[] $argNames Flow/meta-flow names tal cual del CLI (already validated)
     * @param string $aggregateFlowName Identificador del run según spec §4.4 (e.g. "qa+lint")
     * @param string[] $excludeJobs
     * @param string[] $onlyJobs
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors prepare() with the variadic name list and aggregate id
     */
    public function prepareMultiple(
        array $argNames,
        string $aggregateFlowName,
        ConfigurationResult $config,
        OptionsConfiguration $options,
        ?ExecutionContext $context = null,
        array $excludeJobs = [],
        array $onlyJobs = [],
        ?string $invocationMode = null,
        string $cliExtraArgs = ''
    ): FlowPlan {
        $expandedFlowNames = MultiFlowExpansion::expandFlowNames($argNames, $config);

        // FEAT-1/FEAT-3: build the aggregate from rich JobRefs (preserving
        // needs / only-files / exclude-files) and reconstruct the dependency
        // graph, so `flows` honours flow-entry attributes exactly like `flow`.
        // The single-flow degenerate is just the one-flow case of the same
        // merge: its refs and graph are identical to the source flow.
        $jobRefs = MultiFlowExpansion::mergeFlowJobRefs($expandedFlowNames, $config);
        $jobNames = array_map(static fn(JobRef $ref): string => $ref->getTarget(), $jobRefs);
        $dependencyGraph = MultiFlowExpansion::buildAggregateGraph($aggregateFlowName, $jobRefs);

        $aggregate = new FlowConfiguration(
            $aggregateFlowName,
            $jobNames,
            $options,
            null,
            null,
            $jobRefs,
            null,
            $dependencyGraph
        );

        $plan = $this->prepare($aggregate, $config, $context, $excludeJobs, $onlyJobs, $invocationMode, $cliExtraArgs);

        return new FlowPlan(
            $plan->getFlowName(),
            $plan->getJobs(),
            $plan->getOptions(),
            $plan->getContext(),
            $plan->getSkippedJobs(),
            $plan->getExecutionMode(),
            $plan->getInputFiles() ?? null,
            $expandedFlowNames,
            $plan->getEffectiveOptions(),
            $plan->getDependencyGraph()
        );
    }

    /**
     * Prepare a single job for direct execution (githooks job <name>).
     *
     * @param string|null $invocationMode CLI flag execution mode
     */
    public function prepareSingleJob(
        JobConfiguration $jobConfig,
        OptionsConfiguration $options,
        ?ExecutionContext $context = null,
        ?string $invocationMode = null,
        string $cliExtraArgs = ''
    ): FlowPlan {
        // Backward compatibility
        $effectiveInvocation = $invocationMode;
        if ($effectiveInvocation === null && $context !== null && $context->isFastMode()) {
            $effectiveInvocation = ExecutionMode::FAST;
        }

        $jobConfig = $this->applyExecutionModeSingleJob($jobConfig, $effectiveInvocation, $context, $options);

        $job = $this->jobRegistry->create($jobConfig);
        if ($cliExtraArgs !== '') {
            $job->applyCliExtraArguments($cliExtraArgs);
        }
        $this->applyExecutablePrefix($job, $jobConfig, $options);
        // FEAT-13 envelope reporting: if no CLI mode flag is present, fall
        // back to the job-declared `execution` so the plan (and the JSON
        // envelope's top-level `executionMode`) reflects the mode the job
        // actually runs in. Matches the resolution priority used by
        // `resolveMode()` for jobs inside a flow.
        $planMode = $effectiveInvocation ?? $jobConfig->getExecution() ?? ExecutionMode::FULL;
        return new FlowPlan(
            $jobConfig->getName(),
            [$job],
            $options,
            $context,
            [],
            $planMode,
            $context !== null ? $context->getInputFilesResolution() : null
        );
    }

    /**
     * Resolve the effective execution mode for a job.
     * Priority: invocation > job config > flow config > full
     */
    private function resolveMode(?string $invocationMode, JobConfiguration $jobConfig, FlowConfiguration $flow): string
    {
        if ($invocationMode !== null) {
            return $invocationMode;
        }
        if ($jobConfig->getExecution() !== null) {
            return $jobConfig->getExecution();
        }
        if ($flow->getExecution() !== null) {
            return $flow->getExecution();
        }
        return ExecutionMode::FULL;
    }

    /**
     * Map the skipped-job reason recorded in FlowPlan to the warning emitted
     * by filterJobForMode(). Keeps structured outputs (JSON / SARIF / JUnit)
     * aligned with the validation log.
     */
    private function resolveSkipReason(
        ?ExecutionContext $context,
        ?string $effectiveInvocation,
        FlowConfiguration $flow,
        JobConfiguration $originalConfig
    ): string {
        if ($context !== null && $effectiveInvocation !== null) {
            $mode = $this->resolveMode($effectiveInvocation, $originalConfig, $flow);
            if ($context->isEffectiveSetEmpty($mode)) {
                return 'no changes to validate';
            }
        }
        return ($context !== null && $context->hasInputFiles())
            ? 'no input files match its paths'
            : 'no staged files match its paths';
    }

    /**
     * Apply execution mode filtering to a job config within a flow.
     * Returns null if the job should be skipped, or the (possibly filtered) JobConfiguration.
     */
    private function applyExecutionMode(
        JobConfiguration $jobConfig,
        FlowConfiguration $flow,
        ?string $invocationMode,
        ?ExecutionContext $context,
        OptionsConfiguration $options,
        ConfigurationResult $config
    ): ?JobConfiguration {
        $mode = $this->resolveMode($invocationMode, $jobConfig, $flow);

        return $this->filterJobForMode($jobConfig, $mode, $context, $options, $config);
    }

    /**
     * Apply execution mode filtering to a single job (no flow context).
     */
    private function applyExecutionModeSingleJob(
        JobConfiguration $jobConfig,
        ?string $invocationMode,
        ?ExecutionContext $context,
        OptionsConfiguration $options
    ): JobConfiguration {
        $mode = $invocationMode ?? ($jobConfig->getExecution() ?? ExecutionMode::FULL);

        return $this->filterJobForMode($jobConfig, $mode, $context, $options) ?? $jobConfig;
    }

    /**
     * Core execution mode filtering shared by both flow and single-job paths.
     * Returns null if the job should be skipped, or the (possibly filtered) JobConfiguration.
     *
     * @param ConfigurationResult|null $config When non-null, warnings about skipped jobs are added
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Consolidates mode/accelerability/fallback logic from two paths
     * @SuppressWarnings(PHPMD.NPathComplexity) Each early-return adds an independent branch
     */
    private function filterJobForMode(
        JobConfiguration $jobConfig,
        string $mode,
        ?ExecutionContext $context,
        OptionsConfiguration $options,
        ?ConfigurationResult $config = null
    ): ?JobConfiguration {
        if ($mode === ExecutionMode::FULL) {
            return $jobConfig;
        }

        $jobName = $jobConfig->getName();

        // BUG-15: in fast/fast-branch with an empty effective set (no staged
        // files / no diff vs base) every job is skipped — accelerable or
        // not, paths declared or not. The contract of these modes is "run
        // only what the changes affect; nothing changed = nothing to run".
        // This guard lives BEFORE the accelerable/paths short-circuit so
        // non-accelerable jobs (phpunit, paratest, custom, script, …) honour
        // it instead of running their original paths.
        if ($context !== null && $context->isEffectiveSetEmpty($mode)) {
            if ($config !== null) {
                $config->getValidation()->addWarning("Job '$jobName' was skipped: no changes to validate.");
            }
            return null;
        }

        if (!$jobConfig->isAccelerable($this->jobRegistry) || empty($jobConfig->getPaths())) {
            return $jobConfig;
        }

        if ($context === null) {
            return $jobConfig;
        }

        $filteredFiles = $context->filterFilesForMode($mode, $jobConfig->getPaths());

        if ($filteredFiles === null && $mode === ExecutionMode::FAST_BRANCH) {
            $fallbackMode = $options->getFastBranchFallback();
            if ($fallbackMode === ExecutionMode::FULL) {
                return $jobConfig;
            }
            $filteredFiles = $context->filterFilesForMode(ExecutionMode::FAST, $jobConfig->getPaths());
            if (empty($filteredFiles)) {
                if ($config !== null) {
                    $config->getValidation()->addWarning("Job '$jobName' was skipped: no staged files match its paths (fast-branch fallback to fast).");
                }
                return null;
            }
            return $jobConfig->withPaths($filteredFiles);
        }

        if (empty($filteredFiles)) {
            if ($config !== null) {
                $reason = $context->hasInputFiles() ? 'no input files match its paths' : 'no staged files match its paths';
                $config->getValidation()->addWarning("Job '$jobName' was skipped: $reason.");
            }
            return null;
        }

        return $jobConfig->withPaths($filteredFiles);
    }

    /**
     * Return the job refs in topological order if the flow has a dependency
     * graph; otherwise return them in declaration order unchanged.
     *
     * @return JobRef[]
     */
    private function orderJobRefsTopologically(FlowConfiguration $flow): array
    {
        $refs = $flow->getJobReferences();
        $graph = $flow->getDependencyGraph();
        if ($graph === null) {
            return $refs;
        }
        $byName = [];
        foreach ($refs as $ref) {
            $byName[$ref->getTarget()] = $ref;
        }
        $ordered = [];
        foreach ($graph->getOrderedNames() as $name) {
            if (isset($byName[$name])) {
                $ordered[] = $byName[$name];
            }
        }
        return $ordered;
    }

    /**
     * FEAT-1 admission: evaluate `only-files` / `exclude-files` of the flow
     * entry against the effective set of files for the mode. Returns null when
     * the job is admitted, or a short skipReason when it must be skipped.
     *
     * Decoupled from `filterJobForMode()`: admission is a per-flow-entry
     * concern (the same job admitted from another flow can have different
     * rules), so it is evaluated here before mode-based path filtering.
     */
    private function resolveAdmissionSkipReason(JobRef $jobRef, string $mode, ?ExecutionContext $context): ?string
    {
        if (!$jobRef->hasAdmissionRules() || $mode === ExecutionMode::FULL || $context === null) {
            return null;
        }

        $effectiveSet = $context->getEffectiveSet($mode);
        if (empty($effectiveSet)) {
            // Empty / unavailable set: BUG-15 already handles this with its
            // own skip reason later in filterJobForMode(). Defer to it.
            return null;
        }

        $onlyFiles = $jobRef->getOnlyFiles() ?? [];
        $excludeFiles = $jobRef->getExcludeFiles() ?? [];

        if ($this->patternMatcher->matchesFiles($effectiveSet, $onlyFiles, $excludeFiles)) {
            return null;
        }

        return $this->admissionSkipMessage($jobRef);
    }

    private function admissionSkipMessage(JobRef $jobRef): string
    {
        $hasOnly = $jobRef->getOnlyFiles() !== null;
        $hasExclude = $jobRef->getExcludeFiles() !== null;
        if ($hasOnly && !$hasExclude) {
            return "no files in the change set match its only-files rule";
        }
        if (!$hasOnly && $hasExclude) {
            return "every file in the change set is filtered by its exclude-files rule";
        }
        return "no files in the change set survive its only-files / exclude-files rules";
    }

    /**
     * Resolve and apply executable prefix to a job.
     * Priority: per-job > options (flow-level or global).
     */
    private function applyExecutablePrefix(
        JobAbstract $job,
        JobConfiguration $jobConfig,
        OptionsConfiguration $options
    ): void {
        $jobRawConfig = $jobConfig->getConfig();

        if (array_key_exists('executable-prefix', $jobRawConfig)) {
            $jobPrefix = $jobRawConfig['executable-prefix'];
            if (is_string($jobPrefix) && $jobPrefix !== '') {
                $job->applyExecutablePrefix($jobPrefix);
            }
            // If null or empty string: explicit opt-out, don't apply any prefix
            return;
        }

        $globalPrefix = $options->getExecutablePrefix();
        if ($globalPrefix !== '') {
            $job->applyExecutablePrefix($globalPrefix);
        }
    }
}
