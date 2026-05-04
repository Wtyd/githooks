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
 * Layout (option C — aligned multi-line):
 *
 *   Settings:
 *     processes      = 8                                   (flows.ci-validation.options)
 *     fail-fast      = false                               (flows.options)
 *     mode           = fast-branch                         (cli)
 *     time-budget    = warn-after=800s,fail-after=1200s    (flows.ci-validation.options)
 *     memory-budget  = warn-above=3000MB,fail-above=5000MB (flows.ci-validation.options)
 *     allocator      = greedy                              (flows.ci-validation.options)
 *     stats          = true                                (flows.ci-validation.options)
 *
 * Source is omitted when it is `default` — the value alone carries the
 * information; `(default)` was just decoration drowning the signal of
 * the cells where the user actually overrode something.
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
        $rows = $this->buildSettingsRows($resolution, $inputFiles);

        $maxLabel = 0;
        $maxValue = 0;
        foreach ($rows as $row) {
            $maxLabel = max($maxLabel, strlen($row['label']));
            $maxValue = max($maxValue, strlen($row['value']));
        }

        $lines = ['Settings:'];
        foreach ($rows as $row) {
            $label = str_pad($row['label'], $maxLabel);
            $line  = "  $label = " . $row['value'];

            if ($row['source'] !== '' && $row['source'] !== 'default') {
                $line = "  $label = " . str_pad($row['value'], $maxValue) . " ({$row['source']})";
            }

            $lines[] = rtrim($line);
        }

        if ($expandedFlows !== null && $expandedFlows !== []) {
            $lines[] = 'Flows: ' . implode(', ', $expandedFlows);
        }

        return $lines;
    }

    /**
     * @return array<int, array{label: string, value: string, source: string}>
     */
    private function buildSettingsRows(
        EffectiveOptionsResolution $resolution,
        ?InputFilesResolution $inputFiles
    ): array {
        $trace = $resolution->getTrace();

        $modeValue = $inputFiles !== null
            ? 'files'
            : (string) ($trace['executionMode']['value'] ?? 'full');
        $modeSource = $inputFiles !== null
            ? 'cli'
            : (string) ($trace['executionMode']['source'] ?? 'default');

        return [
            $this->settingsRow('processes', $trace['processes'] ?? null),
            $this->settingsRow('fail-fast', $trace['failFast'] ?? null),
            ['label' => 'mode', 'value' => $modeValue, 'source' => $modeSource],
            $this->budgetRow('time-budget', $trace['timeBudget'] ?? null, 'after', 's'),
            $this->budgetRow('memory-budget', $trace['memoryBudget'] ?? null, 'above', 'MB'),
            $this->settingsRow('allocator', $trace['allocator'] ?? null),
            $this->settingsRow('stats', $trace['stats'] ?? null),
        ];
    }

    /**
     * @param array{value: mixed, source: string}|null $entry
     * @return array{label: string, value: string, source: string}
     */
    private function settingsRow(string $label, ?array $entry): array
    {
        if ($entry === null) {
            return ['label' => $label, 'value' => '?', 'source' => 'default'];
        }
        return [
            'label'  => $label,
            'value'  => $this->stringifyTraceValue($entry['value']),
            'source' => (string) $entry['source'],
        ];
    }

    /**
     * Render a budget row (time / memory). Output is identical to the previous
     * single-line format (e.g. `warn-after=800s,fail-after=1200s`) so existing
     * downstream consumers / docs keep matching.
     *
     * @param array{value: mixed, source: string}|null $entry
     * @return array{label: string, value: string, source: string}
     */
    private function budgetRow(string $label, ?array $entry, string $direction, string $unit): array
    {
        if ($entry === null) {
            return ['label' => $label, 'value' => 'none', 'source' => 'default'];
        }

        $source = (string) ($entry['source'] ?? 'default');
        $value  = $entry['value'] ?? null;

        if ($value === null) {
            $rendered = $source === 'cli' ? 'disabled' : 'none';
            return ['label' => $label, 'value' => $rendered, 'source' => $source];
        }

        if (is_array($value)) {
            $warnKey = 'warn' . ucfirst($direction); // warnAfter | warnAbove
            $failKey = 'fail' . ucfirst($direction); // failAfter | failAbove
            $parts = [];
            $warn = $value[$warnKey] ?? null;
            $fail = $value[$failKey] ?? null;
            if ($warn !== null) {
                $parts[] = "warn-{$direction}={$warn}{$unit}";
            }
            if ($fail !== null) {
                $parts[] = "fail-{$direction}={$fail}{$unit}";
            }
            $rendered = $parts === [] ? 'none' : implode(',', $parts);
            return ['label' => $label, 'value' => $rendered, 'source' => $source];
        }

        return [
            'label'  => $label,
            'value'  => $this->stringifyTraceValue($value),
            'source' => $source,
        ];
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
