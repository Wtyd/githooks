<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;

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
 */
final class EffectiveOptionsResolver
{
    public const SOURCE_CLI = 'cli';
    public const SOURCE_FLOWS_OPTIONS = 'flows.options';
    public const SOURCE_DEFAULT = 'default';

    public function resolveSingle(
        ConfigurationResult $config,
        FlowConfiguration $flow,
        ?bool $cliFailFast,
        ?int $cliProcesses,
        ?string $invocationMode
    ): EffectiveOptionsResolution {
        $sourceLabel = "flows.{$flow->getName()}.options";

        return $this->resolve(
            $config,
            $flow->getOptions(),
            $sourceLabel,
            $flow->getExecution(),
            $cliFailFast,
            $cliProcesses,
            $invocationMode
        );
    }

    public function resolveMultiple(
        ConfigurationResult $config,
        ?bool $cliFailFast,
        ?int $cliProcesses,
        ?string $invocationMode
    ): EffectiveOptionsResolution {
        return $this->resolve(
            $config,
            null,
            '',
            null,
            $cliFailFast,
            $cliProcesses,
            $invocationMode
        );
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Parameters mirror the cascade inputs explicitly.
     */
    private function resolve(
        ConfigurationResult $config,
        ?OptionsConfiguration $flowOptions,
        string $flowSourceLabel,
        ?string $flowExecution,
        ?bool $cliFailFast,
        ?int $cliProcesses,
        ?string $invocationMode
    ): EffectiveOptionsResolution {
        $globalOptions = $config->getGlobalOptions();

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
            $flowSourceLabel
        );

        [$mainBranch, $mainBranchSource] = $this->cascadeOptionalString(
            'main-branch',
            $flowOptions,
            $flowSourceLabel,
            $globalOptions,
            fn(OptionsConfiguration $opts) => $opts->getMainBranch()
        );

        $merged = $this->mergeOptionsBlock(
            $globalOptions,
            $flowOptions,
            $failFast,
            $processes,
            $mainBranch
        );

        $trace = [
            'processes'     => ['value' => $processes,     'source' => $processesSource],
            'failFast'      => ['value' => $failFast,      'source' => $failFastSource],
            'executionMode' => ['value' => $executionMode, 'source' => $executionModeSource],
        ];

        if ($executionMode === ExecutionMode::FAST_BRANCH || $mainBranch !== null) {
            $trace['mainBranch'] = ['value' => $mainBranch, 'source' => $mainBranchSource];
        }

        return new EffectiveOptionsResolution($merged, $executionMode, $trace);
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
     * @return array{0: string, 1: string}
     */
    private function resolveExecutionMode(
        ?string $invocationMode,
        ?string $flowExecution,
        string $flowSourceLabel
    ): array {
        if ($invocationMode !== null) {
            return [$invocationMode, self::SOURCE_CLI];
        }
        if ($flowExecution !== null && $flowSourceLabel !== '') {
            return [$flowExecution, $flowSourceLabel];
        }
        return [ExecutionMode::FULL, self::SOURCE_DEFAULT];
    }

    /**
     * Build the resolved OptionsConfiguration applying per-key cascade for the tracked
     * keys and the existing block-level fallback (flow.options ?? globals) for the rest
     * (executable-prefix, fast-branch-fallback, reports).
     */
    private function mergeOptionsBlock(
        OptionsConfiguration $globalOptions,
        ?OptionsConfiguration $flowOptions,
        bool $failFast,
        int $processes,
        ?string $mainBranch
    ): OptionsConfiguration {
        $base = $flowOptions ?? $globalOptions;

        return new OptionsConfiguration(
            $failFast,
            $processes,
            $mainBranch,
            $base->getFastBranchFallback(),
            $base->getExecutablePrefix(),
            $base->getReports()
        );
    }
}
