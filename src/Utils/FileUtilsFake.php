<?php

namespace Wtyd\GitHooks\Utils;

use Wtyd\GitHooks\Utils\FileUtils;

class FileUtilsFake extends FileUtils
{
    /** @var array */
    protected $modifiedFiles = [];

    /** @var array */
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
