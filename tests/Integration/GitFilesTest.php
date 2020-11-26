<?php

namespace Tests\Integration;

use GitHooks\Utils\GitFiles;
use Tests\System\Utils\PhpFileBuilder;
use Tests\SystemTestCase;

/**
 * Before executing this test suite after any changes, you must commit these changes
 * @group git
 */
class GitFilesTest extends SystemTestCase
{
    protected function setUp(): void
    {
        $this->deleteDirStructure();

        $this->hiddenConsoleOutput();

        $this->createDirStructure();
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
        shell_exec('git add .');
    }

    /** @test */
    function it_retrieve_modified_files()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        $filename = $this->getPath() . '/src/NewFile.php';
        file_put_contents($filename, $fileBuilder->build());
        shell_exec('git add .');

        $gitFiles = $this->container->make(GitFiles::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([$this->deletePathPrefix($filename)], $modifiedFiles);
    }

    /** @test */
    function it_retrieve_an_empty_array_when_there_are_no_modified_files()
    {
        $gitFiles = $this->container->make(GitFiles::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);
    }

    /** @test */
    function it_retrieve_an_empty_array_when_the_modified_files_there_are_no_added_to_the_git_stage()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        file_put_contents($this->getPath() . '/src/NewFile.php', $fileBuilder->build());

        $gitFiles = $this->container->make(GitFiles::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);
    }

    /**
     * @param string $path Absolute path. For example: /var/www/html/githooks/tests/src/NewFile.php
     *
     * @return string Only the relative path of the file to root project. For example: tests/src/NewFile.php
     */
    public function deletePathPrefix(string $path): string
    {
        $path = explode('tests/', $path);

        return 'tests/' . $path[1];
    }
}
