<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\AllocatorStrategy;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\FlowOnRule;
use Wtyd\GitHooks\Configuration\MemoryBudgetConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\TimeBudgetConfiguration;
use Wtyd\GitHooks\Hooks\PatternMatcher;
use Wtyd\GitHooks\Utils\BranchResolution;

/**
 * Resolves the effective option set for a run, layering CLI > flow.options > flows.options > default
 * per key (REQ-015..017) and producing a trace for the conditions header (REQ-019) and the
 * effectiveOptions JSON v2 block (REQ-022).
 *
 * - resolveSingle(): used by `flow X` (single-flow degenerate) and by the declarative meta-flow
 *   path of `flows`. Both share the same cascade with `flows.<X>.options` or `flows.<alias>.options`
 *   as the per-flow layer.
 * - resolveMultiple(): used by `flows` ad-hoc and mixed modes. Per-flow and per-meta-flow options
 *   are ignored (CON-001/002); cascade collapses to `cli > flows.options > default`.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates options, flows and execution-mode resolution
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Per-key cascade for every tracked option is the
 *   intrinsic surface of the resolver; collapsing it would add abstractions without value.
 */
final class EffectiveOptionsResolver
{
    public const SOURCE_CLI = 'cli';
    public const SOURCE_FLOWS_OPTIONS = 'flows.options';
    public const SOURCE_DEFAULT = 'default';

    private PatternMatcher $patternMatcher;

