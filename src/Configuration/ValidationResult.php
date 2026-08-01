<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

class ValidationResult
{
    /** @var string[] */
    private array $errors = [];

    /** @var string[] */
    private array $warnings = [];

    /** @var Deprecation[] */
    private array $deprecations = [];

    /**
     * Jobs the parser refused to build, keyed by job name, each with the errors
     * that caused the rejection. They are absent from the parsed job list, so
     * without this record a consumer inspecting the configuration cannot report
     * on them at all — they would only exist as free-text error strings.
     *
     * @var array<string, string[]>
     */
    private array $rejectedJobs = [];

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Record a structured deprecation. Also emits the canonical user-facing
     * warning string via addWarning() so the existing stderr pipeline surfaces
     * it without per-consumer changes.
     */
    public function addDeprecation(Deprecation $deprecation): void
    {
        $this->deprecations[] = $deprecation;
        $this->warnings[] = $deprecation->getWarningMessage();
    }

    /**
     * Record a job that could not be built. The errors are already in
     * getErrors() — this keeps the association with the job that caused them.
     *
     * @param string[] $errors
     */
    public function addRejectedJob(string $name, array $errors): void
    {
        $this->rejectedJobs[$name] = array_values($errors);
    }

    /** @return array<string, string[]> */
    public function getRejectedJobs(): array
    {
        return $this->rejectedJobs;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /** @return Deprecation[] */
    public function getDeprecations(): array
    {
        return $this->deprecations;
    }

    public function merge(self $other): self
    {
        $merged = new self();
        $merged->errors = array_merge($this->errors, $other->errors);
        $merged->warnings = array_merge($this->warnings, $other->warnings);
        $merged->deprecations = array_merge($this->deprecations, $other->deprecations);
        $merged->rejectedJobs = array_merge($this->rejectedJobs, $other->rejectedJobs);
        return $merged;
    }
}
