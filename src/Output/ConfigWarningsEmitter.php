<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wtyd\GitHooks\Configuration\ValidationResult;

/**
 * Emits parsing-time warnings (including deprecations) on stderr after the
 * flow has executed. Skipped-job warnings are filtered because the output
 * handler (StreamingText / Dashboard / Progress) already surfaces them via
 * onJobSkipped events — emitting them again would duplicate the notice in
 * text mode and leak into stdout for structured formats.
 *
 * Uses SymfonyStyle::getErrorStyle() (public) so writes target stderr in
 * production. In tests where $output is a plain BufferedOutput we fall back
 * to writing on $output directly so assertions on the buffer still see the
 * warnings — the production stdout/stderr separation is the responsibility
 * of the caller's OutputInterface choice.
 */
class ConfigWarningsEmitter
{
    public function emit(ValidationResult $validation, OutputInterface $output): void
    {
        $sink = $output instanceof SymfonyStyle
            ? $output->getErrorStyle()
            : $output;

        foreach ($validation->getWarnings() as $warning) {
            if (strpos($warning, 'skipped') !== false) {
                continue;
            }
            $sink->writeln("<comment>Warning:</comment> $warning");
        }
    }
}
