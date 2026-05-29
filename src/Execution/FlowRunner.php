<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Console\Output\OutputInterface;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Execution\Concerns\EmitsRunnerStderr;
use Wtyd\GitHooks\Output\ConditionsHeaderEmitter;
use Wtyd\GitHooks\Output\ConfigWarningsEmitter;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\HeaderOptions;
use Wtyd\GitHooks\Output\RenderOptions;
use Wtyd\GitHooks\Utils\BranchResolver;
use Wtyd\GitHooks\Utils\Exception\DetachedHeadException;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * Pure-orchestration handler for `githooks flow <name>`. Equivalent to
 * {@see JobRunner} but for a full flow: parses the config, finds the flow,
 * resolves the effective options cascade (with optional branch resolution
 * for FEAT-2 `on` rules), prepares the FlowPlan, then runs the executor
 * and renders the result.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes parser, preparer,
 *   executor, renderer and emitters. The coupling reflects the surface.
 */
class FlowRunner
{
    use EmitsRunnerStderr;

    private ConfigurationParser $parser;

    private FlowPreparer $preparer;

    private FileUtilsInterface $fileUtils;

    private FlowExecutor $executor;

    private FlowResultRenderer $renderer;

    private ConditionsHeaderEmitter $headerEmitter;

    private ConfigWarningsEmitter $warningsEmitter;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Seven collaborators by design.
     */
    public function __construct(
        ConfigurationParser $parser,
        FlowPreparer $preparer,
        FileUtilsInterface $fileUtils,
        FlowExecutor $executor,
        FlowResultRenderer $renderer,
        ConditionsHeaderEmitter $headerEmitter,
        ConfigWarningsEmitter $warningsEmitter
    ) {
        $this->parser = $parser;
        $this->preparer = $preparer;
        $this->fileUtils = $fileUtils;
        $this->executor = $executor;
        $this->renderer = $renderer;
        $this->headerEmitter = $headerEmitter;
        $this->warningsEmitter = $warningsEmitter;
    }

    /**
     * Run the full flow pipeline: prepare → applyFormat → emit header →
     * execute → emit warnings → render result → optional monitor. Returns
     * the process exit code.
     */
    public function run(FlowRunRequest $request, OutputInterface $output, RenderOptions $renderOptions): int
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
            $this->headerEmitter->emit($resolution, $plan->getExpandedFlows(), $plan->getInputFiles(), $headerOptions, $output);

            $result = $this->executor->execute($plan, $request->dryRun);
            $result->setConfigValidation($config->getValidation());

            $this->warningsEmitter->emit($config->getValidation(), $output);

            $this->renderer->renderFormattedResult($result, $plan->getOptions(), $renderOptions, $output);

            if ($request->monitor) {
                $this->renderer->renderMonitorReport($result, $output);
            }

            return $result->isSuccess() ? 0 : 1;
        } catch (GitHooksExceptionInterface $e) {
            // To STDERR so --format=json/junit/sarif/codeclimate stdout stays clean (BUG-5).
            $this->emitStderr($output, $e->getMessage());
            return 1;
        }
    }

    /**
     * Parse → legacy/error checks → find flow → context → cascade options →
     * preparer → re-pack plan with the resolution attached.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each branch is one class of
     *   pre-execution error.
     * @SuppressWarnings(PHPMD.NPathComplexity) Sequential pipeline with linear
     *   early-returns.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Single-pass orchestration.
     */
    public function prepare(FlowRunRequest $request): FlowPreparation
    {
        try {
            $config = $this->parser->parse($request->configFile);
        } catch (GitHooksExceptionInterface $e) {
            return FlowPreparation::failure([$e->getMessage()]);
        }

        if ($config->isLegacy()) {
            return FlowPreparation::failure([
                "The 'flow' command requires v3 configuration format (hooks/flows/jobs).",
                "Use 'githooks conf:init' to generate the new format.",
            ]);
        }

        if ($config->hasErrors()) {
            return FlowPreparation::failure($config->getValidation()->getErrors());
        }

        $flow = $config->getFlow($request->flowName);
        if ($flow === null) {
            $errors = ["Flow '{$request->flowName}' is not defined in the configuration file."];
            $availableFlows = array_keys($config->getFlows());
            if (!empty($availableFlows)) {
                $errors[] = 'Available flows: ' . implode(', ', $availableFlows);
            }
            return FlowPreparation::failure($errors);
        }

        if (!empty($request->excludeJobs) && !empty($request->onlyJobs)) {
            return FlowPreparation::failure(['Options --exclude-jobs and --only-jobs cannot be used together.']);
        }

        $invocationMode = $request->invocationMode;
        if ($request->inputFiles !== null) {
            $context = ExecutionContext::forInputFiles($request->inputFiles, $this->fileUtils);
            $invocationMode = ExecutionMode::FAST;
        } else {
            $mainBranch = $config->getGlobalOptions()->getMainBranch()
                ?? $this->fileUtils->detectMainBranch();
            $context = ExecutionContext::create($this->fileUtils, $mainBranch);
        }

        // FEAT-2: only resolve the branch when the flow declares `on`.
        // Detached HEAD must not break flows that don't depend on it.
        $branchResolution = null;
        if ($flow->getOn() !== null) {
            try {
                $branchResolution = (new BranchResolver())->resolve(
                    $request->cliBranch !== null && $request->cliBranch !== '' ? $request->cliBranch : null,
                    $this->fileUtils
                );
            } catch (DetachedHeadException $e) {
                return FlowPreparation::failure([$e->getMessage()]);
            }
        }

        $resolver = new EffectiveOptionsResolver();
        $resolution = $resolver->resolveSingle(
            $config,
            $flow,
            $request->cliFailFast,
            $request->cliProcesses,
            $invocationMode,
            $request->timeBudgetWarn,
            $request->timeBudgetFail,
            $request->timeBudgetDisabled,
            $request->memoryWarnAbove,
            $request->memoryFailAbove,
            $request->memoryBudgetDisabled,
            $request->cliAllocator,
            $request->cliStats,
            $branchResolution
        );

        // FEAT-2 re-sync (see FlowCommand for the rationale).
        if (
            $invocationMode === null
            && $resolution->getTrace()['executionMode']['source'] !== EffectiveOptionsResolver::SOURCE_DEFAULT
            && $resolution->getExecutionMode() !== ExecutionMode::FULL
        ) {
            $invocationMode = $resolution->getExecutionMode();
        }

        $plan = $this->preparer->prepare(
            $flow,
            $config,
            $context,
            $request->excludeJobs,
            $request->onlyJobs,
            $invocationMode
        );

        $plan = new FlowPlan(
            $plan->getFlowName(),
            $plan->getJobs(),
            $resolution->getOptions(),
            $plan->getContext(),
            $plan->getSkippedJobs(),
            $plan->getExecutionMode(),
            $plan->getInputFiles(),
            $plan->getExpandedFlows(),
            $resolution,
            $plan->getDependencyGraph()
        );

        return FlowPreparation::success($plan, $resolution, $config);
    }
}
