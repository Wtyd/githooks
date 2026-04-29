<?php

namespace Tests\Integration;

use Wtyd\GitHooks\Utils\FileUtils;
use Wtyd\GitHooks\Utils\FileUtilsInterface;
use Tests\Utils\PhpFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;
use Tests\Utils\Traits\GitSandboxTrait;

/**
 * Tests FileUtils against a sandboxed git repo created in /tmp. The
 * project's real working tree is never touched — see GitSandboxTrait
 * for the isolation model.
 *
 * @group git
 */
class FileUtilsTest extends SystemTestCase
{
    use GitSandboxTrait;

    /** @var string Absolute path inside the sandbox. */
    protected $gitFilesPathTest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpGitSandbox();

        // Path layout intentionally keeps a `testsDir/gitTests` segment so the
        // existing deletePathPrefix() helper keeps producing the same relative
        // paths used in the assertions.
        $this->gitFilesPathTest = $this->sandboxDir
            . DIRECTORY_SEPARATOR
            . SystemTestCase::TESTS_PATH
            . DIRECTORY_SEPARATOR
            . 'gitTests';
        mkdir($this->gitFilesPathTest, 0755, true);

        $this->app->bind(FileUtilsInterface::class, FileUtils::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownGitSandbox();

        parent::tearDown();
    }

    /** @test */
    public function it_retrieve_modified_files()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        $filename = $this->gitFilesPathTest . '/NewFile.php';
        file_put_contents($filename, $fileBuilder->build());

        shell_exec('git add -f ' . escapeshellarg($filename));

        $gitFiles = $this->app->make(FileUtils::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([$this->deletePathPrefix($filename)], $modifiedFiles);
    }

    /** @test */
    public function it_retrieve_an_empty_array_when_there_are_no_modified_files()
    {
        $gitFiles = $this->app->make(FileUtils::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);
    }

    /** @test */
    public function it_retrieve_an_empty_array_when_the_modified_files_there_are_no_added_to_the_git_stage()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        file_put_contents($this->gitFilesPathTest . '/NewFile.php', $fileBuilder->build());

        $gitFiles = $this->app->make(FileUtils::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);
    }

    /** @test */
    public function it_excludes_deleted_file_from_modified_files()
    {
        $fileBuilder = new PhpFileBuilder('ToDelete');
        $filename = $this->gitFilesPathTest . '/ToDelete.php';
        file_put_contents($filename, $fileBuilder->build());

        shell_exec('git add -f ' . escapeshellarg($filename));
        shell_exec('git commit --quiet -m "temp: add file for deletion test"');

        shell_exec('git rm ' . escapeshellarg($filename) . ' 2>/dev/null');

        $gitFiles = $this->app->make(FileUtils::class);
        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertNotContains($this->deletePathPrefix($filename), $modifiedFiles);
    }

    /** @test */
    public function it_includes_renamed_file_in_modified_files()
    {
        $fileBuilder = new PhpFileBuilder('Original');
        $originalPath = $this->gitFilesPathTest . '/Original.php';
        $renamedPath = $this->gitFilesPathTest . '/Renamed.php';
        file_put_contents($originalPath, $fileBuilder->build());

        shell_exec('git add -f ' . escapeshellarg($originalPath));
        shell_exec('git commit --quiet -m "temp: add file for rename test"');

        shell_exec('git mv -f ' . escapeshellarg($originalPath) . ' ' . escapeshellarg($renamedPath));

        $gitFiles = $this->app->make(FileUtils::class);
        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertContains($this->deletePathPrefix($renamedPath), $modifiedFiles);
    }

    /**
     * @param string $path Absolute path inside the sandbox. For example: /tmp/githooks-sandbox-XXXX/testsDir/gitTests/NewFile.php
     *
     * @return string Path relative to repo root. For example: testsDir/gitTests/NewFile.php
     */
    public function deletePathPrefix(string $path): string
    {
        $path = explode(SystemTestCase::TESTS_PATH . '/', $path);

        return SystemTestCase::TESTS_PATH . '/' . $path[1];
    }
}
