<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsConditionsHeader;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsConfigWarnings;
use Wtyd\GitHooks\App\Commands\Concerns\FormatsOutput;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesAllocatorFlag;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesInputFiles;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesMemoryBudgetFlags;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesStatsFlag;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesTimeBudgetFlags;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolver;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolution;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Execution\InputFilesResolver;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * `flows <name1> <name2> ...` — run several flows or a meta-flow as a single plan.
 *
 * Four invocation modes (spec §1, §3.3):
 *  - single-flow degenerate: 1 arg that is a normal flow → equivalent to `flow X`.
 *  - declarative:            1 arg that is a meta-flow → uses meta-flow options.
 *  - ad-hoc:                 ≥2 normal flows → flows.options + CLI only.
 *  - mixed:                  ≥2 args, at least one meta-flow → flows.options + CLI only.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates parser, preparer, executor and resolvers.
 */
class FlowsCommand extends Command
{
    use EmitsConditionsHeader;
    use EmitsConfigWarnings;
    use FormatsOutput;
    use ResolvesAllocatorFlag;
    use ResolvesInputFiles;
    use ResolvesMemoryBudgetFlags;
    use ResolvesStatsFlag;
    use ResolvesTimeBudgetFlags;

    protected $signature = 'flows
                            {names* : One or more flow or meta-flow names}
                            {--fail-fast : Stop on first job failure}
                            {--processes= : Number of parallel processes}
                            {--exclude-jobs= : Comma-separated list of jobs to skip}
                            {--only-jobs= : Comma-separated list of jobs to run (others skipped)}
                            {--format= : Output format (text, json, junit, codeclimate, sarif)}
                            {--output= : Write the structured payload to PATH (default: stdout)}
                            {--report-json= : Also write a JSON v2 report to PATH}
                            {--report-junit= : Also write a JUnit XML report to PATH}
                            {--report-sarif= : Also write a SARIF 2.1.0 report to PATH}
                            {--report-codeclimate= : Also write a Code Climate JSON report to PATH}
                            {--no-reports : Ignore the `reports` section from config (--report-* flags still apply)}
                            {--dry-run : Show commands without executing}
                            {--fast : Fast mode — accelerable jobs analyze only staged files instead of full paths}
                            {--fast-branch : Fast-branch mode — accelerable jobs analyze branch diff files instead of full paths}
                            {--fast-branch-fallback= : Fallback strategy (fast|full)}
                            {--files= : CSV of files to filter accelerable jobs by (mutually exclusive with --files-from)}
                            {--files-from= : Path to a manifest file with one path per line (mutually exclusive with --files)}
                            {--exclude-pattern= : CSV of glob patterns excluded from --files / --files-from input}
                            {--monitor : Show thread usage report after execution}
                            {--warn-after= : Warn when total job time (seconds) reaches this threshold}
                            {--fail-after= : Fail when total job time (seconds) reaches this threshold}
                            {--no-time-budget : Disable time-budget evaluation for this run (per-job and flow)}
                            {--memory-warn-above= : Warn when peak simultaneous RSS (MB) crosses this threshold}
                            {--memory-fail-above= : Fail when peak simultaneous RSS (MB) crosses this threshold}
                            {--no-memory-budget : Disable memory-budget evaluation for this run (per-job and flow)}
                            {--allocator= : Resource admission strategy (fifo|greedy)}
                            {--stats : Print a final stats table with peak cores/memory per job and emit the stats block in JSON v2}
                            {--no-ci : Disable auto-detection of CI environment annotations}
                            {--show-progress : Force progress emission on stderr even when not a TTY}
                            {--config= : Path to configuration file}';

    protected $description = 'Execute several flows (or a declarative meta-flow) as a single plan';

    private ConfigurationParser $parser;

    private FlowPreparer $preparer;

    private FlowExecutor $executor;

    private InputFilesResolver $inputFilesResolver;

