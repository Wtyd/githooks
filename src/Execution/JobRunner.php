<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * Pure-orchestration handler for `githooks job <name>`. Takes a fully-resolved
 * {@see JobRunRequest} from the Command layer and returns a
 * {@see JobPreparation} the Command can act on (emit errors, configure the
 * executor, run, render). Lives outside the Symfony Command hierarchy so the
 * 8-step business pipeline (parse → validate v3/legacy → validate config →
 * find job → build context → resolve effective options → prepare plan →
 * apply per-job threshold overrides) can be unit-tested with synthetic fakes
 * without booting Laravel-Zero or `$this->artisan()`.
 *
 * Phase 1 of the JobCommand refactor (see plan
 * `serene-gathering-nova.md`): rendering ceremony — applying format,
 * emitting the conditions header, calling `FlowExecutor::execute()`,
 * rendering the `FlowResult` — stays in the Command via the existing traits
 * (`FormatsOutput`, `EmitsConditionsHeader`, `EmitsConfigWarnings`). Phase 2
 * extracts those traits to plain classes and migrates the rendering inside
 * the Runner as well.
 */
class JobRunner
{
    private ConfigurationParser $parser;

    private FlowPreparer $preparer;

    private FileUtilsInterface $fileUtils;

    public function __construct(
        ConfigurationParser $parser,
        FlowPreparer $preparer,
        FileUtilsInterface $fileUtils
    ) {
        $this->parser = $parser;
        $this->preparer = $preparer;
        $this->fileUtils = $fileUtils;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Orchestrates parse/validate/find/prepare; each branch is one
     *   class of pre-execution error.
     * @SuppressWarnings(PHPMD.NPathComplexity) Sequential pipeline with linear early-returns per error class.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Single-pass orchestration — splitting helpers
     *   would obscure the eight-step pipeline (parse → validate → find → context → options → plan → thresholds → rewrap).
     */
    public function prepare(JobRunRequest $request): JobPreparation
    {
        try {
            $config = $this->parser->parse($request->configFile);
        } catch (GitHooksExceptionInterface $e) {
            return JobPreparation::failure([$e->getMessage()]);
        }

        if ($config->isLegacy()) {
            return JobPreparation::failure([
                "The 'job' command requires v3 configuration format (hooks/flows/jobs).",
                "Use 'githooks conf:init' to generate the new format.",
            ]);
        }

        if ($config->hasErrors()) {
            return JobPreparation::failure($config->getValidation()->getErrors());
        }

        $jobConfig = $config->getJob($request->jobName);
        if ($jobConfig === null) {
            $errors = ["Job '{$request->jobName}' is not defined in the configuration file."];
            $available = array_keys($config->getJobs());
            if (!empty($available)) {
                $errors[] = 'Available jobs: ' . implode(', ', $available);
            }
            return JobPreparation::failure($errors);
        }

        if ($request->inputFiles !== null) {
            $context = ExecutionContext::forInputFiles($request->inputFiles, $this->fileUtils);
            $invocationMode = ExecutionMode::FAST;
        } else {
            $mainBranch = $config->getGlobalOptions()->getMainBranch()
                ?? $this->fileUtils->detectMainBranch();
            $context = ExecutionContext::create($this->fileUtils, $mainBranch);
            $invocationMode = $request->invocationMode;
        }

        // FEAT-13 envelope reporting: pass the job-declared `execution` (and
        // a `jobs.<name>.execution` label) so the JSON envelope's
        // `executionMode` reflects it instead of falling back to `default`.
        // The actual file-set filtering already honours `jobs.X.execution`
        // via `FlowPreparer::resolveMode`; this aligns the reported envelope
        // with that behaviour.
        $jobLevelExecution = $jobConfig->getExecution();
        $jobLevelExecutionLabel = $jobLevelExecution !== null
            ? "jobs.{$jobConfig->getName()}.execution"
            : '';

        $resolver = new EffectiveOptionsResolver();
        $resolution = $resolver->resolveMultiple(
            $config,
            $request->cliFailFast,
            null, // cliProcesses — `job` does not expose `--processes`
            $invocationMode,
            null,
            null,
            $request->timeBudgetDisabled,
            null,
            null,
            $request->memoryBudgetDisabled,
            null,
            $request->statsFlag,
            $jobLevelExecution,
            $jobLevelExecutionLabel
        );

        $plan = $this->preparer->prepareSingleJob(
            $jobConfig,
            $resolution->getOptions(),
            $context,
            $invocationMode,
            $request->cliExtraArgs
        );

        if ($request->timeBudgetWarn !== null || $request->timeBudgetFail !== null) {
            foreach ($plan->getJobs() as $jobAbstract) {
                $jobAbstract->applyThresholdOverride(
                    $request->timeBudgetWarn,
                    $request->timeBudgetFail
                );
            }
        }

        // Re-pack the plan with the resolution attached. `FlowPreparer::prepareSingleJob`
        // does not know about the resolution trace; the Command-era code did
        // this rewrap inline, we keep the same shape so consumers (header,
        // renderer) see `effectiveOptions` populated.
        $plan = new FlowPlan(
            $plan->getFlowName(),
            $plan->getJobs(),
            $resolution->getOptions(),
            $plan->getContext(),
            $plan->getSkippedJobs(),
            $plan->getExecutionMode(),
            $plan->getInputFiles(),
            $plan->getExpandedFlows(),
            $resolution
        );

        return JobPreparation::success($plan, $resolution, $config);
    }
}
