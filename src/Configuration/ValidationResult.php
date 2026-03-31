<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

class ValidationResult
{
    /** @var string[] */
    private array $errors = [];

    /** @var string[] */
    private array $warnings = [];

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
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

    public function merge(self $other): self
    {
        $merged = new self();
        $merged->errors = array_merge($this->errors, $other->errors);
        $merged->warnings = array_merge($this->warnings, $other->warnings);
        return $merged;
    }
}
