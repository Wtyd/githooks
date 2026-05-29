<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Execution\EffectiveOptionsResolution;
use Wtyd\GitHooks\Execution\InputFilesResolution;
use Wtyd\GitHooks\Output\ConditionsHeaderEmitter;
use Wtyd\GitHooks\Output\HeaderOptions;

/**
 * Adapter trait that delegates to {@see ConditionsHeaderEmitter}. Kept during
 * Phase 2a so the three commands keep working untouched; removed in Phase 2c
 * when all three switch to the Runner-based pipeline.
 */
trait EmitsConditionsHeader
{
    /**
     * @param string[]|null $expandedFlows Normal flow names after meta-flow expansion (multi-flow runs only)
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) used by classes that consume the trait
     */
    private function emitConditionsHeader(
        EffectiveOptionsResolution $resolution,
        ?array $expandedFlows,
        ?InputFilesResolution $inputFiles
    ): void {
        $options = new HeaderOptions(
            strval($this->option('format')),
            $this->hasOption('show-progress') && (bool) $this->option('show-progress')
        );
        (new ConditionsHeaderEmitter())->emit($resolution, $expandedFlows, $inputFiles, $options, $this->getOutput());
    }
}
