<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class CheckConfigurationFileTest extends ReleaseTestCase
{
    /** @test */
    function it_checks_the_configuration_file_and_return_exit_0()
    {
        $this->configurationFileBuilder->buildInFileSystem();
        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());
    }

    /** @test */
    function it_checks_the_configuration_file_and_show_warning_and_return_exit_0()
    {
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setOptions(['invalidOptionTest' => 1])->buildPhp()
        );
        // $this->configurationFileBuilder->setOptions(['invalidOptionTest' => 1])->buildInFileSystem();
        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());
        $this->assertStringContainsString("The key 'invalidOptionTest' is not a valid option", $this->getActualOutput());
    }

    /** @test */
    function it_checks_the_configuration_file_and_show_error_and_return_exit_1()
    {
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools([])->buildPhp()
        );

        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString("The 'Tools' tag from configuration file is empty.", $this->getActualOutput());
    }

    /** @test */
    function it_handles_multiple_config_files_with_errors_and_valid()
    {
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder->setTools(['invalid-tool'])->buildPhp()
        );
        // $this->configurationFileBuilder
        //         ->setTools(['invalid-tool'])
        //         ->buildInFileSystem();

        // Create valid config in custom folder
        mkdir('custom', 0777, true);
        file_put_contents(
            'custom/githooks.php',
            $this->configurationFileBuilder
                ->setTools(['phpunit', 'phpcs'])
                ->buildPhp()
        );
        // $this->configurationFileBuilder
        //     ->setTools(['phpunit', 'phpcs'])
        //         ->buildInFileSystem('custom');

        // Check root config with errors
        passthru("$this->githooks conf:check", $exitCode);
        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString("There must be at least one tool configured.", $this->getActualOutput());

        // Check valid config in custom folder
        passthru("$this->githooks conf:check --config=custom/githooks.php", $exitCode);
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());

        $this->deleteDirStructure('custom');
    }
}
