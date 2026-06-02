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

        return new RenderOptions(
            strval($this->option('format')),
            $outputPath === null || $outputPath === '' ? null : strval($outputPath),
            $this->hasOption('no-reports') && (bool) $this->option('no-reports'),
            $this->hasOption('no-ci') && (bool) $this->option('no-ci'),
            $this->hasOption('show-progress') && (bool) $this->option('show-progress'),
            $cliReports,
            $this->hasOption('diag') && (bool) $this->option('diag')
        );
    }
}
