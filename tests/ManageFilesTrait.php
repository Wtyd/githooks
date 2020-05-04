<?php

namespace Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait ManageFilesTrait
{
    protected $path = __DIR__ . '/System/tmp';

    protected function deleteDir()
    {
        $dir = $this->path;
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator(
            $it,
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }

    public function getPath()
    {
        return $this->path;
    }

    public function createDirStructure()
    {
        mkdir($this->path);
        mkdir($this->path . '/src');
        mkdir($this->path . '/vendor');
    }

    public function deleteDirStructure()
    {
        if (is_dir($this->path)) {
            $this->deleteDir();
        }
    }
}
