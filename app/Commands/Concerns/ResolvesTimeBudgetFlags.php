<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

/**
 * Reads `--warn-after`, `--fail-after` and `--no-time-budget` from the
 * Laravel-Zero command and normalises them for the EffectiveOptionsResolver.
 *
 * Mixing rules (REQ-014/REQ-015):
 *  - `--no-time-budget` always wins.
 *  - When mixed with `--warn-after` or `--fail-after`, the latter are silently
 *    ignored and a warning is emitted on stderr (no abort).
 *
 * Usage from a command:
 *   $tb = $this->resolveTimeBudgetFlags();
 *   $resolver->resolveSingle($config, $flow, ..., $tb['warnAfter'], $tb['failAfter'], $tb['disabled']);
 */
trait ResolvesTimeBudgetFlags
{
    /**
     * @return array{warnAfter: ?int, failAfter: ?int, disabled: bool}
     */
    private function resolveTimeBudgetFlags(): array
    {
        $disabled = $this->hasOption('no-time-budget') && (bool) $this->option('no-time-budget');
        $warnAfter = $this->parseSecondsOption('warn-after');
        $failAfter = $this->parseSecondsOption('fail-after');

        if ($disabled && ($warnAfter !== null || $failAfter !== null)) {
            $stderr = $this->getOutput()->getErrorOutput();
            $hint = [];
            if ($warnAfter !== null) {
                $hint[] = '--warn-after';
            }
            if ($failAfter !== null) {
                $hint[] = '--fail-after';
            }
            $stderr->writeln('Warning: ignoring ' . implode('/', $hint) . ' due to --no-time-budget.');
            $warnAfter = null;
            $failAfter = null;
        }

        return [
            'warnAfter' => $warnAfter,
            'failAfter' => $failAfter,
            'disabled'  => $disabled,
        ];
    }

    private function parseSecondsOption(string $name): ?int
    {
        if (!$this->hasOption($name)) {
            return null;
        }
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->getOutput()->getErrorOutput()->writeln(
                "Warning: --$name expects a positive integer (seconds); got '$raw'. Ignoring."
            );
            return null;
        }
        return (int) $raw;
    }
}
