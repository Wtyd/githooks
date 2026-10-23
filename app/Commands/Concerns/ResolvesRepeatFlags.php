<?php

declare(strict_types=1);

namespace Wtyd\Alfil\App\Commands\Concerns;

/**
 * Reads `--repeat` and `--flap-threshold` (FEAT-25). Both are counts, so
 * anything but a positive integer is an error: unlike `--processes`, there is
 * no configured value to fall back to, and repeating a ten-minute suite the
 * wrong number of times is worse than refusing to start.
 *
 * Usage from a command:
 *   $flags = $this->resolveRepeatFlags(); // null when invalid (already reported)
 */
trait ResolvesRepeatFlags
{
    /**
     * @return array{repeat: int, flapThreshold: int}|null
     */
    private function resolveRepeatFlags(): ?array
    {
        $repeat = $this->positiveIntOption('repeat');
        $threshold = $this->positiveIntOption('flap-threshold');
        if ($repeat === null || $threshold === null) {
            return null;
        }

        if ($repeat < 2 && $this->optionGiven('flap-threshold')) {
            $this->getOutput()->getErrorStyle()->writeln(
                '<comment>Warning:</comment> --flap-threshold has no effect without --repeat=N (N >= 2). Ignoring.'
            );
        }

        return ['repeat' => $repeat, 'flapThreshold' => $threshold];
    }

    /**
     * The value of a count flag, 1 when absent, null when invalid.
     */
    private function positiveIntOption(string $name): ?int
    {
        if (!$this->optionGiven($name)) {
            return 1;
        }
        $raw = strval($this->option($name));
        if (!ctype_digit($raw) || (int) $raw < 1) {
            $this->getOutput()->getErrorStyle()->writeln(
                "<error>--$name expects a positive integer; got '$raw'.</error>"
            );
            return null;
        }
        return (int) $raw;
    }

    private function optionGiven(string $name): bool
    {
        if (!$this->hasOption($name)) {
            return false;
        }
        $raw = $this->option($name);

        return $raw !== null && $raw !== '';
    }
}
