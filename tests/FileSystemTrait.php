<?php

namespace Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait FileSystemTrait
{
    /**
     * @var string Path to directory for tests that require filesystem
     */
    protected $path = SystemTestCase::TESTS_PATH;

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Creates de directory structure for testing.
     * A root path, determinated by $path with two subdirectories, src and vendor.
     *
     * @return void
     */
    public function createDirStructure(): void
    {
        mkdir($this->path . '/src');
        mkdir($this->path . '/vendor');
    }

    /**
     * Delete de directory structure for testing with all subdirectories and files.
     *
     * @return void
     */
    public function deleteDirStructure(): void
    {
        if (is_dir($this->path)) {
            $this->deleteDir();
        }
    }

    /**
     * Delete all content from diretory for testing
     *
     * @return void
     */
    protected function deleteDir(): void
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
            } elseif ('.gitkeep' !== $file->getFileName()) {
                unlink($file->getRealPath());
            }
        }
    }
}
