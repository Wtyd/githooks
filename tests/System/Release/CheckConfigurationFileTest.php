<?php

namespace Tests\System\Release;

use Tests\ConsoleTestCase;
use Tests\ReleaseTestCase;

/**
 * @group release
 */
class CheckConfigurationFileTest extends ReleaseTestCase
{
    protected $githooks = ConsoleTestCase::TESTS_PATH . '/githooks';

    /** @test */
    function it_checks_the_configuration_file_and_return_exit_0()
    {
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder->buildYalm()
        );

        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The file githooks.yml has the correct format.', $this->getActualOutput());
    }

    /** @test */
    function it_checks_the_configuration_file_and_show_warning_and_return_exit_0()
    {
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder->setOptions(['invalidOptionTest' => 1])->buildYalm()
        );

        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The file githooks.yml has the correct format.', $this->getActualOutput());
        $this->assertStringContainsString("The key 'invalidOptionTest' is not a valid option", $this->getActualOutput());
    }

    /** @test */
    function it_checks_the_configuration_file_and_show_error_and_return_exit_1()
    {
        file_put_contents(
            'githooks.yml',
            $this->configurationFileBuilder->setTools([])->buildYalm()
        );

        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString("The 'Tools' tag from configuration file is empty.", $this->getActualOutput());
    }
}