    public function __construct(
        ConfigurationParser $parser,
        FlowPreparer $preparer,
        FlowExecutor $executor,
        InputFilesResolver $inputFilesResolver
    ) {
        parent::__construct();
        $this->ignoreValidationErrors();
        $this->parser = $parser;
        $this->preparer = $preparer;
        $this->executor = $executor;
        $this->inputFilesResolver = $inputFilesResolver;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Orchestrates parse, validate, resolve, prepare, render
     * @SuppressWarnings(PHPMD.NPathComplexity) Each branch (modes, errors, output) adds an independent path
     */
    public function handle(): int
    {
        /** @var string[] $argNames */
        $argNames = (array) $this->argument('names');
        $configFile = strval($this->option('config'));

        try {
            $config = $this->parser->parse($configFile);

            if ($config->isLegacy()) {
                $this->error("The 'flows' command requires v3 configuration format (hooks/flows/jobs).");
                $this->warn("Use 'githooks conf:init' to generate the new format.");
                return 1;
            }

            if ($config->hasErrors()) {
                foreach ($config->getValidation()->getErrors() as $error) {
                    $this->error($error);
                }
                return 1;
            }

            if (!$this->validateFlowNames($argNames, $config)) {
                return 1;
            }

            $excludeJobs = $this->csvOption('exclude-jobs');
            $onlyJobs = $this->csvOption('only-jobs');
            if (!empty($excludeJobs) && !empty($onlyJobs)) {
                $this->error('Options --exclude-jobs and --only-jobs cannot be used together.');
                return 1;
            }

            $fileUtils = $this->getLaravel()->make(FileUtilsInterface::class);

            $invocationMode = null;
            if ($this->option('fast')) {
                $invocationMode = ExecutionMode::FAST;
            } elseif ($this->option('fast-branch')) {
                $invocationMode = ExecutionMode::FAST_BRANCH;
            }

            $mainBranch = $config->getGlobalOptions()->getMainBranch()
                ?? $fileUtils->detectMainBranch();

            $inputFilesResolution = $this->resolveInputFilesFlags(true);
            if ($inputFilesResolution !== null) {
                $context = ExecutionContext::forInputFiles($inputFilesResolution, $fileUtils);
                $invocationMode = ExecutionMode::FAST;
            } else {
                $context = ExecutionContext::create($fileUtils, $mainBranch);
            }

            $cliFailFast = $this->option('fail-fast') ? true : null;
            $cliProcesses = $this->option('processes') !== null ? (int) $this->option('processes') : null;
            $timeBudgetFlags = $this->resolveTimeBudgetFlags();
            $memoryBudgetFlags = $this->resolveMemoryBudgetFlags();
            $cliAllocator = $this->resolveAllocatorFlag();
            $cliStats = $this->resolveStatsFlag();

            $resolver = new EffectiveOptionsResolver();
            [$resolution, $isSingleFlow, $isDeclarative] = $this->resolveOptionsForMode(
                $resolver,
                $config,
                $argNames,
                $cliFailFast,
                $cliProcesses,
                $invocationMode,
                $timeBudgetFlags,
                $memoryBudgetFlags,
                $cliAllocator,
                $cliStats
            );

            $this->emitIgnoredOptionsWarning($argNames, $config, $isSingleFlow, $isDeclarative);

            $aggregateFlowName = $this->buildRunIdentifier($argNames);

            $plan = $this->preparer->prepareMultiple(
                $argNames,
                $aggregateFlowName,
                $config,
                $resolution->getOptions(),
                $context,
                $excludeJobs,
                $onlyJobs,
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
                $resolution
            );

            $this->applyFormat($this->executor, $plan);

            $this->executor->setThresholdsDisabled($timeBudgetFlags['disabled']);
            $this->executor->setMemoryBudgetDisabled($memoryBudgetFlags['disabled']);

            $this->emitConditionsHeader($resolution, $expandedFlows, $plan->getInputFiles());

            $result = $this->executor->execute($plan, (bool) $this->option('dry-run'));
            $result->setConfigValidation($config->getValidation());

            $this->emitConfigWarnings($config->getValidation());

            $this->renderFormattedResult($result, $plan->getOptions());

            if ($this->option('monitor')) {
                $this->renderMonitorReport($result);
            }

            return $result->isSuccess() ? 0 : 1;
        } catch (GitHooksExceptionInterface $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    /**
     * @param string[] $argNames
     */
    private function validateFlowNames(array $argNames, ConfigurationResult $config): bool
    {
        $valid = true;
        foreach ($argNames as $name) {
            if ($config->getFlow($name) !== null) {
                continue;
            }
            $this->error("Flow '$name' is not defined in the configuration file.");
            $valid = false;
        }

        if ($valid) {
            return true;
        }

        [$normalFlows, $metaFlows] = $this->splitFlowsByKind($config);
        if (!empty($normalFlows)) {
            $this->info('Available flows: ' . implode(', ', $normalFlows));
        }
        if (!empty($metaFlows)) {
            $this->info('Available meta-flows: ' . implode(', ', $metaFlows));
        }
        return false;
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
     * @param string[] $argNames
     * @param array{warnAfter: ?int, failAfter: ?int, disabled: bool} $timeBudgetFlags
     * @param array{warnAbove: ?int, failAbove: ?int, disabled: bool} $memoryBudgetFlags
     * @return array{0: EffectiveOptionsResolution, 1: bool, 2: bool}
     *         Returns [resolution, isSingleFlow, isDeclarative]
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the cascade inputs explicitly.
     */
    private function resolveOptionsForMode(
        EffectiveOptionsResolver $resolver,
        ConfigurationResult $config,
        array $argNames,
        ?bool $cliFailFast,
        ?int $cliProcesses,
        ?string $invocationMode,
        array $timeBudgetFlags,
        array $memoryBudgetFlags,
        ?string $cliAllocator,
        ?bool $cliStats
    ): array {
        $unique = array_values(array_unique($argNames));

        if (count($unique) === 1) {
            $flow = $config->getFlow($unique[0]);
            $isMeta = $flow !== null && $flow->isMetaFlow();
            $isSingleFlow = !$isMeta;
            $isDeclarative = $isMeta;
            $resolution = $resolver->resolveSingle(
                $config,
                $flow,
                $cliFailFast,
                $cliProcesses,
                $invocationMode,
                $timeBudgetFlags['warnAfter'],
                $timeBudgetFlags['failAfter'],
                $timeBudgetFlags['disabled'],
                $memoryBudgetFlags['warnAbove'],
                $memoryBudgetFlags['failAbove'],
                $memoryBudgetFlags['disabled'],
                $cliAllocator,
                $cliStats
            );
            return [$resolution, $isSingleFlow, $isDeclarative];
        }

        return [
            $resolver->resolveMultiple(
                $config,
                $cliFailFast,
                $cliProcesses,
                $invocationMode,
                $timeBudgetFlags['warnAfter'],
                $timeBudgetFlags['failAfter'],
                $timeBudgetFlags['disabled'],
                $memoryBudgetFlags['warnAbove'],
                $memoryBudgetFlags['failAbove'],
                $memoryBudgetFlags['disabled'],
                $cliAllocator,
                $cliStats
            ),
            false,
            false,
        ];
    }

    /**
     * @param string[] $argNames
     */
    private function emitIgnoredOptionsWarning(
        array $argNames,
        ConfigurationResult $config,
        bool $isSingleFlow,
        bool $isDeclarative
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
        fwrite(
            STDERR,
            "⚠️  Options declared in '" . implode("', '", $ignored) . "' are ignored in multi-flow runs."
            . "\n  Effective options come from flows.options + CLI; see header below.\n"
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

    /**
     * @return string[]
     */
    private function csvOption(string $name): array
    {
        $value = $this->option($name);
        if (empty($value)) {
            return [];
        }
        return array_map('trim', explode(',', strval($value)));
    }
}
