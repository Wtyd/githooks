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
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder->buildYaml()
        );

        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());
    }

    /** @test */
    function it_checks_the_configuration_file_and_show_warning_and_return_exit_0()
    {
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder->setOptions(['invalidOptionTest' => 1])->buildYaml()
        );

        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());
        $this->assertStringContainsString("The key 'invalidOptionTest' is not a valid option", $this->getActualOutput());
    }

    /** @test */
    function it_checks_the_configuration_file_and_show_error_and_return_exit_1()
    {
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder->setTools([])->buildYaml()
        );

        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString("The 'Tools' tag from configuration file is empty.", $this->getActualOutput());
    }

    /** @test */
    function it_handles_multiple_config_files_with_errors_and_valid()
    {
        // Create root config with errors
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder
                ->setTools(['invalid-tool'])
                ->buildYaml()
        );

        // Create valid config in custom folder
        mkdir('custom');
        file_put_contents(
            'custom/githooks.yml',
            $this->configurationFileBuilder
                ->setTools(['phpunit', 'phpcs'])
                ->buildYaml()
        );

        // Check root config with errors
        passthru("$this->githooks conf:check", $exitCode);
        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Configuration file contains errors', $this->getActualOutput());

        // Check valid config in custom folder
        passthru("$this->githooks conf:check --config=custom/githooks.yml", $exitCode);
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());

        rmdir('custom');
    }
}
