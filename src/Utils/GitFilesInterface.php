<?php

namespace GitHooks\Utils;

interface GitFilesInterface
{
    /**
     * @return array Files modified and staged since last commit.
     */
    public function getModifiedFiles(): array;
}
