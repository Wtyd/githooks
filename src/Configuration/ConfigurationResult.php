<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

class ConfigurationResult
{
    private string $filePath;

    private OptionsConfiguration $globalOptions;

    /** @var array<string, JobConfiguration> */
    private array $jobs;

    /** @var array<string, FlowConfiguration> */
    private array $flows;

    private ?HookConfiguration $hooks;

    private ValidationResult $validation;

    private bool $isLegacy;

    /** @var array<string, mixed>|null Raw config for legacy format bridge */
    private ?array $legacyConfig;

    private ?string $localFilePath = null;

    /**
     * @param array<string, JobConfiguration> $jobs
     * @param array<string, FlowConfiguration> $flows
     */
    public function __construct(
        string $filePath,
        OptionsConfiguration $globalOptions,
        array $jobs,
        array $flows,
        ?HookConfiguration $hooks,
        ValidationResult $validation
    ) {
        $this->filePath = $filePath;
        $this->globalOptions = $globalOptions;
        $this->jobs = $jobs;
        $this->flows = $flows;
        $this->hooks = $hooks;
        $this->validation = $validation;
        $this->isLegacy = false;
        $this->legacyConfig = null;
    }

    /** @param array<string, mixed> $rawConfig */
    public static function legacy(array $rawConfig, string $filePath, ValidationResult $validation): self
    {
        $instance = new self(
            $filePath,
            new OptionsConfiguration(),
            [],
            [],
            null,
            $validation
        );
        $instance->isLegacy = true;
        $instance->legacyConfig = $rawConfig;
        return $instance;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getGlobalOptions(): OptionsConfiguration
    {
        return $this->globalOptions;
    }

    /** @return array<string, JobConfiguration> */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    public function getJob(string $name): ?JobConfiguration
    {
        return $this->jobs[$name] ?? null;
    }

    /** @return array<string, FlowConfiguration> */
    public function getFlows(): array
    {
        return $this->flows;
    }

    public function getFlow(string $name): ?FlowConfiguration
    {
        return $this->flows[$name] ?? null;
    }

    public function getHooks(): ?HookConfiguration
    {
        return $this->hooks;
    }

    public function getValidation(): ValidationResult
    {
        return $this->validation;
    }

    public function hasErrors(): bool
    {
        return $this->validation->hasErrors();
    }

    public function isLegacy(): bool
    {
        return $this->isLegacy;
    }

    /** @return array<string, mixed>|null */
    public function getLegacyConfig(): ?array
    {
        return $this->legacyConfig;
    }

    public function getLocalFilePath(): ?string
    {
        return $this->localFilePath;
    }

    public function setLocalFilePath(?string $localFilePath): void
    {
        $this->localFilePath = $localFilePath;
    }
}
