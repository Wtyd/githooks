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
        exec('git rev-parse --abbrev-ref HEAD 2>/dev/null', $output);
        return $output[0] ?? '';
    }

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple fallback strategies for robustness
     */
    public function getBranchDiffFiles(string $mainBranch): ?array
    {
        // Try to find the merge-base
        $baseCommit = [];
        $returnCode = 0;
        exec("git merge-base origin/$mainBranch HEAD 2>/dev/null", $baseCommit, $returnCode);

        if ($returnCode !== 0 || empty($baseCommit)) {
            // Try fetching the branch first (CI environments with shallow clones)
            $fetchOutput = [];
            exec("git fetch origin $mainBranch --depth=1 2>/dev/null", $fetchOutput);

            // Retry merge-base
            $baseCommit = [];
            exec("git merge-base origin/$mainBranch HEAD 2>/dev/null", $baseCommit, $returnCode);

            if ($returnCode !== 0 || empty($baseCommit)) {
                // Last attempt: try without origin/ prefix (local branch)
                exec("git merge-base $mainBranch HEAD 2>/dev/null", $baseCommit, $returnCode);

                if ($returnCode !== 0 || empty($baseCommit)) {
                    return null;
                }
            }
        }

        $diffFiles = [];
        exec("git diff --name-only --diff-filter=ACMR {$baseCommit[0]}...HEAD 2>/dev/null", $diffFiles, $returnCode);

        if ($returnCode !== 0) {
            return null;
        }

        // Union with staged files (dedup)
        $stagedFiles = $this->getModifiedFiles();
        return array_values(array_unique(array_merge($diffFiles, $stagedFiles)));
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
        $output = [];
        exec('git symbolic-ref refs/remotes/origin/HEAD 2>/dev/null', $output);
        if (!empty($output[0])) {
            // Output is like "refs/remotes/origin/master"
            $parts = explode('/', $output[0]);
            return end($parts);
        }

        // 3. Try common branch names
        foreach (['master', 'main'] as $candidate) {
            $refs = [];
            $returnCode = 0;
            exec("git show-ref --verify refs/heads/$candidate 2>/dev/null", $refs, $returnCode);
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
