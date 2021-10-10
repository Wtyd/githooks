<?php

namespace Tests;

use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait FileSystemTrait
{
    /**
     * @var string Path to directory for tests that require filesystem
     */
    protected $path = ConsoleTestCase::TESTS_PATH;

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
            self::deleteDir();
        }
    }

    /**
     * Delete all content from diretory for testing
     *
     * @return void
     */
    protected static function deleteDir(): void
    {
        $dir = ConsoleTestCase::TESTS_PATH;
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
    public static function cleanTestsFilesystem()
    {

        self::deleteDir();
    }
}
