<?php

namespace Tests\System\Commands;

use Tests\ConsoleTestCase;

class CheckConfigurationFileCommandTest extends ConsoleTestCase
{
    /** @test */
    function it_pass_all_file_configuration_checks()
    {
        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        $this->artisan('conf:check')
            ->assertExitCode(0)
            ->expectsOutput('The file githooks.yml has the correct format.');
    }

    /** @test */
    function it_pass_all_file_configuration_checks_with_some_warnings()
    {
        $this->configurationFileBuilder
            ->setOptions([])
            ->setTools(['phpcs', 'phpstan', 'invent']);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        $this->artisan('conf:check')
            ->assertExitCode(0)
            ->containsStringInOutput("The tag 'Options' is empty")
            ->containsStringInOutput('The tool invent is not supported by GitHooks.');
    }

    /** @test */
    function it_fails_the_file_configuration_checks_and_print_errors_and_warnings()
    {
        $this->configurationFileBuilder->setTools([])->setOptions(['invent option' => 1]);
        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        $this->artisan('conf:check')
            ->assertExitCode(1)
            ->expectsOutput('The configuration file has some errors')
            ->containsStringInOutput("The 'Tools' tag from configuration file is empty") //error
            ->containsStringInOutput("The key 'invent option' is not a valid option"); //warning
    }
}
