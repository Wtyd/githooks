<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Configuration\AllocatorStrategy;

/**
 * Reads `--allocator` from the Laravel-Zero command and normalises it for the
 * EffectiveOptionsResolver. Returns null when the flag is absent or invalid
 * (in the latter case, a warning is emitted on stderr and the cascade falls
 * back to the configured value or the default `fifo`).
 *
 * Usage from a command:
 *   $allocator = $this->resolveAllocatorFlag(); // ?string
 *   $resolver->resolveSingle($config, $flow, ..., $allocator);
 */
trait ResolvesAllocatorFlag
{
    private function resolveAllocatorFlag(): ?string
    {
        if (!$this->hasOption('allocator')) {
            return null;
        }
        $raw = $this->option('allocator');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !AllocatorStrategy::isValid($raw)) {
            $valid = implode(', ', AllocatorStrategy::ALL);
            $this->writeAllocatorStderrWarning(
                "--allocator expects one of: $valid (got '$raw'). Ignoring."
            );
            return null;
        }
        return $raw;
    }

    private function writeAllocatorStderrWarning(string $message): void
    {
        $errorStyle = $this->getOutput()->getErrorStyle();
        $errorStyle->writeln("<comment>Warning:</comment> $message");
    }
}
