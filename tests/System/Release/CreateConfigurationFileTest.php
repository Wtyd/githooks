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
        @unlink('alfil.php');
        parent::tearDown();
    }

    /** @test */
    function it_creates_the_configuration_file_and_returns_exit_code_0()
    {
        $this->deleteDirStructure('./vendor/wtyd');
        @unlink('githooks.php');
        @unlink('alfil.php');
        @unlink('githooks.yml');
        @unlink('qa/githooks.php');
        @unlink('qa/githooks.yml');

        $this->configurationFileBuilder
                ->setName('alfil.dist.php')
                ->buildInFileSystem('./vendor/wtyd/alfil/qa/', true);

        passthru("$this->alfil conf:init -n", $exitCode);
        $this->assertStringContainsString('Configuration file alfil.php has been created in root path', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileExists('alfil.php');

        $this->deleteDirStructure('vendor/wtyd');
    }

    /** @test */
    function it_creates_config_interactively_when_tools_detected()
    {
        @unlink('githooks.php');
        @unlink('alfil.php');
        @unlink('githooks.yml');
        @unlink('qa/githooks.php');
        @unlink('qa/githooks.yml');

        // stdin closed: `conf:init` prompts when it has a terminal to prompt
        // on, and a local run of the suite would sit there for ever. CI never
        // has one, which is why this only bites in local runs.
        passthru("$this->alfil conf:init < /dev/null", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('created with', $this->getActualOutput());
        $this->assertFileExists('alfil.php');
    }

    /** @test */
    function it_not_creates_configuration_file_when_already_exists()
    {
        $this->deleteDirStructure('./vendor/wtyd');

        $this->configurationFileBuilder
                ->setName('githooks.php')
                ->buildInFileSystem('./', true);

        $this->configurationFileBuilder
                ->setName('alfil.dist.php')
                ->buildInFileSystem('./vendor/wtyd/alfil/qa/', true);

        // stdin closed: `conf:init` prompts when it has a terminal to prompt
        // on, and a local run of the suite would sit there for ever. CI never
        // has one, which is why this only bites in local runs.
        passthru("$this->alfil conf:init < /dev/null", $exitCode);
        $this->assertStringContainsString('Alfil configuration file already exists', $this->getActualOutput());
        $this->assertEquals(1, $exitCode);
        $this->assertFileExists('alfil.php');

        $this->deleteDirStructure('vendor/wtyd');
    }
}
