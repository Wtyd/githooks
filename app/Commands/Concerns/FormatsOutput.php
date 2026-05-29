<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\OutputFormats;
use Wtyd\GitHooks\Output\RenderOptions;

/**
 * Adapter trait that delegates to {@see FlowResultRenderer}. Kept during
 * Phase 2a so the three commands keep working untouched; removed in Phase 2c
 * when all three switch to the Runner-based pipeline.
 *
 * Consumer Commands MUST also `use EmitsStderr;` (legacy contract preserved
 * for the few collaborator traits that still reference `$this->emitStderr`).
 */
trait FormatsOutput
{
    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) used by classes that consume the trait
     */
    private function applyFormat(FlowExecutor $executor, ?FlowPlan $plan = null): void
    {
        $this->makeRenderer()->applyFormat($executor, $plan, $this->buildRenderOptions(), $this->getOutput());
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) used by classes that consume the trait
     */
    private function renderFormattedResult(FlowResult $result, ?OptionsConfiguration $options = null): void
    {
        $this->makeRenderer()->renderFormattedResult($result, $options, $this->buildRenderOptions(), $this->getOutput());
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) used by classes that consume the trait
     */
    private function renderMonitorReport(FlowResult $result): void
    {
        $this->makeRenderer()->renderMonitorReport($result, $this->getOutput());
    }

    /**
     * @return array<string, string>
     */
    private function collectReportTargets(?OptionsConfiguration $options): array
    {
        return $this->makeRenderer()->collectReportTargets($options, $this->buildRenderOptions());
    }

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
            $cliReports
        );
    }

    private function makeRenderer(): FlowResultRenderer
    {
        return new FlowResultRenderer($this->getLaravel());
    }
}
