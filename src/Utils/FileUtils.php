<?php

namespace Wtyd\GitHooks\Utils;

use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Wtyd\GitHooks\LoadTools\ExecutionMode;

class FileUtils implements FileUtilsInterface
{
    /**
     * @return array Files modified and staged since last commit.
     */
    public function getModifiedFiles(): array
    {
        $modifiedFiles = [];
        exec('git diff --cached --name-only --diff-filter=ACMR', $modifiedFiles);
        //git diff --cached --name-status

        return $modifiedFiles;
    }

    /**
     * Check if two files are the same file. The problem comes when the configuration file file is preceded by the string
     * ROOT_PATH.
     *
     * @param string $file1
     * @param string $file2
     * @return boolean
     */
    public function isSameFile(string $file1, string $file2): bool
    {
        $file1 = explode(ExecutionMode::ROOT_PATH, $file1);
        $file1 = count($file1) > 1 ? $file1[1] : $file1[0];

        $file2 = explode(ExecutionMode::ROOT_PATH, $file2);
        $file2 = count($file2) > 1 ? $file2[1] : $file2[0];

        return $file1 === $file2;
    }

    /**
     * Three possibilities:
     * 1. The file doesn't exist. The file has been deleted.
     * 2. The tool has setted the root directory of the project. Clearly the file belongs to the $directory.
     * 3. In other case, the file is searche in the $directory.
     *
     * @param string $directory
     * @param string $file
     * @return boolean
     */
    public function directoryContainsFile(string $directory, string $file): bool
    {
        if (!Storage::exists($file)) {
            return false;
        }

        if ($directory === ExecutionMode::ROOT_PATH) {
            return true;
        }

        return in_array($file, Storage::allFiles($directory));
    }

    public function getCurrentBranch(): string
    {
        $output = [];
        $silent = Platform::stderrRedirect();
        exec("git rev-parse --abbrev-ref HEAD $silent", $output);
        return $output[0] ?? '';
    }

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple fallback strategies for robustness
     */
    public function getBranchDiffFiles(string $mainBranch): ?array
    {
        // Use the platform-portable null device so stderr is silenced on
        // Windows too — `2>/dev/null` is bash-only and on cmd.exe it tries
        // to create a literal `dev\null` file relative to the cwd, which
        // either fails or pollutes the working tree.
        $silent = Platform::stderrRedirect();

        // Try to find the merge-base
        $baseCommit = [];
        $returnCode = 0;
        exec("git merge-base origin/$mainBranch HEAD $silent", $baseCommit, $returnCode);

        if ($returnCode !== 0 || empty($baseCommit)) {
            // Try fetching the branch first (CI environments with shallow clones)
            $fetchOutput = [];
            exec("git fetch origin $mainBranch --depth=1 $silent", $fetchOutput);

            // Retry merge-base
            $baseCommit = [];
            exec("git merge-base origin/$mainBranch HEAD $silent", $baseCommit, $returnCode);

            if ($returnCode !== 0 || empty($baseCommit)) {
                // Last attempt: try without origin/ prefix (local branch)
                exec("git merge-base $mainBranch HEAD $silent", $baseCommit, $returnCode);

                if ($returnCode !== 0 || empty($baseCommit)) {
                    return null;
                }
            }
        }

        $diffFiles = [];
        exec("git diff --name-only --diff-filter=ACMR {$baseCommit[0]}...HEAD $silent", $diffFiles, $returnCode);

        if ($returnCode !== 0) {
            return null;
        }

        // Union with staged files (dedup)
        $stagedFiles = $this->getModifiedFiles();
        return array_values(array_unique(array_merge($diffFiles, $stagedFiles)));
    }

    /**
     * FEAT-13: unified working-tree set.
     * Returns the union of:
     *   - tracked files with A/C/M/R differences vs HEAD (staged or unstaged),
     *   - untracked files that are not gitignored.
     * Deleted entries (D) are excluded by --diff-filter=ACMR so the returned
     * paths always exist on disk. Renamed entries surface as the destination.
     *
     * Returns null when the cwd is not a git repository or HEAD does not
     * resolve (fresh init without a commit, etc.). Callers treat null as
     * "set empty" via {@see ExecutionContext::isEffectiveSetEmpty()}.
     *
     * @return string[]|null
     */
    public function getWorktreeDiffFiles(): ?array
    {
        $silent = Platform::stderrRedirect();

        // First gate: we must be inside a git work tree.
        $isInsideWorkTree = [];
        $returnCode = 0;
        exec("git rev-parse --is-inside-work-tree $silent", $isInsideWorkTree, $returnCode);
        if ($returnCode !== 0 || ($isInsideWorkTree[0] ?? '') !== 'true') {
            return null;
        }

        // Second gate: HEAD must resolve so `git diff HEAD` has a baseline.
        $headRev = [];
        exec("git rev-parse --verify HEAD $silent", $headRev, $returnCode);
        if ($returnCode !== 0) {
            return null;
        }

        // Tracked: union of staged+unstaged vs HEAD, excluding D.
        $tracked = [];
        exec("git diff --name-only --diff-filter=ACMR HEAD $silent", $tracked, $returnCode);
        if ($returnCode !== 0) {
            return null;
        }

        // Untracked, respecting .gitignore.
        $untracked = [];
        exec("git ls-files --others --exclude-standard $silent", $untracked, $returnCode);
        if ($returnCode !== 0) {
            return null;
        }

        return array_values(array_unique(array_merge($tracked, $untracked)));
    }

    /**
     * @inheritDoc
     */
    public function detectMainBranch(): ?string
    {
        // 1. CI environment variables
        $ciVars = [
            'GITHUB_BASE_REF',
            'CI_MERGE_REQUEST_TARGET_BRANCH_NAME',
            'BITBUCKET_PR_DESTINATION_BRANCH',
        ];

        foreach ($ciVars as $var) {
            $value = getenv($var);
            if ($value !== false && $value !== '') {
                return $value;
            }
        }

        // 2. git symbolic-ref (local, no network)
        $silent = Platform::stderrRedirect();
        $output = [];
        exec("git symbolic-ref refs/remotes/origin/HEAD $silent", $output);
        if (!empty($output[0])) {
            // Output is like "refs/remotes/origin/master"
            $parts = explode('/', $output[0]);
            return end($parts);
        }

        // 3. Try common branch names
        foreach (['master', 'main'] as $candidate) {
            $refs = [];
            $returnCode = 0;
            exec("git show-ref --verify refs/heads/$candidate $silent", $refs, $returnCode);
            if ($returnCode === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function expandDirectory(string $directory, array $extensions): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        $hasFilter = !empty($extensions);
        $extSet = array_flip(array_map('strtolower', $extensions));

        $files = [];
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            if ($hasFilter && !isset($extSet[strtolower($entry->getExtension())])) {
                continue;
            }
            $files[] = str_replace('\\', '/', $entry->getPathname());
        }

        sort($files);

        return $files;
    }
}
