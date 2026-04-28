<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Configuration\ValidationResult;

/**
 * Emits parsing-time warnings (including deprecations) on stderr after the
 * flow has executed. Skipped-job warnings are filtered because the output
 * handler (StreamingText / Dashboard / Progress) already surfaces them via
 * onJobSkipped events — emitting them again would duplicate the notice in
 * text mode and leak into stdout for structured formats.
 *
 * Uses SymfonyStyle::getErrorStyle() (public) instead of $this->warn(), which
 * writes to stdout and would corrupt structured payloads (--format=json|junit|
 * sarif|codeclimate).
 */
trait EmitsConfigWarnings
{
    private function emitConfigWarnings(ValidationResult $validation): void
    {
        $errorStyle = $this->getOutput()->getErrorStyle();
        foreach ($validation->getWarnings() as $warning) {
            if (strpos($warning, 'skipped') !== false) {
                continue;
            }
            $errorStyle->writeln("<comment>Warning:</comment> $warning");
        }
    }
}
