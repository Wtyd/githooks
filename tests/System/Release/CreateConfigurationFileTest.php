<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class CreateConfigurationFileTest extends ReleaseTestCase
{
    /** @test */
    function it_creates_the_configuration_file_and_returns_exit_code_0()
    {
        $this->deleteDirStructure('vendor/wtyd');

        // mkdir('vendor/wtyd/githooks/qa/', 0777, true);
        // file_put_contents(
        //     'vendor/wtyd/githooks/qa/githooks.dist.php',
        //     $this->configurationFileBuilder->buildYaml()
        // );
        $this->configurationFileBuilder->buildInFileSystem('vendor/wtyd/githooks/qa/');

        passthru("$this->githooks conf:init", $exitCode);

        $this->assertStringContainsString('Configuration file githooks.php has been created in root path', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileExists('githooks.yml');

        $this->deleteDirStructure('vendor/wtyd');
    }

    // TODO: Add test to check if the file already exists
}
