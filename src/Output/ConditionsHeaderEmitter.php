<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolution;
use Wtyd\GitHooks\Execution\InputFilesResolution;

/**
 * Emit the cross-cutting "conditions header" that shows the effective options
 * of a `flow`, `flows` or `job` run with their source (REQ-019..021 / spec
 * §4.6).
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
 * Every row carries its `(source)` parenthesis — including `(default)` — so
 * the column is aligned and the audit trail is complete: seeing
 * `allocator = fifo (default)` confirms "this fell through, nothing overrode
 * it", which is the exact question the operator asks when behaviour is
 * surprising.
 *
 * Output channel:
 *  - `--format=text` (default): stdout via `$output->writeln()`.
 *  - structured + `--show-progress`: stderr via `getErrorStyle()` when the
 *    output is a SymfonyStyle; otherwise silent (test buffer).
 *  - structured without --show-progress: silent (would corrupt stdout payload).
 */
class ConditionsHeaderEmitter
{
    /**
     * @param string[]|null $expandedFlows Normal flow names after meta-flow expansion (multi-flow runs only)
     * @param OutputInterface $output Either a Symfony OutputInterface or any object exposing
     *   `writeln(string)` and optionally `getErrorStyle()`. Loose typing during the
     *   Phase 2a adapter window so the existing trait test doubles keep working;
     *   tightened to `OutputInterface` in Phase 2c when the doubles are removed.
     */
    public function emit(
        EffectiveOptionsResolution $resolution,
        ?array $expandedFlows,
        ?InputFilesResolution $inputFiles,
        HeaderOptions $options,
        OutputInterface $output
    ): void {
        $sink = $this->resolveSink($options, $output);
        if ($sink === null) {
            return;
        }

        foreach ($this->buildConditionsHeaderLines($resolution, $expandedFlows, $inputFiles) as $line) {
            $sink->writeln($line);
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
            $value = str_pad($row['value'], $maxValue);
            $source = $row['source'] !== '' ? $row['source'] : 'default';
            $lines[] = rtrim("  $label = $value ($source)");
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

        $source = (string) $entry['source'];
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
     * Decide where the header lines go (or return null to suppress).
     *
     *  - text format: stdout via the OutputInterface.
     *  - structured + --show-progress on a SymfonyStyle: stderr via getErrorStyle().
     *  - structured without --show-progress: null (stdout would corrupt JSON/JUnit).
     *  - structured + --show-progress on a non-SymfonyStyle output: null
     *    (test buffer / minimal output — matches the pre-refactor trait
     *    behaviour because the trait's resolveHeaderChannel relied on
     *    `getOutput()->getErrorStyle()` which only existed on SymfonyStyle).
     *
     * @param OutputInterface $output See {@see emit()} for the duck-typed contract.
     * @return OutputInterface|null Something exposing `writeln(string)`, or null to suppress.
     */
    private function resolveSink(HeaderOptions $options, OutputInterface $output): ?OutputInterface
    {
        $isStructured = in_array($options->format, OutputFormats::STRUCTURED, true);

        if (!$isStructured) {
            return $output;
        }

        if (!$options->showProgress) {
            return null;
        }

        if ($output instanceof SymfonyStyle) {
            return $output->getErrorStyle();
        }

        return null;
    }
}
