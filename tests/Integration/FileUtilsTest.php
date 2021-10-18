<?php

namespace Tests\Integration;

use Wtyd\GitHooks\Utils\FileUtils;
use Illuminate\Container\Container;
use Tests\ConsoleTestCase;
use Tests\Utils\PhpFileBuilder;
use Tests\SystemTestCase;

/**
 * Before executing this test suite after any changes, you must commit these changes
 * @group git
 */
class FileUtilsTest extends ConsoleTestCase
{
    protected static $gitFilesPathTest = __DIR__ . '/../../testsDir/gitTests';

    protected function setUp(): void
    {
        $this->deleteDirStructure();

        $this->createDirStructure();
        mkdir(self::$gitFilesPathTest);

        $this->container = Container::getInstance();
        $this->container->bind(FileUtilsInterface::class, FileUtils::class);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    /** @test */
    function it_retrieve_modified_files()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        $filename = self::$gitFilesPathTest . '/NewFile.php';
        file_put_contents($filename, $fileBuilder->build());

        shell_exec('git add ' . self::$gitFilesPathTest . '/NewFile.php');

        $gitFiles = $this->container->make(FileUtils::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([$this->deletePathPrefix($filename)], $modifiedFiles);

        $this->deleteDirStructure();

        shell_exec('git add ' . self::$gitFilesPathTest . '/NewFile.php');
    }

    /** @test */
    function it_retrieve_an_empty_array_when_there_are_no_modified_files()
    {
        $gitFiles = $this->container->make(FileUtils::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);
    }

    /** @test */
    function it_retrieve_an_empty_array_when_the_modified_files_there_are_no_added_to_the_git_stage()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        file_put_contents(self::$gitFilesPathTest . '/NewFile.php', $fileBuilder->build());

        $gitFiles = $this->container->make(FileUtils::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);
    }

    /**
     * @param string $path Absolute path. For example: /var/www/html/githooks/tests/NewFile.php
     *
     * @return string Only the relative path of the file to root project. For example: tests/NewFile.php
     */
    public function deletePathPrefix(string $path): string
    {
        $path = explode('testsDir/', $path);

        return 'testsDir/' . $path[1];
    }
}
