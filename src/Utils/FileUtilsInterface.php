<?php

namespace Wtyd\GitHooks\Utils;

interface FileUtilsInterface
{
    /**
     * @return array Files modified and staged since last commit.
     */
    public function getModifiedFiles(): array;

    /**
     * Check if two files are the same file. The problem comes when the configuration file file is preceded by the string
     * ROOT_PATH.
     *
     * @param string $file1
     * @param string $file2
     * @return boolean
     */
    public function isSameFile(string $file1, string $file2): bool;

    /**
     * If the $directory is root of work directory it is sure that the modified file is in $directory.
     *
     * @param string $directory
     * @param string $file
     * @return boolean
     */
    public function directoryContainsFile(string $directory, string $file): bool;

    /**
     * @return string Current git branch name, or empty string if not in a git repo.
     */
    public function getCurrentBranch(): string;

    /**
     * Get files that differ between the current branch and the main branch.
     * Includes staged files (union, deduplicated).
     *
     * @return string[]|null Null if unable to compute (shallow clone, missing ref, etc.)
     */
    public function getBranchDiffFiles(string $mainBranch): ?array;

    /**
     * Auto-detect the main branch name.
     * Checks CI env vars (GITHUB_BASE_REF, CI_MERGE_REQUEST_TARGET_BRANCH_NAME,
     * BITBUCKET_PR_DESTINATION_BRANCH), then git symbolic-ref, then tries master/main.
     *
     * @return string|null Null if unable to detect.
     */
    public function detectMainBranch(): ?string;
}
