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
        $this->configurationFileBuilder->buildInFileSystem('./', true);
        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());
    }

    /** @test */
    function it_checks_the_configuration_file_and_show_warning_and_return_exit_0()
    {
        $this->configurationFileBuilder->setOptions(['invalidOptionTest' => 1])
                                        ->buildInFileSystem('./', true);
        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());
        $this->assertStringContainsString("The key 'invalidOptionTest' is not a valid option", $this->getActualOutput());
    }

    /** @test */
    function it_checks_the_configuration_file_and_show_error_and_return_exit_1()
    {
        $this->configurationFileBuilder->setTools([])->buildInFileSystem('./', true);

        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString("The 'Tools' tag from configuration file is empty.", $this->getActualOutput());
    }

    /** @test */
    function it_checks_configuration_file_in_custom_path_with_config_flag()
    {
        $this->configurationFileBuilder->setTools(['invalid-tool'])
            ->buildInFileSystem('./', true);

        // Create valid config in custom folder
        $this->createDirStructure('custom');

        $this->configurationFileBuilder
            ->setTools(['phpunit', 'phpcs'])
                ->buildInFileSystem('custom', true);

        // Check root config with errors
        passthru("$this->githooks conf:check", $exitCode);
        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString("There must be at least one tool configured.", $this->getActualOutput());

        // Check valid config in custom folder
        passthru("$this->githooks conf:check --config=custom/githooks.php", $exitCode);

        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());

        $this->deleteDirStructure('custom/');
    }

    /** @test */
    function it_shows_configuration_file_path()
    {
        $this->configurationFileBuilder->buildInFileSystem('./', true);
        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Configuration file:', $this->getActualOutput());
    }

    /** @test */
    function it_checks_configuration_file_using_c_shortcut()
    {
        $this->configurationFileBuilder->setTools(['invalid-tool'])
            ->buildInFileSystem('./', true);

        // Create valid config in custom folder
        $this->createDirStructure('custom');

        $this->configurationFileBuilder
            ->setTools(['phpunit', 'phpcs'])
                ->buildInFileSystem('custom', true);

        // -c shortcut should work identically to --config
        passthru("$this->githooks conf:check -c custom/githooks.php", $exitCode);

        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());

        $this->deleteDirStructure('custom/');
    }
}
