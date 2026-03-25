<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class CreateConfigurationFileTest extends ReleaseTestCase
{
    public function tearDown(): void
    {
        shell_exec("git checkout -- 'qa/*'");
        @unlink('githooks.php');
        parent::tearDown();
    }
    /** @test */
    function it_creates_the_configuration_file_and_returns_exit_code_0()
    {
        $this->deleteDirStructure('./vendor/wtyd');
        // Delete all posible configuration files
        @unlink('githooks.php');
        @unlink('githooks.yml');
        @unlink('qa/githooks.php');
        @unlink('qa/githooks.yml');

        $this->configurationFileBuilder
                ->setName('githooks.dist.php')
                ->buildInFileSystem('./vendor/wtyd/githooks/qa/', true);

        passthru("$this->githooks conf:init", $exitCode);
        $this->assertStringContainsString('Configuration file githooks.php has been created in root path', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileExists('githooks.php');

        $this->deleteDirStructure('vendor/wtyd');
    }

    /** @test */
    function it_not_creates_configuration_file_when_already_exists()
    {
        $this->deleteDirStructure('./vendor/wtyd');

        $this->configurationFileBuilder
                ->setName('githooks.php')
                ->setTools(['phpcs'])
                ->buildInFileSystem('./', true);
        $alreadyExistFile =  $this->configurationFileBuilder->buildPhp();

        $this->configurationFileBuilder
                ->setName('githooks.dist.php')
                ->setTools(['parallel-lint'])
                ->buildInFileSystem('./vendor/wtyd/githooks/qa/', true);

        passthru("$this->githooks conf:init", $exitCode);
        $this->assertStringContainsString('githooks configuration file already exists', $this->getActualOutput());
        $this->assertEquals(1, $exitCode);
        $this->assertFileExists('githooks.php');
        $this->assertFileNotEquals(
            'githooks.php',
            './vendor/wtyd/githooks/qa/githooks.dist.php',
            'The file may have been overwritten'
        );

        $this->deleteDirStructure('vendor/wtyd');
    }
}
