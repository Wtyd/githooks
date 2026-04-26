<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Immutable result of resolving --files / --files-from / --exclude-pattern.
 *
 * Holds the full picture: where the list came from, which paths survived
 * existence + extension filtering, which were excluded by patterns, and which
 * were dropped because they did not exist. Consumed by ExecutionContext (to
 * feed the FAST filter pipeline) and by formatters (to emit the JSON v2
 * `inputFiles` block — §4.5 of spec-design-files-flag.md).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Value object: getters only, no logic.
 */
class InputFilesResolution
{
    public const SOURCE_CLI         = 'cli';
    public const SOURCE_FILES_FROM  = 'files-from';

    private string $source;

    private ?string $sourcePath;

    /** @var string[] Final list after expansion + exclude-pattern (the working set). */
    private array $valid;

    /** @var string[] Paths originally provided that could not be resolved (REQ-012). */
    private array $invalid;

    /** @var string[] Patterns provided in --exclude-pattern (empty if none). */
    private array $excludedPatterns;

    /** @var string[] Paths discarded by --exclude-pattern. */
    private array $excluded;

    private int $totalProvided;

    private bool $bomDetected;

    /**
     * @param string[] $valid
     * @param string[] $invalid
     * @param string[] $excludedPatterns
     * @param string[] $excluded
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Optional flag for BOM warning.
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Value object aggregator.
     */
    public function __construct(
        string $source,
        ?string $sourcePath,
        array $valid,
        array $invalid,
        array $excludedPatterns,
        array $excluded,
        int $totalProvided,
        bool $bomDetected = false
    ) {
        $this->source           = $source;
        $this->sourcePath       = $sourcePath;
        $this->valid            = array_values($valid);
        $this->invalid          = array_values($invalid);
        $this->excludedPatterns = array_values($excludedPatterns);
        $this->excluded         = array_values($excluded);
        $this->totalProvided    = $totalProvided;
        $this->bomDetected      = $bomDetected;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourcePath(): ?string
    {
        return $this->sourcePath;
    }

    /** @return string[] */
    public function getValid(): array
    {
        return $this->valid;
    }

    /** @return string[] */
    public function getInvalid(): array
    {
        return $this->invalid;
    }

    /** @return string[] */
    public function getExcludedPatterns(): array
    {
        return $this->excludedPatterns;
    }

    /** @return string[] */
    public function getExcluded(): array
    {
        return $this->excluded;
    }

    public function getTotalProvided(): int
    {
        return $this->totalProvided;
    }

    public function getTotalValid(): int
    {
        return count($this->valid);
    }

    public function getTotalAfterExclude(): int
    {
        return count($this->valid);
    }

    public function hasExcludePatterns(): bool
    {
        return !empty($this->excludedPatterns);
    }

    public function isBomDetected(): bool
    {
        return $this->bomDetected;
    }
}
