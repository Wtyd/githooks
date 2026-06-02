<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Output\Diagnostics\DiagnosticsCollector;
use Wtyd\GitHooks\Utils\CpuDetector;
use Wtyd\GitHooks\Utils\MemoryDetector;

/**
 * Deterministic {@see DiagnosticsCollector}: fixed version/platform/ci/load/clock
 * plus injected detector stubs.
 */
class DiagnosticsCollectorStub extends DiagnosticsCollector
{
    private string $version;

    private string $platform;

    private ?string $ci;

    /** @var array<int, float> */
    private array $load;

    private float $clock;

    /** @param array<int, float> $load */
    public function __construct(
        CpuDetector $cpu,
        MemoryDetector $memory,
        string $version = '3.5.0',
        string $platform = 'linux',
        ?string $ci = null,
        array $load = [],
        float $clock = 0.0
    ) {
        parent::__construct($cpu, $memory);
        $this->version = $version;
        $this->platform = $platform;
        $this->ci = $ci;
        $this->load = $load;
        $this->clock = $clock;
    }

    protected function microtime(): float
    {
        return $this->clock;
    }

    protected function version(): string
    {
        return $this->version;
    }

    protected function platform(): string
    {
        return $this->platform;
    }

    protected function detectCi(): ?string
    {
        return $this->ci;
    }

    protected function loadAverage(): array
    {
        return $this->load;
    }
}
