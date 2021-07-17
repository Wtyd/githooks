<?php

namespace Tests\System\Commands;

use Tests\ConsoleTestCase;

class CheckConfigurationFileCommandTest extends ConsoleTestCase
{
    /** @test */
    function it_pass_all_file_configuration_checks()
    {
        $this->markTestSkipped('CheckConfiguration');
        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        try {
            $this->artisan('conf:check')
                ->containsStringInOutput("Checking the configuration file:\n")
                ->containsStringInOutput('The file githooks.yml has the correct format.');
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    /** @test */
    function it_not_pass_file_configuration_checks()
    {
        $this->markTestSkipped('CheckConfiguration');
        $this->configurationFileBuilder->setOptions(['execution' => 'invalid value']);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        $this->artisan('conf:check')
            ->containsStringInOutput("Checking the configuration file:\n")
            ->containsStringInOutput("The file contains the following errors:")
            ->containsStringInOutput("- The value 'invalid value' is not allowed for the tag 'execution'. Accept: full, smart, fast")
            ->notContainsStringInOutput('The file githooks.yml has the correct format.');
    }

    /** @test */
    function it_pass_all_checks_with_warnings()
    {
        $this->markTestSkipped('CheckConfiguration');
        $this->configurationFileBuilder->setPhpCSConfiguration([
            'execution' => 'invalid value'
        ]);
        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());


        $this->artisan('conf:check')
            ->containsStringInOutput("Checking the configuration file:\n")
            ->containsStringInOutput('The file githooks.yml has the correct format.')
            ->containsStringInOutput('execution argument is invalid for tool phpcs');
    }
}
