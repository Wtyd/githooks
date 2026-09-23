<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Output\OutputFormats;
use Wtyd\GitHooks\Output\RenderOptions;

/**
 * Builds the {@see RenderOptions} DTO from the parsed CLI flags. Shared by
 * the three Phase 2 thin-adapter commands (Job/Flow/Flows) so they emit the
 * same shape to their respective Runners.
 *
 * The trait expects the consumer Command to expose `option(string)` and
 * `hasOption(string)` — both provided by Illuminate\Console\Command.
 */
trait BuildsRenderOptions
{
    private function buildRenderOptions(): RenderOptions
    {
        $cliReports = [];
        foreach (OutputFormats::STRUCTURED as $format) {
            $key = "report-$format";
            if (!$this->hasOption($key)) {
                continue;
            }
            $value = $this->option($key);
            if ($value === null || $value === '') {
                continue;
            }
            $cliReports[$format] = strval($value);
        }

        $outputPath = $this->hasOption('output') ? $this->option('output') : null;

        $statsSort = $this->resolveStatsSort();

        return new RenderOptions(
            strval($this->option('format')),
            $outputPath === null || $outputPath === '' ? null : strval($outputPath),
            $this->hasOption('no-reports') && (bool) $this->option('no-reports'),
            $this->hasOption('no-ci') && (bool) $this->option('no-ci'),
            $this->hasOption('show-progress') && (bool) $this->option('show-progress'),
            $cliReports,
            $this->hasOption('diag') && (bool) $this->option('diag'),
            $statsSort
        );
    }

    /**
     * `RenderOptions` falls back to `exec` for anything it does not recognise,
     * which on its own turns a typo into a table quietly sorted by something
     * else. Every other enumerated flag (`--allocator`, `--format`, a pest
     * job's `runner`) says so; this one did not.
     */
    private function resolveStatsSort(): string
    {
        if (!$this->hasOption('stats-sort')) {
            return RenderOptions::STATS_SORT_EXEC;
        }

        $raw = $this->option('stats-sort');
        if ($raw === null || $raw === '') {
            return RenderOptions::STATS_SORT_EXEC;
        }

        $value = strval($raw);
        if (!in_array($value, RenderOptions::STATS_SORTS, true)) {
            $valid = implode(', ', RenderOptions::STATS_SORTS);
            $this->getOutput()->getErrorStyle()->writeln(
                "<comment>Warning:</comment> --stats-sort expects one of: $valid (got '$value'). "
                . 'Falling back to ' . RenderOptions::STATS_SORT_EXEC . '.'
            );
            return RenderOptions::STATS_SORT_EXEC;
        }

        return $value;
    }
}
