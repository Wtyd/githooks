<?php

namespace Wtyd\GitHooks\Utils;

use Illuminate\Support\Facades\Storage;
use Wtyd\GitHooks\LoadTools\ExecutionMode;

class FileUtils implements FileUtilsInterface
{
    /**
     * @return array Files modified and staged since last commit.
     */
    public function getModifiedFiles(): array
    {
        $modifiedFiles = [];
        exec('git diff --cached --name-only', $modifiedFiles);
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
}
