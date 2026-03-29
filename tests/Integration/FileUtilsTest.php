<?php

namespace Tests\Integration;

use Wtyd\GitHooks\Utils\FileUtils;
use Wtyd\GitHooks\Utils\FileUtilsInterface;
use Tests\Utils\PhpFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;

/**
 * Before executing this test suite after any changes, you must commit these changes
 * @group git
 */
class FileUtilsTest extends SystemTestCase
{
    protected static $gitFilesPathTest = __DIR__ . '/../../' . SystemTestCase::TESTS_PATH . '/gitTests';

    /** @var string */
    protected $headBeforeTest;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure clean git state before each test
        shell_exec('git reset --hard HEAD 2>/dev/null');

        // Git identity needed for commits in CI runners
        shell_exec('git config user.email "test@test.com" 2>/dev/null');
        shell_exec('git config user.name "Test" 2>/dev/null');

        mkdir(self::$gitFilesPathTest);

        $this->app->bind(FileUtilsInterface::class, FileUtils::class);

        $this->headBeforeTest = trim(shell_exec('git rev-parse HEAD'));
    }

    protected function tearDown(): void
    {
        $currentHead = trim(shell_exec('git rev-parse HEAD'));
        if ($currentHead !== $this->headBeforeTest) {
            shell_exec('git reset --hard ' . $this->headBeforeTest);
        } else {
            shell_exec('git reset --hard HEAD 2>/dev/null');
        }

        parent::tearDown();
    }

    /** @test */
    function it_retrieve_modified_files()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        $filename = self::$gitFilesPathTest . '/NewFile.php';
        file_put_contents($filename, $fileBuilder->build());

        shell_exec('git add -f ' . $filename);

        $gitFiles = $this->app->make(FileUtils::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([$this->deletePathPrefix($filename)], $modifiedFiles);
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
    function it_includes_deleted_file_in_modified_files()
    {
        $fileBuilder = new PhpFileBuilder('ToDelete');
        $filename = self::$gitFilesPathTest . '/ToDelete.php';
        file_put_contents($filename, $fileBuilder->build());

        shell_exec('git add -f ' . $filename);
        shell_exec('git commit -m "temp: add file for deletion test"');

        shell_exec('git rm ' . $filename);

        $gitFiles = $this->app->make(FileUtils::class);
        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertContains($this->deletePathPrefix($filename), $modifiedFiles);
    }

    /** @test */
    function it_includes_renamed_file_in_modified_files()
    {
        $fileBuilder = new PhpFileBuilder('Original');
        $originalPath = self::$gitFilesPathTest . '/Original.php';
        $renamedPath = self::$gitFilesPathTest . '/Renamed.php';
        file_put_contents($originalPath, $fileBuilder->build());

        shell_exec('git add -f ' . $originalPath);
        shell_exec('git commit -m "temp: add file for rename test"');

        shell_exec('git mv -f ' . $originalPath . ' ' . $renamedPath);

        $gitFiles = $this->app->make(FileUtils::class);
        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertContains($this->deletePathPrefix($renamedPath), $modifiedFiles);
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
