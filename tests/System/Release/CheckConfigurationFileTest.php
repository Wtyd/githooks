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
    function it_checks_v3_configuration_with_correct_format()
    {
        $this->configurationFileBuilder->enableV3Mode();

        file_put_contents(
            self::TESTS_PATH . '/githooks.php',
            $this->configurationFileBuilder->buildV3Php()
        );

        $configPath = self::TESTS_PATH . '/githooks.php';
        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('correct format', $this->getActualOutput());
    }

    /** @test */
    function it_shows_validation_warnings_for_v3_config_with_bad_path()
    {
        $this->configurationFileBuilder->enableV3Mode()
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'phpstan', 'level' => 0, 'paths' => ['nonexistent_dir']],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]]);

        $configPath = self::TESTS_PATH . '/githooks.php';
        file_put_contents($configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('not found', $this->getActualOutput());
        $this->assertStringContainsString('validation warnings', $this->getActualOutput());
    }

    /** @test */
    function it_shows_conditional_execution_in_hooks_table()
    {
        $this->configurationFileBuilder->enableV3Mode()
            ->setV3Hooks([
                'pre-commit' => [
                    ['flow' => 'qa', 'only-on' => ['main', 'develop']],
                ],
            ]);

        $configPath = self::TESTS_PATH . '/githooks.php';
        file_put_contents($configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('on: main', $this->getActualOutput());
    }
}
