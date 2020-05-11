<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Al llamar a setOutputCallback escondemos cualquier output por la consola que no venga de phpunit
 */
class SystemTestCase extends TestCase
{
    protected $path = __DIR__ . '/System/tmp';

    protected function hiddenConsoleOutput(): void
    {
        $this->setOutputCallback(function () {
        });
    }



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
