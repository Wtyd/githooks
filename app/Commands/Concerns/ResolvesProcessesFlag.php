<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Configuration\OptionsConfiguration;

/**
 * Reads `--processes` from the Laravel-Zero command and normalises it for the
 * EffectiveOptionsResolver. Returns null when the flag is absent or invalid
 * (in the latter case, a warning is emitted on stderr and the cascade falls
 * back to the configured value or the default of 1).
 *
 * Mirrors {@see ResolvesAllocatorFlag}: the validity rule lives in the owning
 * class ({@see OptionsConfiguration::isValidProcesses}) and is shared with the
 * config path, so CLI and config apply the same criterion (config errors on an
 * invalid value; CLI warns and ignores).
 *
 * Usage from a command:
 *   $processes = $this->resolveProcessesFlag(); // ?int
 */
trait ResolvesProcessesFlag
{
    private function resolveProcessesFlag(): ?int
    {
        if (!$this->hasOption('processes')) {
            return null;
        }
        $raw = $this->option('processes');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit((string) $raw) || !OptionsConfiguration::isValidProcesses((int) $raw)) {
            $this->writeProcessesStderrWarning(
                "--processes expects a positive integer; got '$raw'. Ignoring."
            );
            return null;
        }
        return (int) $raw;
    }

    private function writeProcessesStderrWarning(string $message): void
    {
        $errorStyle = $this->getOutput()->getErrorStyle();
        $errorStyle->writeln("<comment>Warning:</comment> $message");
    }
}
