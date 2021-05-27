<?php

namespace Wtyd\GitHooks\Utils;

class GitFiles implements GitFilesInterface
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
}
