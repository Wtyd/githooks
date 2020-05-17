<?php

namespace Tests\System;

use GitHooks\Constants;
use GitHooks\Utils\GitFiles;

class GitFilesFake extends GitFiles
{
    protected $modifiedFiles = [];

    public function getModifiedFiles(): array
    {
        return $this->modifiedFiles;
    }

    public function setModifiedfiles(array $modifiedFiles): void
    {
        $this->modifiedFiles = $modifiedFiles;
    }
}
