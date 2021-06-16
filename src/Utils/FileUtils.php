<?php

namespace Wtyd\GitHooks\Utils;

use Wtyd\GitHooks\LoadTools\StrategyInterface;
use Storage;

class FileUtils implements FileUtilsInterface
{
    /**
     * @return array Files modified and staged since last commit.
     */
    public function getModifiedFiles(): array
    {
        $modifiedFiles = [];
        exec('git diff --cached --name-only', $modifiedFiles);

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
        $file1 = explode(StrategyInterface::ROOT_PATH, $file1);
        $file1 = count($file1) > 1 ? $file1[1] : $file1[0];

        $file2 = explode(StrategyInterface::ROOT_PATH, $file2);
        $file2 = count($file2) > 1 ? $file2[1] : $file2[0];

        return $file1 === $file2;
    }

    /**
     * If the $directory is root of work directory it is sure that the modified file is in $directory.
     *
     * @param string $directory
     * @param string $file
     * @return boolean
     */
    public function directoryContainsFile(string $directory, string $file): bool
    {
        if ($directory === StrategyInterface::ROOT_PATH) {
            return true;
        }
        return in_array($file, Storage::allFiles($directory));
    }
}
