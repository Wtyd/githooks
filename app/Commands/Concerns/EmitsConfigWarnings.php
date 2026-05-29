<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Output\ConfigWarningsEmitter;

/**
 * Adapter trait that delegates to {@see ConfigWarningsEmitter}. Kept during
 * Phase 2a so the three commands keep working untouched; removed in Phase 2c
 * when all three switch to the Runner-based pipeline.
 */
trait EmitsConfigWarnings
{
    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) used by classes that consume the trait
     */
    private function emitConfigWarnings(ValidationResult $validation): void
    {
        (new ConfigWarningsEmitter())->emit($validation, $this->getOutput());
    }
}
