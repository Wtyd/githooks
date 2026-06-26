<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Inspection;

/**
 * Diagnostic model for `conf:check`: the assembled, presentation-free result of
 * checking a configuration file. Built by the command (which owns the parser,
 * job registry and checker); serialised by {@see ConfigCheckJsonFormatter}.
 *
 * `valid` is derived: a config is valid when it produced no errors.
 *
 * @phpstan-type Diagnostics array{errors: string[], warnings: string[], deprecations: array<int, array<string, mixed>>, hint: string|null}
 */
final class ConfigCheckResult
{
    private bool $legacy;

    private string $filePath;

    private ?string $localFilePath;

    /** @var array<string, mixed> */
    private array $options;

    /** @var array<int, array<string, mixed>> */
    private array $hooks;

    /** @var array<int, array<string, mixed>> */
    private array $flows;

    /** @var array<int, array<string, mixed>> */
    private array $jobs;

    /** @var Diagnostics */
    private array $diagnostics;

    /**
     * @param array<string, mixed> $options
     * @param array<int, array<string, mixed>> $hooks
     * @param array<int, array<string, mixed>> $flows
     * @param array<int, array<string, mixed>> $jobs
     * @param Diagnostics $diagnostics
     */
    private function __construct(
        bool $legacy,
        string $filePath,
        ?string $localFilePath,
        array $options,
        array $hooks,
        array $flows,
        array $jobs,
        array $diagnostics
    ) {
        $this->legacy = $legacy;
        $this->filePath = $filePath;
        $this->localFilePath = $localFilePath;
        $this->options = $options;
        $this->hooks = $hooks;
        $this->flows = $flows;
        $this->jobs = $jobs;
        $this->diagnostics = $diagnostics;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, array<string, mixed>> $hooks
     * @param array<int, array<string, mixed>> $flows
     * @param array<int, array<string, mixed>> $jobs
     * @param Diagnostics $diagnostics
     */
    public static function forV3(
        string $filePath,
        ?string $localFilePath,
        array $options,
        array $hooks,
        array $flows,
        array $jobs,
        array $diagnostics
    ): self {
        return new self(false, $filePath, $localFilePath, $options, $hooks, $flows, $jobs, $diagnostics);
    }

    /**
     * @param Diagnostics $diagnostics
     */
    public static function legacy(string $filePath, ?string $localFilePath, array $diagnostics): self
    {
        return new self(true, $filePath, $localFilePath, [], [], [], [], $diagnostics);
    }

    public function isLegacy(): bool
    {
        return $this->legacy;
    }

    public function isValid(): bool
    {
        return empty($this->diagnostics['errors']);
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getLocalFilePath(): ?string
    {
        return $this->localFilePath;
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @return array<int, array<string, mixed>> */
    public function getHooks(): array
    {
        return $this->hooks;
    }

    /** @return array<int, array<string, mixed>> */
    public function getFlows(): array
    {
        return $this->flows;
    }

    /** @return array<int, array<string, mixed>> */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->diagnostics['errors'];
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return $this->diagnostics['warnings'];
    }

    /** @return array<int, array<string, mixed>> */
    public function getDeprecations(): array
    {
        return $this->diagnostics['deprecations'];
    }

    public function getHint(): ?string
    {
        return $this->diagnostics['hint'];
    }
}
