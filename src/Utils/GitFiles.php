<?php

namespace GitHooks\Utils;

class GitFiles
{
    /**
     * @return array Files modified since the last commit.
     */
    public function getModifiedFiles(): array
    {
        $modifiedFiles = [];
        exec('git diff --cached --name-only', $modifiedFiles);

        return $modifiedFiles;
    }
}
