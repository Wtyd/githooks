<?php

namespace Tests\Utils;

use Wtyd\GitHooks\Utils\FileUtils;

class FileUtilsFake extends FileUtils
{
    protected $modifiedFiles = [];

    protected $filesThatShouldBeFoundInDirectories = [];

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
}
