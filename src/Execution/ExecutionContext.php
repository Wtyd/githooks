<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * Immutable value object that carries execution context through the pipeline.
 * Supports 3 modes: full, fast (staged files), fast-branch (branch diff + staged).
 * File lists are loaded lazily on first access.
 *
 * Files mode (--files / --files-from) reuses this object via forInputFiles():
 * the resolved list is exposed as if it were "staged", so FlowPreparer's FAST
 * filter pipeline works unchanged. See spec-design-files-flag.md §7.4.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Coheres the staged + diff + cwd + paths
 *   normalisation across the three execution modes; splitting these would scatter the
 *   same concern across collaborators and force callers to compose them by hand.
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

    /**
     * FEAT-13: lazy cache for the worktree diff set (`fast-dirty` mode).
     * Same three-state convention as branchDiffFiles.
     *
     * @var string[]|null|false
     */
    private $worktreeDiffFiles = null;

    /** @var bool Whether staged files have been loaded (for lazy create() factory) */
    private bool $stagedLoaded;

    private ?InputFilesResolution $inputFiles = null;

    private ?string $cwd = null;

    /**
     * FEAT-16: path of the commit-message file for an inline `commit-msg` job.
     * Set by the CLI (`--message-file` / `--message` materialised to a temp
     * file) or by the git hook (`$1`). Null outside a commit-msg context.
     */
    private ?string $commitMessageFile = null;

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

    /**
     * Override the CWD used to fold absolute input-file paths into CWD-relative
     * form before matching against job.paths. Production callers should not need
     * this; tests use it to make the path normalisation deterministic.
     */
    public function withCwd(string $cwd): self
    {
        $clone      = clone $this;
        $clone->cwd = $cwd;
        return $clone;
    }

    /**
     * FEAT-16: attach the resolved commit-message file path. Clone pattern so
     * the context stays immutable, mirroring {@see withCwd()}.
     */
    public function withCommitMessageFile(string $commitMessageFile): self
    {
        $clone = clone $this;
        $clone->commitMessageFile = $commitMessageFile;
        return $clone;
    }

    public function getCommitMessageFile(): ?string
    {
        return $this->commitMessageFile;
    }

    /** The working directory used to resolve relative paths (CWD when unset). */
    public function getCwd(): string
    {
        return $this->cwd ?? (string) getcwd();
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

    /**
     * Build a context backed by a user-provided input files list (--files / --files-from).
     * The resolved list is served as if it were staged so that FAST-mode filtering
     * works without changes (spec §7.4, CON-002).
     */
    public static function forInputFiles(InputFilesResolution $resolution, FileUtilsInterface $fileUtils): self
    {
        $context              = new self(false, $resolution->getValid(), $fileUtils);
        $context->inputFiles  = $resolution;
        $context->stagedLoaded = true;
        return $context;
    }

    public function isFastMode(): bool
    {
        return $this->fastMode;
    }

    public function hasInputFiles(): bool
    {
        return $this->inputFiles !== null;
    }

    public function getInputFilesResolution(): ?InputFilesResolution
    {
        return $this->inputFiles;
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
     * Whether the effective input set for this mode is empty (BUG-15).
     *
     * "Effective set empty" means the upstream source has nothing to validate
     * — `--fast` with no staged files, or `--fast-branch` with no diff vs the
     * base branch. Decoupled from `getPaths()` so all jobs (accelerable or
     * not, with or without paths) can share a single skip predicate.
     *
     * Returns false for FULL (mode does not consume the staged/diff set) and
     * for FAST_BRANCH when the diff itself failed (`getBranchDiffFilesLazy()`
     * returns null, which is the fallback signal — distinct from "diff
     * succeeded but empty").
     */
    public function isEffectiveSetEmpty(string $mode): bool
    {
        if ($mode === ExecutionMode::FAST) {
            $this->ensureStagedLoaded();
            return $this->stagedFiles === [];
        }

        if ($mode === ExecutionMode::FAST_BRANCH) {
            $branchFiles = $this->getBranchDiffFilesLazy();
            return $branchFiles === [];
        }

        if ($mode === ExecutionMode::FAST_DIRTY) {
            $dirtyFiles = $this->getWorktreeDiffFilesLazy();
            return $dirtyFiles === null || $dirtyFiles === [];
        }

        return false;
    }

    /**
     * The full effective file set for the given mode, unfiltered by job paths.
     *
     * Used by FEAT-1 admission rules (`only-files` / `exclude-files` per flow
     * entry): admission is decided over the whole change set, independent of
     * any job's `paths`. Returns null for FULL (mode does not consume the set)
     * and for FAST_BRANCH when the diff lookup failed (same fallback signal
     * as filterFilesForMode).
     *
     * @return string[]|null
     */
    public function getEffectiveSet(string $mode): ?array
    {
        if ($mode === ExecutionMode::FAST) {
            $this->ensureStagedLoaded();
            return $this->stagedFiles;
        }

        if ($mode === ExecutionMode::FAST_BRANCH) {
            return $this->getBranchDiffFilesLazy();
        }

        if ($mode === ExecutionMode::FAST_DIRTY) {
            return $this->getWorktreeDiffFilesLazy();
        }

        return null;
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

        if ($mode === ExecutionMode::FAST_DIRTY) {
            $dirtyFiles = $this->getWorktreeDiffFilesLazy();
            if ($dirtyFiles === null) {
                // No git / no HEAD → treat as empty set (skip path), NOT as
                // fallback-to-full. The contract says clean tree / no repo =
                // nothing to validate.
                return [];
            }
            return $this->filterFileList($dirtyFiles, $paths);
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
     * FEAT-13: lazy load of the worktree diff set. Same three-state cache as
     * {@see getBranchDiffFilesLazy()}: null=not loaded, false=load failed,
     * array=loaded. Returns null on failure so the caller can treat the set
     * as empty (skip universe, NOT fallback to full).
     *
     * @return string[]|null
     */
    private function getWorktreeDiffFilesLazy(): ?array
    {
        if ($this->worktreeDiffFiles === false) {
            return null;
        }

        if (is_array($this->worktreeDiffFiles)) {
            return $this->worktreeDiffFiles;
        }

        if ($this->fileUtils === null) {
            $this->worktreeDiffFiles = false;
            return null;
        }

        $files = $this->fileUtils->getWorktreeDiffFiles();
        if ($files === null) {
            $this->worktreeDiffFiles = false;
            return null;
        }

        $this->worktreeDiffFiles = $files;
        return $this->worktreeDiffFiles;
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
        $normalised = $this->normaliseToCwdRelative($file);

        foreach ($paths as $path) {
            if ($this->fileUtils !== null && is_file($path) && $this->fileUtils->isSameFile($normalised, $path)) {
                return true;
            }

            if ($this->fileUtils !== null && $this->fileUtils->directoryContainsFile($path, $normalised)) {
                return true;
            }
        }

        return false;
    }

    /**
     * V33-029: --files acepta rutas absolutas, pero job.paths siempre es
     * relativo al CWD; sin esta normalización el matcher (string-equals)
     * falla en silencio y el job se skipea con `matched=[]`.
     * Ver tests/Unit/Execution/factors-input-files.md.
     */
    private function normaliseToCwdRelative(string $file): string
    {
        if (!$this->isAbsolutePath($file)) {
            return $file;
        }
        $cwd = $this->cwd ?? (string) getcwd();
        if ($cwd === '') {
            return $file;
        }
        $cwdNormalised  = rtrim(str_replace('\\', '/', $cwd), '/') . '/';
        $fileNormalised = str_replace('\\', '/', $file);
        if (strncmp($fileNormalised, $cwdNormalised, strlen($cwdNormalised)) === 0) {
            return substr($fileNormalised, strlen($cwdNormalised));
        }
        return $file;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }
        // Windows drive letter (C:\..)
        return strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '/' || $path[2] === '\\');
    }
}
