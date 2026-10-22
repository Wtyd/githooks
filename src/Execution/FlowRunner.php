<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Symfony\Component\Console\Output\OutputInterface;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Execution\Concerns\EmitsRunnerStderr;
use Wtyd\GitHooks\Flaky\FlapAggregator;
use Wtyd\GitHooks\Flaky\FlapReport;
use Wtyd\GitHooks\Flaky\FlapReportRenderer;
use Wtyd\GitHooks\Flaky\JunitReport;
use Wtyd\GitHooks\History\RunHistoryStore;
use Wtyd\GitHooks\Output\ConditionsHeaderEmitter;
use Wtyd\GitHooks\Output\ConfigWarningsEmitter;
use Wtyd\GitHooks\Output\Diagnostics\DiagnosticsCollector;
use Wtyd\GitHooks\Output\DiagnosticsHeaderEmitter;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\HeaderOptions;
use Wtyd\GitHooks\Output\NullOutputHandler;
use Wtyd\GitHooks\Output\OutputFormats;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Two entry points sharing
 *   prepare(): the single run and the repeated run of FEAT-25, whose tail
 *   (flap report instead of a FlowResult) is deliberately kept out of run().
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

    private DiagnosticsCollector $diagnosticsCollector;

    private DiagnosticsHeaderEmitter $diagnosticsEmitter;

    private RunHistoryStore $historyStore;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Collaborators by design;
     *   the trailing ones default to real instances for backward-compatible
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
        ?DiagnosticsHeaderEmitter $diagnosticsEmitter = null,
        ?RunHistoryStore $historyStore = null
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
        $this->historyStore = $historyStore ?? new RunHistoryStore();
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

            // FEAT-14: collect the runner snapshot, emit the diagnostics block
            // (when applicable) BEFORE the Settings header, then keep startedAt
            // for the always-present runtime node.
            $diagnostics = $this->diagnosticsCollector->collect();
            $startedAt = $this->diagnosticsCollector->now();
            $this->diagnosticsEmitter->emit($diagnostics, $startedAt, $renderOptions->diag, $headerOptions, $output);

            $this->headerEmitter->emit($resolution, $plan->getExpandedFlows(), $plan->getInputFiles(), $headerOptions, $output);

            $result = $this->executor->execute($plan, $request->dryRun);
            $result->setConfigValidation($config->getValidation());
            $result->setRuntime(new RuntimeBlock($diagnostics, $startedAt, $this->diagnosticsCollector->now()));

            $this->warningsEmitter->emit($config->getValidation(), $output);

            $this->renderer->renderFormattedResult($result, $plan->getOptions(), $renderOptions, $output);

            $this->persistHistory($result, $plan->getOptions()->getHistorySize(), $request->saveHistory, $request->dryRun, $startedAt);

            if ($request->monitor) {
                $this->renderer->renderMonitorReport($result, $output);
            }

            return OutputFormats::exitCodeFor($renderOptions->format, $result->isSuccess());
        } catch (GitHooksExceptionInterface $e) {
            // To STDERR so --format=json/junit/sarif/codeclimate stdout stays clean (BUG-5).
            $this->emitStderr($output, $e->getMessage());
            return 1;
        }
    }

    /**
     * FEAT-25: run the flow `repeat` times and report, test by test, whose
     * outcome changed between passes. Shares prepare() with run(); the tail
     * differs entirely — one flap report instead of one FlowResult — which is
     * why it is a sibling and not a branch inside run() (see
     * tests/Unit/Flaky/factors.md, table B).
     */
    public function runRepeated(FlowRunRequest $request, OutputInterface $output, RenderOptions $renderOptions): int
    {
        try {
            $format = $this->repeatFormat($renderOptions->format, $output);
            if ($format === null) {
                return 1;
            }

            $preparation = $this->prepare($request);
            foreach ($preparation->errors as $error) {
                $this->emitError($output, $error);
            }
            if (!$preparation->success) {
                return 1;
            }
            $plan = $preparation->plan;
            $resolution = $preparation->resolution;
            $config = $preparation->config;
            if ($plan === null || $resolution === null || $config === null) {
                return 1;
            }

            $junitPaths = $this->junitPaths($plan, $output);
            if ($junitPaths === null) {
                return 1;
            }

            $this->warnInertFlags($request, $renderOptions, $output);
            $this->executor->setOutputHandler(new NullOutputHandler());
            $this->executor->setStructuredFormat(false);
            $this->executor->setThresholdsDisabled($request->timeBudgetDisabled);
            $this->executor->setMemoryBudgetDisabled($request->memoryBudgetDisabled);

            $this->headerEmitter->emit(
                $resolution,
                $plan->getExpandedFlows(),
                $plan->getInputFiles(),
                new HeaderOptions($format, $renderOptions->showProgress),
                $output
            );

            $passes = $this->runPasses($plan, $junitPaths, $request->repeat, $format, $renderOptions, $output);

            $this->warningsEmitter->emit($config->getValidation(), $output);

            $report = (new FlapAggregator($request->flapThreshold))->aggregate($plan->getFlowName(), $junitPaths, $passes);
            $this->renderFlapReport($report, $format, $renderOptions, $output);

            return $report->exitCode();
        } catch (GitHooksExceptionInterface $e) {
            $this->emitStderr($output, $e->getMessage());
            return 1;
        }
    }

    /**
     * Only text and json can describe N passes; the other formats describe a
     * single run. An unknown value falls back to text, as it does in run().
     */
    private function repeatFormat(string $format, OutputInterface $output): ?string
    {
        if ($format === '' || $format === 'text') {
            return 'text';
        }
        if ($format === 'json') {
            return 'json';
        }
        if (in_array($format, OutputFormats::SUPPORTED, true)) {
            $this->emitError($output, "--format=$format describes a single run and cannot be used with --repeat. Use text or json.");
            return null;
        }
        $this->emitStderr($output, "Unknown format '$format'. Using text output.");
        return 'text';
    }

    /**
     * Junit file of each job that declares one, keyed by job name. Two jobs
     * writing the same file would overwrite each other's results, so that is
     * refused here — not in conf:check, where `extends` makes a shared
     * `log-junit` legitimate as long as the jobs never run in the same flow.
     *
     * @return array<string, string>|null null when the plan cannot be repeated (already reported)
     */
    private function junitPaths(FlowPlan $plan, OutputInterface $output): ?array
    {
        $paths = [];
        $owners = [];
        foreach ($plan->getJobs() as $job) {
            $path = $job->getJunitPath();
            if ($path === null) {
                continue;
            }
            $absolute = $this->absolutePath($path);
            if (isset($owners[$absolute])) {
                $this->emitError(
                    $output,
                    "Jobs '{$owners[$absolute]}' and '{$job->getName()}' both write their junit file to '$path'; "
                    . "--repeat cannot tell their tests apart. Give each job its own 'junit' path."
                );
                return null;
            }
            $owners[$absolute] = $job->getName();
            $paths[$job->getName()] = $path;
        }

        if ($paths === []) {
            $this->emitError(
                $output,
                "--repeat needs at least one job in flow '{$plan->getFlowName()}' that declares 'junit' "
                . "(or 'log-junit' on phpunit and paratest jobs)."
            );
            return null;
        }

        return $paths;
    }

    /**
     * Run the passes. Before each one every declared junit file is removed:
     * a pass that crashes without writing one must read as "not written",
     * never as the previous pass's results.
     *
     * @param array<string, string> $junitPaths
     * @return list<array{reports: array<string, JunitReport>, jobs: array<string, string>}>
     */
    private function runPasses(
        FlowPlan $plan,
        array $junitPaths,
        int $repeat,
        string $format,
        RenderOptions $renderOptions,
        OutputInterface $output
    ): array {
        $passes = [];
        for ($pass = 1; $pass <= $repeat; $pass++) {
            $stale = $this->clearJunitFiles($junitPaths);

            $result = $this->executor->execute($plan);

            $reports = [];
            foreach ($junitPaths as $job => $path) {
                $reports[$job] = in_array($job, $stale, true)
                    ? JunitReport::stale()
                    : JunitReport::fromFile($this->absolutePath($path));
            }
            $passes[] = ['reports' => $reports, 'jobs' => $this->jobOutcomes($result)];

            $this->emitPassProgress($result, $pass, $repeat, $format, $renderOptions, $output);
        }
        return $passes;
    }

    /**
     * Remove every declared junit file. A file that is still there afterwards
     * (no permission, …) would be read as this pass's results, so its job is
     * returned and the pass reported as an anomaly for it.
     *
     * @param array<string, string> $junitPaths
     * @return string[] jobs whose junit file could not be removed
     */
    private function clearJunitFiles(array $junitPaths): array
    {
        $stale = [];
        foreach ($junitPaths as $job => $path) {
            $absolute = $this->absolutePath($path);
            if (!is_file($absolute)) {
                continue;
            }
            try {
                unlink($absolute);
            } catch (\Throwable $e) {
                // The check below decides; the reason does not change the outcome.
            }
            clearstatcache(true, $absolute);
            if (is_file($absolute)) {
                $stale[] = (string) $job;
            }
        }
        return $stale;
    }

    /**
     * @return array<string, string> job name => FlapReport::JOB_*
     */
    private function jobOutcomes(FlowResult $result): array
    {
        $outcomes = [];
        foreach ($result->getJobResults() as $jobResult) {
            if ($jobResult->isSkipped()) {
                $outcomes[$jobResult->getJobName()] = FlapReport::JOB_SKIPPED;
                continue;
            }
            $outcomes[$jobResult->getJobName()] = $jobResult->isSuccess() ? FlapReport::JOB_PASSED : FlapReport::JOB_FAILED;
        }
        return $outcomes;
    }

    /**
     * One line per pass. On stdout for text, where it is part of the log; on
     * stderr for json, and only on a terminal or with --show-progress, so
     * stdout stays a single parseable document (BUG-5).
     */
    private function emitPassProgress(
        FlowResult $result,
        int $pass,
        int $repeat,
        string $format,
        RenderOptions $renderOptions,
        OutputInterface $output
    ): void {
        $line = sprintf(
            'Pass %d/%d: jobs %d passed, %d failed, %d skipped (%s)',
            $pass,
            $repeat,
            $result->getPassedCount(),
            $result->getFailedCount(),
            $result->getSkippedCount(),
            $result->getTotalTime()
        );

        if ($format === 'text') {
            $output->writeln($line);
            return;
        }
        if ($renderOptions->showProgress || $this->stderrIsTty()) {
            $this->emitStderr($output, $line);
        }
    }

    private function renderFlapReport(FlapReport $report, string $format, RenderOptions $renderOptions, OutputInterface $output): void
    {
        $renderer = new FlapReportRenderer();
        if ($format === 'json') {
            $this->renderer->writeStructuredPayload($renderer->renderJson($report), $renderOptions, $output);
            return;
        }
        $output->writeln('');
        $output->writeln($renderer->renderText($report));
    }

    /**
     * The options that describe a single run have nothing to say about N
     * passes. Said once, on stderr, so a CI log shows why no history or
     * report file appeared.
     */
    private function warnInertFlags(FlowRunRequest $request, RenderOptions $renderOptions, OutputInterface $output): void
    {
        $inert = array_keys(array_filter([
            '--save-history' => $request->saveHistory,
            '--monitor'      => $request->monitor,
            '--diag'         => $renderOptions->diag,
            '--report-*'     => $renderOptions->cliReports !== [],
        ]));
        if ($inert !== []) {
            $this->emitStderr($output, 'Warning: ' . implode(', ', $inert) . ' ignored with --repeat.');
        }
    }

    /**
     * Jobs run as child processes of this one, without an explicit working
     * directory, so a relative junit path resolves against ours.
     */
    private function absolutePath(string $path): string
    {
        if ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }
        return getcwd() . DIRECTORY_SEPARATOR . $path;
    }

    private function stderrIsTty(): bool
    {
        return defined('STDERR') && function_exists('stream_isatty') && stream_isatty(STDERR);
    }

    /**
     * FEAT-5: persist the run to `.githooks/history/` when enabled. Activation is
     * opt-in (--save-history OR history-size > 0) and never on dry-run. When the
     * flag is set without a configured size, the window defaults to 100. A
     * history write must never break the run, so any failure is swallowed.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Pre-resolved CLI toggles.
     */
    private function persistHistory(
        FlowResult $result,
        int $historySize,
        bool $saveHistory,
        bool $dryRun,
        string $startedAt
    ): void {
        if ($dryRun || (!$saveHistory && $historySize <= 0)) {
            return;
        }
        $size = $historySize > 0 ? $historySize : 100;
        try {
            $this->historyStore->persist($result, $startedAt, true, $size);
        } catch (\Throwable $e) {
            return; // history is best-effort; a failed write never fails the run
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
