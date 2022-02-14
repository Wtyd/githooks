<?php

namespace Tests\Integration;

use Wtyd\GitHooks\Utils\FileUtils;
use Tests\Utils\PhpFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;

/**
 * Before executing this test suite after any changes, you must commit these changes
 * @group git
 */
class FileUtilsTest extends SystemTestCase
{
    protected static $gitFilesPathTest = __DIR__ . '/../../' . SystemTestCase::TESTS_PATH . '/gitTests';

    protected function setUp(): void
    {
        parent::setUp();

        mkdir(self::$gitFilesPathTest);

        $this->app->bind(FileUtilsInterface::class, FileUtils::class);
    }

    /** @test */
    function it_retrieve_modified_files()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        $filename = self::$gitFilesPathTest . '/NewFile.php';
        file_put_contents($filename, $fileBuilder->build());

        shell_exec('git add ' . self::$gitFilesPathTest . '/NewFile.php');

        $gitFiles = $this->app->make(FileUtils::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([$this->deletePathPrefix($filename)], $modifiedFiles);

        $this->deleteDirStructure();

        shell_exec('git add ' . self::$gitFilesPathTest . '/NewFile.php');
    }

    /** @test */
    function it_retrieve_an_empty_array_when_there_are_no_modified_files()
    {
        $gitFiles = $this->app->make(FileUtils::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);
    }

    /** @test */
    function it_retrieve_an_empty_array_when_the_modified_files_there_are_no_added_to_the_git_stage()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        file_put_contents(self::$gitFilesPathTest . '/NewFile.php', $fileBuilder->build());

        $gitFiles = $this->app->make(FileUtils::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);
    }

    /** @test */
    function it_retrieve_an_empty_array_when_change_is_file_deletion()
    {
        $this->markTestIncomplete('Maybe this must be unitary');
        $file = 'src/Hooks.php';
        $gitFiles = $this->app->make(FileUtils::class);

        unlink($file);
        shell_exec("git add $file");

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);

        shell_exec("git checkout -- $file");
    }

    /**
     * @param string $path Absolute path. For example: /var/www/html/githooks/tests/NewFile.php
     *
     * @return string Only the relative path of the file to root project. For example: tests/NewFile.php
     */
    public function deletePathPrefix(string $path): string
    {
        $path = explode(SystemTestCase::TESTS_PATH . '/', $path);

        return SystemTestCase::TESTS_PATH . '/' . $path[1];
    }
}
