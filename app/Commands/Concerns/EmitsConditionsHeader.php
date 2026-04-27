<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Execution\EffectiveOptionsResolution;
use Wtyd\GitHooks\Execution\InputFilesResolution;
use Wtyd\GitHooks\Output\OutputFormats;

/**
 * Emit the cross-cutting "conditions header" that shows the effective options of
 * a `flow`, `flows` or `job` run with their source (REQ-019..021 / spec §4.6).
 *
 * Usage from a Laravel-Zero command:
 *   $this->emitConditionsHeader($resolution, $expandedFlows, $inputFiles);
 *
 * Output channel:
 *  - `--format=text` (default): stdout via $this->line().
 *  - structured + `--show-progress`: stderr to keep stdout clean.
 *  - structured without --show-progress: silent (would corrupt stdout payload).
 */
trait EmitsConditionsHeader
{
    /**
     * @param string[]|null $expandedFlows Normal flow names after meta-flow expansion (multi-flow runs only)
     */
    private function emitConditionsHeader(
        EffectiveOptionsResolution $resolution,
        ?array $expandedFlows,
        ?InputFilesResolution $inputFiles
    ): void {
        $channel = $this->resolveHeaderChannel();
        if ($channel === null) {
            return;
        }

        foreach ($this->buildConditionsHeaderLines($resolution, $expandedFlows, $inputFiles) as $line) {
            $channel($line);
        }
    }

    /**
     * @param string[]|null $expandedFlows
     * @return string[]
     */
    private function buildConditionsHeaderLines(
        EffectiveOptionsResolution $resolution,
        ?array $expandedFlows,
        ?InputFilesResolution $inputFiles
    ): array {
        $trace = $resolution->getTrace();
        $modeKey = 'executionMode';

        $modeValue = $inputFiles !== null
            ? 'files'
            : (string) ($trace[$modeKey]['value'] ?? 'full');
        $modeSource = $inputFiles !== null
            ? 'cli'
            : (string) ($trace[$modeKey]['source'] ?? 'default');

        $segments = [
            $this->formatTraceSegment('processes', $trace['processes'] ?? null),
            $this->formatTraceSegment('fail-fast', $trace['failFast'] ?? null),
            "mode=$modeValue ($modeSource)",
            $this->formatTimeBudgetSegment($trace['timeBudget'] ?? null),
            $this->formatMemoryBudgetSegment($trace['memoryBudget'] ?? null),
            $this->formatTraceSegment('allocator', $trace['allocator'] ?? null),
            $this->formatTraceSegment('stats', $trace['stats'] ?? null),
        ];

        $lines = ['Settings: ' . implode(' | ', $segments)];

        if ($expandedFlows !== null && $expandedFlows !== []) {
            $lines[] = 'Flows: ' . implode(', ', $expandedFlows);
        }

        return $lines;
    }

    /**
     * Render the time-budget cell:
     *  - missing/default → `time-budget=none (default)`
     *  - cli disabled → `time-budget=disabled (cli)`
     *  - configured → `time-budget=warn-after=Ws,fail-after=Fs (origin)`
     *
     * @param array{value: mixed, source: string}|null $entry
     */
    private function formatTimeBudgetSegment(?array $entry): string
    {
        if ($entry === null) {
            return 'time-budget=none (default)';
        }

        $source = (string) ($entry['source'] ?? 'default');
        $value = $entry['value'] ?? null;

        if ($value === null) {
            return $source === 'cli'
                ? 'time-budget=disabled (cli)'
                : "time-budget=none ($source)";
        }

        if (is_array($value)) {
            $parts = [];
            $warn = $value['warnAfter'] ?? null;
            $fail = $value['failAfter'] ?? null;
            if ($warn !== null) {
                $parts[] = "warn-after={$warn}s";
            }
            if ($fail !== null) {
                $parts[] = "fail-after={$fail}s";
            }
            $rendered = $parts === [] ? 'none' : implode(',', $parts);
            return "time-budget=$rendered ($source)";
        }

        return 'time-budget=' . $this->stringifyTraceValue($value) . " ($source)";
    }

    /**
     * Render the memory-budget cell:
     *  - missing/default → `memory-budget=none (default)`
     *  - cli disabled → `memory-budget=disabled (cli)`
     *  - configured → `memory-budget=warn-above=WMB,fail-above=FMB (origin)`
     *
     * @param array{value: mixed, source: string}|null $entry
     */
    private function formatMemoryBudgetSegment(?array $entry): string
    {
        if ($entry === null) {
            return 'memory-budget=none (default)';
        }

        $source = (string) ($entry['source'] ?? 'default');
        $value = $entry['value'] ?? null;

        if ($value === null) {
            return $source === 'cli'
                ? 'memory-budget=disabled (cli)'
                : "memory-budget=none ($source)";
        }

        if (is_array($value)) {
            $parts = [];
            $warn = $value['warnAbove'] ?? null;
            $fail = $value['failAbove'] ?? null;
            if ($warn !== null) {
                $parts[] = "warn-above={$warn}MB";
            }
            if ($fail !== null) {
                $parts[] = "fail-above={$fail}MB";
            }
            $rendered = $parts === [] ? 'none' : implode(',', $parts);
            return "memory-budget=$rendered ($source)";
        }

        return 'memory-budget=' . $this->stringifyTraceValue($value) . " ($source)";
    }

    /**
     * @param array{value: mixed, source: string}|null $entry
     */
    private function formatTraceSegment(string $label, ?array $entry): string
    {
        if ($entry === null) {
            return "$label=? (default)";
        }
        $value = $this->stringifyTraceValue($entry['value']);
        $source = (string) $entry['source'];
        return "$label=$value ($source)";
    }

    /** @param mixed $value */
    private function stringifyTraceValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        return (string) $value;
    }

    /**
     * @return callable(string): void|null
     */
    private function resolveHeaderChannel(): ?callable
    {
        $format = strval($this->option('format'));
        $isStructured = in_array($format, OutputFormats::STRUCTURED, true);

        if (!$isStructured) {
            return function (string $line): void {
                $this->line($line);
            };
        }

        $showProgress = $this->hasOption('show-progress') && (bool) $this->option('show-progress');
        if (!$showProgress) {
            return null;
        }

        // Use SymfonyStyle::getErrorStyle() (public) instead of
        // OutputStyle::getErrorOutput() (protected). The latter is inaccessible
        // from a closure bound to the command and triggers a fatal at runtime
        // when --format=<structured> is combined with --show-progress.
        return function (string $line): void {
            $this->getOutput()->getErrorStyle()->writeln($line);
        };
    }
}