    public function __construct(?PatternMatcher $patternMatcher = null)
    {
        $this->patternMatcher = $patternMatcher ?? new PatternMatcher();
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the cascade inputs explicitly.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$cliNoTimeBudget`/`$cliNoMemoryBudget` are CLI gates, not polymorphism breaks.
     */
    public function resolveSingle(
        ConfigurationResult $config,
        FlowConfiguration $flow,
        ?bool $cliFailFast,
        ?int $cliProcesses,
        ?string $invocationMode,
        ?int $cliWarnAfter = null,
        ?int $cliFailAfter = null,
        bool $cliNoTimeBudget = false,
        ?int $cliMemoryWarnAbove = null,
        ?int $cliMemoryFailAbove = null,
        bool $cliNoMemoryBudget = false,
        ?string $cliAllocator = null,
        ?bool $cliStats = null,
        ?BranchResolution $branchResolution = null
    ): EffectiveOptionsResolution {
        $sourceLabel = "flows.{$flow->getName()}.options";
        $onSourceLabel = "flows.{$flow->getName()}.on";

        return $this->resolve(
            $config,
            $flow->getOptions(),
            $sourceLabel,
            $flow->getExecution(),
            $cliFailFast,
            $cliProcesses,
            $invocationMode,
            $cliWarnAfter,
            $cliFailAfter,
            $cliNoTimeBudget,
            $cliMemoryWarnAbove,
            $cliMemoryFailAbove,
            $cliNoMemoryBudget,
            $cliAllocator,
            $cliStats,
            $flow->getOn(),
            $onSourceLabel,
            $branchResolution
        );
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the cascade inputs explicitly.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$cliNoTimeBudget`/`$cliNoMemoryBudget` are CLI gates, not polymorphism breaks.
     */
    public function resolveMultiple(
        ConfigurationResult $config,
        ?bool $cliFailFast,
        ?int $cliProcesses,
        ?string $invocationMode,
        ?int $cliWarnAfter = null,
        ?int $cliFailAfter = null,
        bool $cliNoTimeBudget = false,
        ?int $cliMemoryWarnAbove = null,
        ?int $cliMemoryFailAbove = null,
        bool $cliNoMemoryBudget = false,
        ?string $cliAllocator = null,
        ?bool $cliStats = null,
        ?string $jobLevelExecution = null,
        string $jobLevelExecutionLabel = ''
    ): EffectiveOptionsResolution {
        // FEAT-2: in multi-flow runs the per-flow `on` map is intentionally
        // ignored (matches CON-001/002 for flow-level options). The branch
        // resolution carries no influence on the mode in this path.
        //
        // FEAT-13 envelope reporting: `githooks job X` invokes this entry with
        // $jobLevelExecution = $jobConfig->getExecution() so the JSON envelope's
        // `executionMode` reflects the job-declared mode (e.g. `fast-dirty`)
        // instead of falling back to `default`. The actual file-set filtering
        // already honoured `jobs.X.execution` via FlowPreparer::resolveMode;
        // this aligns the reported envelope with that behaviour.
        return $this->resolve(
            $config,
            null,
            $jobLevelExecutionLabel,
            $jobLevelExecution,
            $cliFailFast,
            $cliProcesses,
            $invocationMode,
            $cliWarnAfter,
            $cliFailAfter,
            $cliNoTimeBudget,
            $cliMemoryWarnAbove,
            $cliMemoryFailAbove,
            $cliNoMemoryBudget,
            $cliAllocator,
            $cliStats,
            null,
            '',
            null
        );
    }

    /**
     * @param FlowOnRule[]|null $onRules FEAT-2 branch → attrs rules in declaration order
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Parameters mirror the cascade inputs explicitly.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Cascade evaluates several option keys.
     * @SuppressWarnings(PHPMD.NPathComplexity) Optional keys add independent branches.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$cliNoTimeBudget`/`$cliNoMemoryBudget` are CLI gates, not polymorphism breaks.
     */
    private function resolve(
        ConfigurationResult $config,
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        ?string $flowExecution,
        ?bool $cliFailFast,
        ?int $cliProcesses,
        ?string $invocationMode,
        ?int $cliWarnAfter = null,
        ?int $cliFailAfter = null,
        bool $cliNoTimeBudget = false,
        ?int $cliMemoryWarnAbove = null,
        ?int $cliMemoryFailAbove = null,
        bool $cliNoMemoryBudget = false,
        ?string $cliAllocator = null,
        ?bool $cliStats = null,
        ?array $onRules = null,
        string $onSourceLabel = '',
        ?BranchResolution $branchResolution = null
    ): EffectiveOptionsResolution {
        $globalOptions = $config->getGlobalOptions();

        $core = $this->cascadeCoreOptions(
            $globalOptions,
            $flowOptions,
            $flowSourceLabel,
            $flowExecution,
            $cliFailFast,
            $cliProcesses,
            $invocationMode,
            $onRules,
            $onSourceLabel,
            $branchResolution
        );

        [$timeBudget, $timeBudgetSource] = $this->cascadeTimeBudget(
            $cliWarnAfter,
            $cliFailAfter,
            $cliNoTimeBudget,
            $flowOptions,
            $flowSourceLabel,
            $globalOptions
        );

        [$memoryBudget, $memoryBudgetSource] = $this->cascadeMemoryBudget(
            $cliMemoryWarnAbove,
            $cliMemoryFailAbove,
            $cliNoMemoryBudget,
            $flowOptions,
            $flowSourceLabel,
            $globalOptions
        );

        [$allocator, $allocatorSource] = $this->cascadeAllocator(
            $cliAllocator,
            $flowOptions,
            $flowSourceLabel,
            $globalOptions
        );

        [$stats, $statsSource] = $this->cascadeBool(
            'stats',
            $cliStats,
            $flowOptions,
            $flowSourceLabel,
            $globalOptions,
            fn(OptionsConfiguration $opts) => $opts->isStats()
        );

        $merged = $this->mergeOptionsBlock(
            $globalOptions,
            $flowOptions,
            $core['failFast'],
            $core['processes'],
            $core['mainBranch'],
            $timeBudget,
            $memoryBudget,
            $allocator,
            $stats
        );

        $trace = [
            'processes'     => ['value' => $core['processes'],     'source' => $core['processesSource']],
            'failFast'      => ['value' => $core['failFast'],      'source' => $core['failFastSource']],
            'executionMode' => ['value' => $core['executionMode'], 'source' => $core['executionModeSource']],
            'timeBudget'    => ['value' => $this->traceTimeBudget($timeBudget), 'source' => $timeBudgetSource],
            'memoryBudget'  => ['value' => $this->traceMemoryBudget($memoryBudget), 'source' => $memoryBudgetSource],
            'allocator'     => ['value' => $allocator,     'source' => $allocatorSource],
            'stats'         => ['value' => $stats,         'source' => $statsSource],
        ];

        if ($core['executionMode'] === ExecutionMode::FAST_BRANCH || $core['mainBranch'] !== null) {
            $trace['mainBranch'] = ['value' => $core['mainBranch'], 'source' => $core['mainBranchSource']];
        }

        return new EffectiveOptionsResolution($merged, $core['executionMode'], $trace);
    }

    /**
     * Run the cascade for the original v3.0+ options (fail-fast, processes,
     * executionMode, mainBranch). Returns a struct to keep `resolve()` short.
     *
     * @param FlowOnRule[]|null $onRules FEAT-2 branch → attrs rules
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Aggregates the original cascade inputs.
     * @return array{
     *   failFast: bool, failFastSource: string,
     *   processes: int, processesSource: string,
     *   executionMode: string, executionModeSource: string,
     *   mainBranch: ?string, mainBranchSource: string
     * }
     */
    private function cascadeCoreOptions(
        OptionsConfiguration $globalOptions,
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        ?string $flowExecution,
        ?bool $cliFailFast,
        ?int $cliProcesses,
        ?string $invocationMode,
        ?array $onRules = null,
        string $onSourceLabel = '',
        ?BranchResolution $branchResolution = null
    ): array {
        [$failFast, $failFastSource] = $this->cascadeBool(
            'fail-fast',
            $cliFailFast,
            $flowOptions,
            $flowSourceLabel,
            $globalOptions,
            fn(OptionsConfiguration $opts) => $opts->isFailFast()
        );

        [$processes, $processesSource] = $this->cascadeInt(
            'processes',
            $cliProcesses,
            $flowOptions,
            $flowSourceLabel,
            $globalOptions,
            fn(OptionsConfiguration $opts) => $opts->getProcesses()
        );

        [$executionMode, $executionModeSource] = $this->resolveExecutionMode(
            $invocationMode,
            $flowExecution,
            $flowSourceLabel,
            $onRules,
            $onSourceLabel,
            $branchResolution
        );

        [$mainBranch, $mainBranchSource] = $this->cascadeOptionalString(
            'main-branch',
            $flowOptions,
            $flowSourceLabel,
            $globalOptions,
            fn(OptionsConfiguration $opts) => $opts->getMainBranch()
        );

        return [
            'failFast' => $failFast,
            'failFastSource' => $failFastSource,
            'processes' => $processes,
            'processesSource' => $processesSource,
            'executionMode' => $executionMode,
            'executionModeSource' => $executionModeSource,
            'mainBranch' => $mainBranch,
            'mainBranchSource' => $mainBranchSource,
        ];
    }

    /**
     * Cascade for the time-budget block.
     *
     *  - `--no-time-budget` gates everything: result is null with source 'cli'.
     *  - `--warn-after`/`--fail-after` partially override the resolved budget
     *    (config layer below dictates the values they don't replace).
     *  - Otherwise normal cascade: flow.options > flows.options > default(null).
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$cliNoTimeBudget` is a CLI gate, not a polymorphism break.
     * @return array{0: ?TimeBudgetConfiguration, 1: string}
     */
    private function cascadeTimeBudget(
        ?int $cliWarnAfter,
        ?int $cliFailAfter,
        bool $cliNoTimeBudget,
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        OptionsConfiguration $globalOptions
    ): array {
        if ($cliNoTimeBudget) {
            return [null, self::SOURCE_CLI];
        }

        [$configBudget, $configSource] = $this->resolveConfigTimeBudget(
            $flowOptions,
            $flowSourceLabel,
            $globalOptions
        );

        if ($cliWarnAfter === null && $cliFailAfter === null) {
            return [$configBudget, $configSource];
        }

        return $this->mergeCliTimeBudget($cliWarnAfter, $cliFailAfter, $configBudget);
    }

    /**
     * Resolve the time-budget from config layers (flow.options > flows.options > default).
     *
     * @return array{0: ?TimeBudgetConfiguration, 1: string}
     */
    private function resolveConfigTimeBudget(
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        OptionsConfiguration $globalOptions
    ): array {
        if ($flowOptions !== null && $flowOptions->hasKey(TimeBudgetConfiguration::KEY)) {
            return [$flowOptions->getTimeBudget(), $flowSourceLabel];
        }
        if ($globalOptions->hasKey(TimeBudgetConfiguration::KEY)) {
            return [$globalOptions->getTimeBudget(), self::SOURCE_FLOWS_OPTIONS];
        }
        return [null, self::SOURCE_DEFAULT];
    }

    /**
     * Apply CLI overrides over an existing time-budget (or build one from scratch
     * when both CLI flags are provided and config has none).
     *
     * @return array{0: ?TimeBudgetConfiguration, 1: string}
     */
    private function mergeCliTimeBudget(
        ?int $cliWarnAfter,
        ?int $cliFailAfter,
        ?TimeBudgetConfiguration $configBudget
    ): array {
        $warnAfter = $cliWarnAfter !== null
            ? $cliWarnAfter
            : ($configBudget !== null ? $configBudget->getWarnAfter() : null);
        $failAfter = $cliFailAfter !== null
            ? $cliFailAfter
            : ($configBudget !== null ? $configBudget->getFailAfter() : null);

        if ($warnAfter === null && $failAfter === null) {
            return [null, self::SOURCE_CLI];
        }

        return [new TimeBudgetConfiguration($warnAfter, $failAfter), self::SOURCE_CLI];
    }

    /**
     * Render a TimeBudgetConfiguration as a plain associative array for the trace.
     *
     * @return array{warnAfter: ?int, failAfter: ?int}|null
     */
    private function traceTimeBudget(?TimeBudgetConfiguration $budget): ?array
    {
        if ($budget === null) {
            return null;
        }
        return ['warnAfter' => $budget->getWarnAfter(), 'failAfter' => $budget->getFailAfter()];
    }

    /**
     * Cascade for the memory-budget block. Mirrors cascadeTimeBudget:
     *  - `--no-memory-budget` gates everything.
     *  - `--memory-warn-above`/`--memory-fail-above` partial overrides keep the
     *    config layer below for the non-overridden value.
     *  - Otherwise: flow.options > flows.options > default(null).
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$cliNoMemoryBudget` is a CLI gate, not a polymorphism break.
     * @return array{0: ?MemoryBudgetConfiguration, 1: string}
     */
    private function cascadeMemoryBudget(
        ?int $cliWarnAbove,
        ?int $cliFailAbove,
        bool $cliNoMemoryBudget,
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        OptionsConfiguration $globalOptions
    ): array {
        if ($cliNoMemoryBudget) {
            return [null, self::SOURCE_CLI];
        }

        [$configBudget, $configSource] = $this->resolveConfigMemoryBudget(
            $flowOptions,
            $flowSourceLabel,
            $globalOptions
        );

        if ($cliWarnAbove === null && $cliFailAbove === null) {
            return [$configBudget, $configSource];
        }

        return $this->mergeCliMemoryBudget($cliWarnAbove, $cliFailAbove, $configBudget);
    }

    /**
     * @return array{0: ?MemoryBudgetConfiguration, 1: string}
     */
    private function resolveConfigMemoryBudget(
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        OptionsConfiguration $globalOptions
    ): array {
        if ($flowOptions !== null && $flowOptions->hasKey(MemoryBudgetConfiguration::KEY)) {
            return [$flowOptions->getMemoryBudget(), $flowSourceLabel];
        }
        if ($globalOptions->hasKey(MemoryBudgetConfiguration::KEY)) {
            return [$globalOptions->getMemoryBudget(), self::SOURCE_FLOWS_OPTIONS];
        }
        return [null, self::SOURCE_DEFAULT];
    }

    /**
     * @return array{0: ?MemoryBudgetConfiguration, 1: string}
     */
    private function mergeCliMemoryBudget(
        ?int $cliWarnAbove,
        ?int $cliFailAbove,
        ?MemoryBudgetConfiguration $configBudget
    ): array {
        $warnAbove = $cliWarnAbove !== null
            ? $cliWarnAbove
            : ($configBudget !== null ? $configBudget->getWarnAbove() : null);
        $failAbove = $cliFailAbove !== null
            ? $cliFailAbove
            : ($configBudget !== null ? $configBudget->getFailAbove() : null);

        if ($warnAbove === null && $failAbove === null) {
            return [null, self::SOURCE_CLI];
        }

        return [new MemoryBudgetConfiguration($warnAbove, $failAbove), self::SOURCE_CLI];
    }

    /**
     * @return array{warnAbove: ?int, failAbove: ?int}|null
     */
    private function traceMemoryBudget(?MemoryBudgetConfiguration $budget): ?array
    {
        if ($budget === null) {
            return null;
        }
        return ['warnAbove' => $budget->getWarnAbove(), 'failAbove' => $budget->getFailAbove()];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function cascadeAllocator(
        ?string $cliAllocator,
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        OptionsConfiguration $globalOptions
    ): array {
        if ($cliAllocator !== null) {
            return [$cliAllocator, self::SOURCE_CLI];
        }
        if ($flowOptions !== null && $flowOptions->hasKey('allocator')) {
            return [$flowOptions->getAllocator(), $flowSourceLabel];
        }
        if ($globalOptions->hasKey('allocator')) {
            return [$globalOptions->getAllocator(), self::SOURCE_FLOWS_OPTIONS];
        }
        return [AllocatorStrategy::FIFO, self::SOURCE_DEFAULT];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function cascadeBool(
        string $key,
        ?bool $cliValue,
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        OptionsConfiguration $globalOptions,
        callable $reader
    ): array {
        if ($cliValue !== null) {
            return [$cliValue, self::SOURCE_CLI];
        }
        if ($flowOptions !== null && $flowOptions->hasKey($key)) {
            return [(bool) $reader($flowOptions), $flowSourceLabel];
        }
        if ($globalOptions->hasKey($key)) {
            return [(bool) $reader($globalOptions), self::SOURCE_FLOWS_OPTIONS];
        }
        return [(bool) $reader($globalOptions), self::SOURCE_DEFAULT];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function cascadeInt(
        string $key,
        ?int $cliValue,
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        OptionsConfiguration $globalOptions,
        callable $reader
    ): array {
        if ($cliValue !== null) {
            return [$cliValue, self::SOURCE_CLI];
        }
        if ($flowOptions !== null && $flowOptions->hasKey($key)) {
            return [(int) $reader($flowOptions), $flowSourceLabel];
        }
        if ($globalOptions->hasKey($key)) {
            return [(int) $reader($globalOptions), self::SOURCE_FLOWS_OPTIONS];
        }
        return [(int) $reader($globalOptions), self::SOURCE_DEFAULT];
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function cascadeOptionalString(
        string $key,
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        OptionsConfiguration $globalOptions,
        callable $reader
    ): array {
        if ($flowOptions !== null && $flowOptions->hasKey($key)) {
            return [$reader($flowOptions), $flowSourceLabel];
        }
        if ($globalOptions->hasKey($key)) {
            return [$reader($globalOptions), self::SOURCE_FLOWS_OPTIONS];
        }
        return [$reader($globalOptions), self::SOURCE_DEFAULT];
    }

    /**
     * @param FlowOnRule[]|null $onRules FEAT-2: ordered branch → attrs rules
     * @return array{0: string, 1: string}
     */
    private function resolveExecutionMode(
        ?string $invocationMode,
        ?string $flowExecution,
        string $flowSourceLabel,
        ?array $onRules = null,
        string $onSourceLabel = '',
        ?BranchResolution $branchResolution = null
    ): array {
        if ($invocationMode !== null) {
            return [$invocationMode, self::SOURCE_CLI];
        }
        $onMode = $this->matchOn($onRules, $branchResolution);
        if ($onMode !== null && $onSourceLabel !== '') {
            return [$onMode, $onSourceLabel];
        }
        if ($flowExecution !== null && $flowSourceLabel !== '') {
            return [$flowExecution, $flowSourceLabel];
        }
        return [ExecutionMode::FULL, self::SOURCE_DEFAULT];
    }

    /**
     * Find the first branch-pattern rule that matches the resolved branch and
     * declares an `execution` attribute. Returns the mode or null if either
     * the rule set is absent, the branch is unknown, or no rule matches.
     *
     * Declaration order is the matching priority (D3): the first match wins.
     * Literal patterns and globs are not reordered — the user controls the
     * precedence by ordering their `on` map.
     *
     * @param FlowOnRule[]|null $onRules
     */
    private function matchOn(?array $onRules, ?BranchResolution $branchResolution): ?string
    {
        if ($onRules === null || $onRules === [] || $branchResolution === null) {
            return null;
        }
        $branch = $branchResolution->getBranch();
        foreach ($onRules as $rule) {
            if ($this->patternMatcher->matchesBranch($branch, [$rule->getPattern()])) {
                $mode = $rule->getExecutionMode();
                if ($mode !== null) {
                    return $mode;
                }
            }
        }
        return null;
    }

    /**
     * Build the resolved OptionsConfiguration applying per-key cascade for every
     * tracked key. `executable-prefix`, `fast-branch-fallback` and `reports`
     * cascade independently (BUG-20) via `OptionsConfiguration::cascadeBlockKeysFromFlow`,
     * shared with `FlowPreparer::prepare()` to keep both code paths consistent.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors the cascade output explicitly.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Aggregates per-key cascade results.
     */
    private function mergeOptionsBlock(
        OptionsConfiguration $globalOptions,
        ?OptionsConfiguration $flowOptions,
        bool $failFast,
        int $processes,
        ?string $mainBranch,
        ?TimeBudgetConfiguration $timeBudget = null,
        ?MemoryBudgetConfiguration $memoryBudget = null,
        string $allocator = AllocatorStrategy::FIFO,
        bool $stats = false
    ): OptionsConfiguration {
        $base = OptionsConfiguration::cascadeBlockKeysFromFlow($flowOptions, $globalOptions);

        return new OptionsConfiguration(
            $failFast,
            $processes,
            $mainBranch,
            $base->getFastBranchFallback(),
            $base->getExecutablePrefix(),
            $base->getReports(),
            $timeBudget,
            $memoryBudget,
            $allocator,
            $stats
        );
    }
}
