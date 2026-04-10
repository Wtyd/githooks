<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * Immutable value object that carries execution context through the pipeline.
 * Supports 3 modes: full, fast (staged files), fast-branch (branch diff + staged).
 * File lists are loaded lazily on first access.
 */
class ExecutionContext
{
    private bool $fastMode;

    /** @var string[] */
    private array $stagedFiles;

    private ?FileUtilsInterface $fileUtils;

    private ?string $mainBranch;

    /** @var string[]|null|false  null=not loaded, false=load failed, array=loaded */
    private $branchDiffFiles = null;

    /** @var bool Whether staged files have been loaded (for lazy create() factory) */
    private bool $stagedLoaded;

    /**
     * @param string[] $stagedFiles
     */
    public function __construct(bool $fastMode, array $stagedFiles = [], ?FileUtilsInterface $fileUtils = null)
    {
        $this->fastMode = $fastMode;
        $this->stagedFiles = $stagedFiles;
        $this->fileUtils = $fileUtils;
        $this->mainBranch = null;
        $this->stagedLoaded = true;
    }

    public static function default(): self
    {
        return new self(false);
    }

    public static function forFastMode(FileUtilsInterface $fileUtils): self
    {
        return new self(true, $fileUtils->getModifiedFiles(), $fileUtils);
    }

    /**
     * Create a lazy context that loads file lists on demand.
     * Does not compute any file lists at creation time.
     */
    public static function create(FileUtilsInterface $fileUtils, ?string $mainBranch): self
    {
        $context = new self(false, [], $fileUtils);
        $context->mainBranch = $mainBranch;
        $context->stagedLoaded = false;
        return $context;
    }

    public function isFastMode(): bool
    {
        return $this->fastMode;
    }

    /** @return string[] */
    public function getStagedFiles(): array
    {
        $this->ensureStagedLoaded();
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
        $this->ensureStagedLoaded();
        return $this->filterFileList($this->stagedFiles, $originalPaths);
    }

    /**
     * Filter files for a specific execution mode.
     *
     * @param string[] $paths Job's configured paths
     * @return string[]|null Filtered files, or null if mode=full or if fast-branch diff failed
     */
    public function filterFilesForMode(string $mode, array $paths): ?array
    {
        if ($mode === ExecutionMode::FULL) {
            return null;
        }

        if ($mode === ExecutionMode::FAST) {
            $this->ensureStagedLoaded();
            return $this->filterFileList($this->stagedFiles, $paths);
        }

        if ($mode === ExecutionMode::FAST_BRANCH) {
            $branchFiles = $this->getBranchDiffFilesLazy();
            if ($branchFiles === null) {
                return null; // signal fallback
            }
            return $this->filterFileList($branchFiles, $paths);
        }

        return null;
    }

    /**
     * Load staged files lazily (for create() factory).
     */
    private function ensureStagedLoaded(): void
    {
        if (!$this->stagedLoaded && $this->fileUtils !== null) {
            $this->stagedFiles = $this->fileUtils->getModifiedFiles();
            $this->stagedLoaded = true;
        }
    }

    /**
     * Load branch diff files lazily. Returns deduplicated union of staged + branch diff,
     * or null if the diff could not be computed.
     *
     * @return string[]|null
     */
    private function getBranchDiffFilesLazy(): ?array
    {
        if ($this->branchDiffFiles === false) {
            return null; // previously failed
        }

        if (is_array($this->branchDiffFiles)) {
            return $this->branchDiffFiles;
        }

        // Not loaded yet
        if ($this->fileUtils === null || $this->mainBranch === null) {
            $this->branchDiffFiles = false;
            return null;
        }

        $diffFiles = $this->fileUtils->getBranchDiffFiles($this->mainBranch);
        if ($diffFiles === null) {
            $this->branchDiffFiles = false;
            return null;
        }

        // Union with staged files (dedup)
        $this->ensureStagedLoaded();
        $this->branchDiffFiles = array_values(array_unique(array_merge($diffFiles, $this->stagedFiles)));

        return $this->branchDiffFiles;
    }

    /**
     * @param string[] $files
     * @param string[] $paths
     * @return string[]
     */
    private function filterFileList(array $files, array $paths): array
    {
        $filtered = [];

        foreach ($files as $file) {
            if ($this->fileIsInPaths($file, $paths)) {
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
