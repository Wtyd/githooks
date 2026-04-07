<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * Immutable value object that carries execution context through the pipeline.
 * In fast mode, provides staged files and path-filtering logic for accelerable jobs.
 */
class ExecutionContext
{
    private bool $fastMode;

    /** @var string[] */
    private array $stagedFiles;

    private ?FileUtilsInterface $fileUtils;

    /**
     * @param string[] $stagedFiles
     */
    public function __construct(bool $fastMode, array $stagedFiles = [], ?FileUtilsInterface $fileUtils = null)
    {
        $this->fastMode = $fastMode;
        $this->stagedFiles = $stagedFiles;
        $this->fileUtils = $fileUtils;
    }

    public static function default(): self
    {
        return new self(false);
    }

    public static function forFastMode(FileUtilsInterface $fileUtils): self
    {
        return new self(true, $fileUtils->getModifiedFiles(), $fileUtils);
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

    /**
     * Filter staged files to only those within the given paths.
     * Returns the subset of staged files that fall inside at least one of the original paths.
     *
     * @param string[] $originalPaths
     * @return string[]
     */
    public function filterFilesForPaths(array $originalPaths): array
    {
        $filtered = [];

        foreach ($this->stagedFiles as $file) {
            if ($this->fileIsInPaths($file, $originalPaths)) {
                $filtered[] = $file;
            }
        }

        return $filtered;
    }

    /** @param string[] $paths */
    private function fileIsInPaths(string $file, array $paths): bool
    {
        foreach ($paths as $path) {
            if ($this->fileUtils !== null && is_file($path) && $this->fileUtils->isSameFile($file, $path)) {
                return true;
            }

            if ($this->fileUtils !== null && $this->fileUtils->directoryContainsFile($path, $file)) {
                return true;
            }
        }

        return false;
    }
}
