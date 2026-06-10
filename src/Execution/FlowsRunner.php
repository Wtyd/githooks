<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use LogicException;
use Symfony\Component\Console\Output\OutputInterface;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
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
use Wtyd\GitHooks\Utils\BranchResolution;
use Wtyd\GitHooks\Utils\BranchResolver;
use Wtyd\GitHooks\Utils\Exception\DetachedHeadException;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * Pure-orchestration handler for `githooks flows <name1> <name2> ...`.
 * Multi-flow variant of {@see FlowRunner}. Handles four invocation modes
 * (spec §1, §3.3):
 *
 *  - single-flow degenerate: 1 arg that is a normal flow → equivalent to `flow X`.
 *  - declarative:            1 arg that is a meta-flow → uses meta-flow options.
 *  - ad-hoc:                 ≥2 normal flows → flows.options + CLI only.
 *  - mixed:                  ≥2 args, at least one meta-flow → flows.options + CLI only.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes parser, preparer,
 *   executor, renderer and emitters; the coupling reflects the surface, not a smell.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Mirrors the four-mode matrix
 *   the spec defines (single/declarative/ad-hoc/mixed) plus the FEAT-2 branch
 *   resolution edge case; splitting per-mode would obscure the cascade.
 */
class FlowsRunner
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
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Collaborators by design;
     *   the two diagnostics ones default to real instances for backward-compatible
     *   construction in unit tests.
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
     * Run the full flows pipeline. Returns the process exit code.
     */
    public function run(FlowsRunRequest $request, OutputInterface $output, RenderOptions $renderOptions): int
    {
        try {
            $preparation = $this->prepare($request, $output);

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
            /** @var ConfigurationResult $config */
            $config = $preparation->config;

            $this->renderer->applyFormat($this->executor, $plan, $renderOptions, $output);
            $this->executor->setThresholdsDisabled($request->timeBudgetDisabled);
            $this->executor->setMemoryBudgetDisabled($request->memoryBudgetDisabled);

            $headerOptions = new HeaderOptions($renderOptions->format, $renderOptions->showProgress);

            // FEAT-14: diagnostics block before the Settings header + runtime node.
            $diagnostics = $this->diagnosticsCollector->collect();
            $startedAt = $this->diagnosticsCollector->now();
            $this->diagnosticsEmitter->emit($diagnostics, $startedAt, $renderOptions->diag, $headerOptions, $output);

            $this->headerEmitter->emit($resolution, $preparation->expandedFlows, $plan->getInputFiles(), $headerOptions, $output);

            $result = $this->executor->execute($plan, $request->dryRun);
            $result->setConfigValidation($config->getValidation());
            $result->setRuntime(new RuntimeBlock($diagnostics, $startedAt, $this->diagnosticsCollector->now()));

            $this->warningsEmitter->emit($config->getValidation(), $output);

            $this->renderer->renderFormattedResult($result, $plan->getOptions(), $renderOptions, $output);

            if ($request->monitor) {
                $this->renderer->renderMonitorReport($result, $output);
            }

            return OutputFormats::exitCodeFor($renderOptions->format, $result->isSuccess());
        } catch (GitHooksExceptionInterface $e) {
            $this->emitStderr($output, $e->getMessage());
            return 1;
        }
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each branch is one mode of the matrix.
     * @SuppressWarnings(PHPMD.NPathComplexity) Modes × error classes × FEAT-2 edge.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Single-pass orchestration.
     */
    public function prepare(FlowsRunRequest $request, OutputInterface $output): FlowsPreparation
    {
        try {
            $config = $this->parser->parse($request->configFile);
        } catch (GitHooksExceptionInterface $e) {
            return FlowsPreparation::failure([$e->getMessage()]);
        }

        if ($config->isLegacy()) {
            return FlowsPreparation::failure([
                "The 'flows' command requires v3 configuration format (hooks/flows/jobs).",
                "Use 'githooks conf:init' to generate the new format.",
            ]);
        }

        if ($config->hasErrors()) {
            return FlowsPreparation::failure($config->getValidation()->getErrors());
        }

        $validationErrors = $this->validateFlowNames($request->flowNames, $config);
        if ($validationErrors !== []) {
            return FlowsPreparation::failure($validationErrors);
        }

        if (!empty($request->excludeJobs) && !empty($request->onlyJobs)) {
            return FlowsPreparation::failure(['Options --exclude-jobs and --only-jobs cannot be used together.']);
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

        // FEAT-2: resolve the branch only for a single normal flow that declares `on`.
        try {
            $branchResolution = $this->resolveBranchForSingleFlow($request, $config);
        } catch (DetachedHeadException $e) {
            return FlowsPreparation::failure([$e->getMessage()]);
        }

        $resolver = new EffectiveOptionsResolver();
        [$resolution, $isSingleFlow, $isDeclarative] = $this->resolveOptionsForMode(
            $resolver,
            $config,
            $request,
            $invocationMode,
            $branchResolution
        );

        // FEAT-2 re-sync (mirrors FlowCommand): only when single-flow `on` is in play.
        if (
            $invocationMode === null
            && $branchResolution !== null
            && $resolution->getExecutionMode() !== ExecutionMode::FULL
            && $resolution->getTrace()['executionMode']['source'] !== EffectiveOptionsResolver::SOURCE_DEFAULT
        ) {
            $invocationMode = $resolution->getExecutionMode();
        }

        $this->emitIgnoredOptionsWarning($request->flowNames, $config, $isSingleFlow, $isDeclarative, $output);

        $aggregateFlowName = $this->buildRunIdentifier($request->flowNames);

        $plan = $this->preparer->prepareMultiple(
            $request->flowNames,
            $aggregateFlowName,
            $config,
            $resolution->getOptions(),
            $context,
            $request->excludeJobs,
            $request->onlyJobs,
            $invocationMode
        );

        $expandedFlows = $isSingleFlow ? null : $plan->getExpandedFlows();

        $plan = new FlowPlan(
            $plan->getFlowName(),
            $plan->getJobs(),
            $resolution->getOptions(),
            $plan->getContext(),
            $plan->getSkippedJobs(),
            $plan->getExecutionMode(),
            $plan->getInputFiles(),
            $expandedFlows,
            $resolution,
            $plan->getDependencyGraph()
        );

        return FlowsPreparation::success($plan, $resolution, $config, $isSingleFlow, $isDeclarative, $expandedFlows);
    }

    /**
     * @param string[] $argNames
     * @return string[] error messages — empty if all names exist.
     */
    private function validateFlowNames(array $argNames, ConfigurationResult $config): array
    {
        $errors = [];
        $invalid = false;
        foreach ($argNames as $name) {
            if ($config->getFlow($name) === null) {
                $errors[] = "Flow '$name' is not defined in the configuration file.";
                $invalid = true;
            }
        }

        if (!$invalid) {
            return [];
        }

        [$normalFlows, $metaFlows] = $this->splitFlowsByKind($config);
        if (!empty($normalFlows)) {
            $errors[] = 'Available flows: ' . implode(', ', $normalFlows);
        }
        if (!empty($metaFlows)) {
            $errors[] = 'Available meta-flows: ' . implode(', ', $metaFlows);
        }
        return $errors;
    }

    /**
     * @return array{0: string[], 1: string[]}
     */
    private function splitFlowsByKind(ConfigurationResult $config): array
    {
        $normal = [];
        $meta = [];
        foreach ($config->getFlows() as $flow) {
            if ($flow->isMetaFlow()) {
                $meta[] = $flow->getName();
            } else {
                $normal[] = $flow->getName();
            }
        }
        return [$normal, $meta];
    }

    /**
     * @return array{0: EffectiveOptionsResolution, 1: bool, 2: bool}
     *         Returns [resolution, isSingleFlow, isDeclarative]
     */
    private function resolveOptionsForMode(
        EffectiveOptionsResolver $resolver,
        ConfigurationResult $config,
        FlowsRunRequest $request,
        ?string $invocationMode,
        ?BranchResolution $branchResolution
    ): array {
        $unique = array_values(array_unique($request->flowNames));

        if (count($unique) === 1) {
            $flow = $config->getFlow($unique[0]);
            // validateFlowNames() guards against null; this assert reassures phpstan
            // without changing runtime behaviour (failure-fast if the contract breaks).
            if ($flow === null) {
                throw new LogicException("Flow '{$unique[0]}' disappeared between validation and option resolution.");
            }
            $isMeta = $flow->isMetaFlow();
            $isSingleFlow = !$isMeta;
            $isDeclarative = $isMeta;
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
            return [$resolution, $isSingleFlow, $isDeclarative];
        }

        return [
            $resolver->resolveMultiple(
                $config,
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
                $request->cliStats
            ),
            false,
            false,
        ];
    }

    /**
     * FEAT-2: resolve the current branch only when the run is a single normal
     * flow that declares an `on` map.
     *
     * @throws DetachedHeadException when `on` must be evaluated but the branch
     *         cannot be detected and no --branch / $GITHOOKS_BRANCH was given.
     */
    private function resolveBranchForSingleFlow(
        FlowsRunRequest $request,
        ConfigurationResult $config
    ): ?BranchResolution {
        $unique = array_values(array_unique($request->flowNames));
        if (count($unique) !== 1) {
            return null;
        }

        $flow = $config->getFlow($unique[0]);
        // BUG-30: a meta-flow invoked alone honours its `on` exactly like a normal
        // flow — the resolver is meta-agnostic, so the only thing needed is to
        // resolve the branch. (Mirrors how a meta-flow's `options` already apply
        // when invoked alone.) `on` is only resolved for a lone flow either way.
        if ($flow === null || $flow->getOn() === null) {
            return null;
        }

        return (new BranchResolver())->resolve(
            $request->cliBranch !== null && $request->cliBranch !== '' ? $request->cliBranch : null,
            $this->fileUtils
        );
    }

    /**
     * @param string[] $argNames
     */
    private function emitIgnoredOptionsWarning(
        array $argNames,
        ConfigurationResult $config,
        bool $isSingleFlow,
        bool $isDeclarative,
        OutputInterface $output
    ): void {
        if ($isSingleFlow || $isDeclarative) {
            return;
        }

        $ignored = [];
        foreach (array_unique($argNames) as $name) {
            $flow = $config->getFlow($name);
            if ($flow !== null && $flow->getOptions() !== null) {
                $ignored[] = $name;
            }
        }

        if ($ignored === []) {
            return;
        }

        // REQ-018 advisory: route to stderr so pipelines capturing stdout
        // (CI, --format=json, scripted tooling) don't pick up the message.
        $this->emitStderr(
            $output,
            "⚠️  Options declared in '" . implode("', '", $ignored) . "' are ignored in multi-flow runs."
            . "\n  Effective options come from flows.options + CLI; see header below."
        );
    }

    /**
     * Build the user-facing run identifier following spec §4.4.
     *
     * @param string[] $argNames
     */
    private function buildRunIdentifier(array $argNames): string
    {
        $unique = array_values(array_unique($argNames));
        if (count($unique) === 1) {
            return $unique[0];
        }
        return implode('+', $unique);
    }
}
