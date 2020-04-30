<?php

namespace GitHooks\Utils;

class GitFiles
{
    /**
     * Array de ficheros modificados desde el último commit
     *
     * @return array
     */
    public function getModifiedFiles(): array
    {
        $modifiedFiles = null;
        exec('git diff --name-only', $modifiedFiles);

        return $modifiedFiles;
    }
}
