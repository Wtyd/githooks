<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * Immutable value object that carries execution context through the pipeline.
 * Currently provides staged files for fast mode in custom jobs.
 */
class ExecutionContext
{
    private bool $fastMode;

    /** @var string[] */
    private array $stagedFiles;

    /**
     * @param string[] $stagedFiles
     */
    public function __construct(bool $fastMode, array $stagedFiles = [])
    {
        $this->fastMode = $fastMode;
        $this->stagedFiles = $stagedFiles;
    }

    public static function default(): self
    {
        return new self(false);
    }

    public static function forFastMode(FileUtilsInterface $fileUtils): self
    {
        return new self(true, $fileUtils->getModifiedFiles());
    }

    public function isFastMode(): bool
    {
        return $this->fastMode;
    }

    /** @return string[] */
    public function getStagedFiles(): array
    {
        return $this->stagedFiles;
    }
}
