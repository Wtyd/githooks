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
        return $merged;
    }
}
