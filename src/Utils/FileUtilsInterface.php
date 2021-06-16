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
}
