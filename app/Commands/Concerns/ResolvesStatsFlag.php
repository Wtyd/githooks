<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

/**
 * Reads `--stats` from the Laravel-Zero command. Returns true when the flag is
 * present and null otherwise (so the cascade can fall back to the configured
 * value or the default `false`).
 *
 * Usage:
 *   $stats = $this->resolveStatsFlag(); // ?bool
 *   $resolver->resolveSingle($config, $flow, ..., $stats);
 */
trait ResolvesStatsFlag
{
    private function resolveStatsFlag(): ?bool
    {
        if (!$this->hasOption('stats')) {
            return null;
        }
        return ((bool) $this->option('stats')) ? true : null;
    }
}
