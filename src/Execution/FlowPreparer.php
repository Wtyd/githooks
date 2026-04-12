<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Jobs\JobRegistry;

/**
 * Pure function: resolves a flow configuration into an executable plan (list of job instances).
 */
class FlowPreparer
{
    private JobRegistry $jobRegistry;

    public function __construct(JobRegistry $jobRegistry)
    {
        $this->jobRegistry = $jobRegistry;
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
        ?string $invocationMode = null
    ): FlowPlan {
        $options = $flow->getOptions() ?? $config->getGlobalOptions();

        $jobs = [];

        // Backward compatibility: if context was created via forFastMode() and no invocationMode,
        // treat it as fast invocation (old behavior)
        $effectiveInvocation = $invocationMode;
        if ($effectiveInvocation === null && $context !== null && $context->isFastMode()) {
            $effectiveInvocation = ExecutionMode::FAST;
        }

        foreach ($flow->getJobs() as $jobName) {
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

            $jobConfig = $this->applyExecutionMode($jobConfig, $flow, $effectiveInvocation, $context, $options, $config);
            if ($jobConfig === null) {
                continue; // skipped by execution mode filtering
            }

            $job = $this->jobRegistry->create($jobConfig);
            $this->applyExecutablePrefix($job, $jobConfig, $options);
            $jobs[] = $job;
        }

        return new FlowPlan($flow->getName(), $jobs, $options, $context);
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
        ?string $invocationMode = null
    ): FlowPlan {
        // Backward compatibility
        $effectiveInvocation = $invocationMode;
        if ($effectiveInvocation === null && $context !== null && $context->isFastMode()) {
            $effectiveInvocation = ExecutionMode::FAST;
        }

        $jobConfig = $this->applyExecutionModeSingleJob($jobConfig, $effectiveInvocation, $context, $options);

        $job = $this->jobRegistry->create($jobConfig);
        $this->applyExecutablePrefix($job, $jobConfig, $options);
        return new FlowPlan($jobConfig->getName(), [$job], $options, $context);
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

        if (!$jobConfig->isAccelerable($this->jobRegistry) || empty($jobConfig->getPaths())) {
            return $jobConfig;
        }

        if ($context === null) {
            return $jobConfig;
        }

        $jobName = $jobConfig->getName();
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
                $config->getValidation()->addWarning("Job '$jobName' was skipped: no staged files match its paths.");
            }
            return null;
        }

        return $jobConfig->withPaths($filteredFiles);
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
