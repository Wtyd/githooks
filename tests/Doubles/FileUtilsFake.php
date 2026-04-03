<?php

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\FileUtils;

class FileUtilsFake extends FileUtils
{
    protected array $modifiedFiles = [];

    protected string $currentBranch = 'main';

    protected array $filesThatShouldBeFoundInDirectories = [];

    public function getModifiedFiles(): array
    {
        return $this->modifiedFiles;
    }

    public function setModifiedfiles(array $modifiedFiles): void
    {
        $this->modifiedFiles = $modifiedFiles;
    }

    public function setFilesThatShouldBeFoundInDirectories(array $filesThatShouldBeFoundInDirectories): void
    {
        $this->filesThatShouldBeFoundInDirectories = $filesThatShouldBeFoundInDirectories;
    }

    public function directoryContainsFile(string $directory, string $file): bool
    {
        if (in_array($file, $this->filesThatShouldBeFoundInDirectories)) {
            return true;
        }
        return false;
    }

    public function getCurrentBranch(): string
    {
        return $this->currentBranch;
    }

    public function setCurrentBranch(string $branch): void
    {
        $this->currentBranch = $branch;
    }
}
