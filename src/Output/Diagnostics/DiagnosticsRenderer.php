<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Diagnostics;

use Wtyd\GitHooks\Execution\Diagnostics;

/**
 * Renders a {@see Diagnostics} snapshot in two shapes (FEAT-14):
 *   - {@see renderMultiline()} for CI: a header line plus an aligned column
 *     block (same alignment style as the `Settings:` header).
 *   - {@see renderCompact()} for local `--diag`: a single dense line.
 *
 * The timestamp (flow start) is passed in rather than stored on the VO so it
 * shares the value used for `runtime.startedAt`.
 */
class DiagnosticsRenderer
{
    /**
     * @return string[] lines (no trailing newlines)
     */
    public function renderMultiline(Diagnostics $snapshot, string $timestamp): array
    {
        $ciName = $snapshot->getCi() ?? 'local';
        $lines = [
            sprintf('githooks %s on %s · %s · %s', $snapshot->getVersion(), $snapshot->getPlatform(), $ciName, $timestamp),
        ];

        $rows = [
            'cpus'              => $this->cpuValue($snapshot),
            'mem available'     => $this->memValue($snapshot),
            'load avg (1/5/15)' => $this->loadValue($snapshot),
        ];
        $width = max(array_map('strlen', array_keys($rows)));
        foreach ($rows as $label => $value) {
            $lines[] = sprintf('  %s = %s', str_pad($label, $width), $value);
        }

        return $lines;
    }

    public function renderCompact(Diagnostics $snapshot, string $timestamp): string
    {
        return sprintf(
            'githooks %s · %s · cpus=%s · mem=%s · load=%s · %s',
            $snapshot->getVersion(),
            $snapshot->getPlatform(),
            $this->cpuValue($snapshot),
            $this->memValue($snapshot),
            $this->loadValue($snapshot),
            $timestamp
        );
    }

    private function cpuValue(Diagnostics $snapshot): string
    {
        $limit = $snapshot->getCpuCgroupLimit();
        return sprintf('%d (cgroup limit: %s)', $snapshot->getCpuDetected(), $limit === null ? 'none' : (string) $limit);
    }

    private function memValue(Diagnostics $snapshot): string
    {
        $available = $snapshot->getMemAvailableMb();
        $total = $snapshot->getMemTotalMb();
        if ($available === null && $total === null) {
            return 'n/a';
        }
        return sprintf('%s MB / %s MB', $available ?? '?', $total ?? '?');
    }

    private function loadValue(Diagnostics $snapshot): string
    {
        if ($snapshot->getLoadAvg1() === null) {
            return 'n/a';
        }
        return sprintf(
            '%s / %s / %s',
            $this->fmtLoad($snapshot->getLoadAvg1()),
            $this->fmtLoad($snapshot->getLoadAvg5()),
            $this->fmtLoad($snapshot->getLoadAvg15())
        );
    }

    private function fmtLoad(?float $value): string
    {
        return $value === null ? '?' : number_format($value, 2);
    }
}
