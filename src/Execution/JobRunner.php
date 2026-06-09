<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Console\Output\OutputInterface;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Execution\Concerns\EmitsRunnerStderr;
use Wtyd\GitHooks\Output\ConditionsHeaderEmitter;
use Wtyd\GitHooks\Output\ConfigWarningsEmitter;
use Wtyd\GitHooks\Output\Diagnostics\DiagnosticsCollector;
use Wtyd\GitHooks\Output\DiagnosticsHeaderEmitter;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\HeaderOptions;
use Wtyd\GitHooks\Output\OutputFormats;
use Wtyd\GitHooks\Output\RenderOptions;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * Pure-orchestration handler for `githooks job <name>`. The Command layer
 * builds a {@see JobRunRequest} from the parsed CLI flags and a
 * {@see RenderOptions} from the format/output/report flags, then delegates
 * the full pipeline to {@see run()}. Lives outside the Symfony Command
 * hierarchy so the eight-step business pipeline plus rendering ceremony can
 * be unit-tested with synthetic fakes without booting Laravel-Zero or
 * `$this->artisan()`.
 *
 * Phase 1 (commit `e6d0ad0`): extracted the prepare() pipeline (parse →
 * validate → find job → context → cascade options → preparer → thresholds →
 * rewrap). Phase 2b (this revision): swallows the render ceremony
 * (applyFormat → emit header → execute → emit warnings → render result),
 * collapsing JobCommand::handle() to a thin adapter.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes parser, preparer, executor,
 *   renderer and emitters; the coupling reflects the surface, not a smell.
 */
class JobRunner
{
    use EmitsRunnerStderr;

    private ConfigurationParser $parser;

    private FlowPreparer $preparer;

    private FileUtilsInterface $fileUtils;

    private FlowExecutor $executor;

    private FlowResultRenderer $renderer;

    private ConditionsHeaderEmitter $headerEmitter;

    private ConfigWarningsEmitter $warningsEmitter;

    private DiagnosticsCollector $diagnosticsCollector;

    private DiagnosticsHeaderEmitter $diagnosticsEmitter;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Collaborators by design; the two
     *   diagnostics ones default to real instances for backward-compatible construction
     *   in unit tests.
     */
    public function __construct(
        ConfigurationParser $parser,
        FlowPreparer $preparer,
        FileUtilsInterface $fileUtils,
        FlowExecutor $executor,
        FlowResultRenderer $renderer,
        ConditionsHeaderEmitter $headerEmitter,
        ConfigWarningsEmitter $warningsEmitter,
        ?DiagnosticsCollector $diagnosticsCollector = null,
        ?DiagnosticsHeaderEmitter $diagnosticsEmitter = null
    ) {
        $this->parser = $parser;
        $this->preparer = $preparer;
        $this->fileUtils = $fileUtils;
        $this->executor = $executor;
        $this->renderer = $renderer;
        $this->headerEmitter = $headerEmitter;
        $this->warningsEmitter = $warningsEmitter;
        $this->diagnosticsCollector = $diagnosticsCollector ?? new DiagnosticsCollector();
        $this->diagnosticsEmitter = $diagnosticsEmitter ?? new DiagnosticsHeaderEmitter();
    }

    /**
     * Run the full job pipeline: prepare → applyFormat → emit header →
     * execute → emit warnings → render result. Returns the process exit code
     * (0 success, 1 failure).
     *
     * The Command's only responsibility above this is: validate pre-execution
     * flags (unknown-options, execution-mode mutex), build the request DTOs,
     * and call this method.
     */
    public function run(JobRunRequest $request, OutputInterface $output, RenderOptions $renderOptions): int
    {
        try {
            $preparation = $this->prepare($request);

            foreach ($preparation->errors as $error) {
                $this->emitError($output, $error);
            }
            if (!$preparation->success) {
                return 1;
            }

            /** @var FlowPlan $plan */
            $plan = $preparation->plan;
            /** @var EffectiveOptionsResolution $resolution */
            $resolution = $preparation->resolution;
            /** @var \Wtyd\GitHooks\Configuration\ConfigurationResult $config */
            $config = $preparation->config;

            $this->renderer->applyFormat($this->executor, $plan, $renderOptions, $output);
            $this->executor->setThresholdsDisabled($request->timeBudgetDisabled);
            $this->executor->setMemoryBudgetDisabled($request->memoryBudgetDisabled);

            $headerOptions = new HeaderOptions($renderOptions->format, $renderOptions->showProgress);

            // FEAT-14: diagnostics block before the Settings header + runtime node.
            $diagnostics = $this->diagnosticsCollector->collect();
            $startedAt = $this->diagnosticsCollector->now();
            $this->diagnosticsEmitter->emit($diagnostics, $startedAt, $renderOptions->diag, $headerOptions, $output);

            $this->headerEmitter->emit($resolution, null, $plan->getInputFiles(), $headerOptions, $output);

            $result = $this->executor->execute($plan, $request->dryRun);
            $result->setConfigValidation($config->getValidation());
            $result->setRuntime(new RuntimeBlock($diagnostics, $startedAt, $this->diagnosticsCollector->now()));

            $this->warningsEmitter->emit($config->getValidation(), $output);

            $this->renderer->renderFormattedResult($result, $plan->getOptions(), $renderOptions, $output);

            return OutputFormats::exitCodeFor($renderOptions->format, $result->isSuccess());
        } catch (GitHooksExceptionInterface $e) {
            // To STDERR so --format=json/junit/sarif/codeclimate stdout stays clean (BUG-5).
            $this->emitStderr($output, $e->getMessage());
            return 1;
        }
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

        // FEAT-16: attach the commit-message file path for an inline commit-msg
        // job. For any other type the flag is inert — warn and ignore (§9.14).
        if ($request->commitMessageFile !== null) {
            if ($jobConfig->getType() === 'commit-msg') {
                $context = $context->withCommitMessageFile($request->commitMessageFile);
            } else {
                $config->getValidation()->addWarning(
                    "flag --message-file/--message ignored: target job '{$request->jobName}' is not commit-msg."
                );
            }
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

        // BUG-28: mirror the time override for memory — without this the
        // `--memory-warn-above` / `--memory-fail-above` flags are parsed into the
        // request but never reach the job's threshold (they were inert on `job`).
        if ($request->memoryWarnAbove !== null || $request->memoryFailAbove !== null) {
            foreach ($plan->getJobs() as $jobAbstract) {
                $jobAbstract->applyMemoryThresholdOverride(
                    $request->memoryWarnAbove,
                    $request->memoryFailAbove
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
