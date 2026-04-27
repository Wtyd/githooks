<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

/**
 * Reads `--memory-warn-above`, `--memory-fail-above` and `--no-memory-budget`
 * from the Laravel-Zero command and normalises them for EffectiveOptionsResolver.
 *
 * Mixing rules (REQ-030/REQ-034):
 *  - `--no-memory-budget` always wins.
 *  - When mixed with `--memory-warn-above` or `--memory-fail-above`, the latter
 *    are silently ignored and a warning is emitted on stderr (no abort).
 *
 * Usage from a command:
 *   $mb = $this->resolveMemoryBudgetFlags();
 *   $resolver->resolveSingle(
 *       $config, $flow, ...,
 *       $mb['warnAbove'], $mb['failAbove'], $mb['disabled']
 *   );
 */
trait ResolvesMemoryBudgetFlags
{
    /**
     * @return array{warnAbove: ?int, failAbove: ?int, disabled: bool}
     */
    private function resolveMemoryBudgetFlags(): array
    {
        $disabled = $this->hasOption('no-memory-budget') && (bool) $this->option('no-memory-budget');
        $warnAbove = $this->parseMbOption('memory-warn-above');
        $failAbove = $this->parseMbOption('memory-fail-above');

        if ($disabled && ($warnAbove !== null || $failAbove !== null)) {
            $hint = [];
            if ($warnAbove !== null) {
                $hint[] = '--memory-warn-above';
            }
            if ($failAbove !== null) {
                $hint[] = '--memory-fail-above';
            }
            $this->writeMemoryBudgetStderrWarning(
                'ignoring ' . implode('/', $hint) . ' due to --no-memory-budget.'
            );
            $warnAbove = null;
            $failAbove = null;
        }

        return [
            'warnAbove' => $warnAbove,
            'failAbove' => $failAbove,
            'disabled'  => $disabled,
        ];
    }

    private function parseMbOption(string $name): ?int
    {
        if (!$this->hasOption($name)) {
            return null;
        }
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->writeMemoryBudgetStderrWarning(
                "--$name expects a positive integer (MB); got '$raw'. Ignoring."
            );
            return null;
        }
        return (int) $raw;
    }

    /**
     * Emit a colored "Warning: ..." line on stderr. Uses SymfonyStyle::getErrorStyle()
     * (public) instead of OutputStyle::getErrorOutput() (protected).
     */
    private function writeMemoryBudgetStderrWarning(string $message): void
    {
        $errorStyle = $this->getOutput()->getErrorStyle();
        $errorStyle->writeln("<comment>Warning:</comment> $message");
    }
}
