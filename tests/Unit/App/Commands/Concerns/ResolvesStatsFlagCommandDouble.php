<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Wtyd\GitHooks\App\Commands\Concerns\ResolvesStatsFlag;

/**
 * @SuppressWarnings(PHPMD)
 */
class ResolvesStatsFlagCommandDouble
{
    use ResolvesStatsFlag;

    /** @var array<string, mixed> */
    public array $options = [];

    /**
     * @param mixed|null $name
     * @return mixed|null
     */
    public function option(string $name = null)
    {
        return $this->options[$name] ?? null;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function call(): ?bool
    {
        return $this->resolveStatsFlag();
    }
}
