<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Immutable snapshot of the runner/runtime at the moment a flow starts
 * (FEAT-14): githooks version, platform, CI, CPU, system memory and load avg.
 * Fields the platform cannot report are null (Windows memory/load, macOS
 * MemAvailable, etc.) and serialise as null without breaking the contract.
 */
final class Diagnostics
{
    private string $version;

    private string $platform;

    private ?string $ciName;

    private int $cpuDetected;

    private ?int $cpuCgroupLimit;

    private ?int $memAvailableMb;

    private ?int $memTotalMb;

    private ?float $loadAvg1;

    private ?float $loadAvg5;

    private ?float $loadAvg15;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Immutable value object.
     */
    public function __construct(
        string $version,
        string $platform,
        ?string $ciName,
        int $cpuDetected,
        ?int $cpuCgroupLimit,
        ?int $memAvailableMb,
        ?int $memTotalMb,
        ?float $loadAvg1,
        ?float $loadAvg5,
        ?float $loadAvg15
    ) {
        $this->version = $version;
        $this->platform = $platform;
        $this->ciName = $ciName;
        $this->cpuDetected = $cpuDetected;
        $this->cpuCgroupLimit = $cpuCgroupLimit;
        $this->memAvailableMb = $memAvailableMb;
        $this->memTotalMb = $memTotalMb;
        $this->loadAvg1 = $loadAvg1;
        $this->loadAvg5 = $loadAvg5;
        $this->loadAvg15 = $loadAvg15;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getCi(): ?string
    {
        return $this->ciName;
    }

    public function getCpuDetected(): int
    {
        return $this->cpuDetected;
    }

    public function getCpuCgroupLimit(): ?int
    {
        return $this->cpuCgroupLimit;
    }

    public function getMemAvailableMb(): ?int
    {
        return $this->memAvailableMb;
    }

    public function getMemTotalMb(): ?int
    {
        return $this->memTotalMb;
    }

    public function getLoadAvg1(): ?float
    {
        return $this->loadAvg1;
    }

    public function getLoadAvg5(): ?float
    {
        return $this->loadAvg5;
    }

    public function getLoadAvg15(): ?float
    {
        return $this->loadAvg15;
    }
}
