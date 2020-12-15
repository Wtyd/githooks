<?php

namespace Tests\System\Commands;

use Tests\Artisan\ConsoleTestCase;
use Tests\System\Utils\ConfigurationFileBuilder;

class CheckConfigurationFileCommandTest extends ConsoleTestCase
{
    protected $configurationFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteDirStructure();

        $this->createDirStructure();

        $this->configurationFile = new ConfigurationFileBuilder($this->getPath());

        $this->mockConfigurationFileForCommandsTests();
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    /** @test */
    function it_pass_all_file_configuration_checks()
    {
        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        $this->artisan('conf:check')
            ->containsStringInOutput("Checking the configuration file:\n")
            ->containsStringInOutput('The file githooks.yml has the correct format.');
    }

    /** @test */
    function it_not_pass_file_configuration_checks()
    {
        $this->configurationFile->setOptions(['execution' => 'invalid value']);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        $this->artisan('conf:check')
            ->containsStringInOutput("Checking the configuration file:\n")
            ->containsStringInOutput("The file contains the following errors:")
            ->containsStringInOutput("- The value 'invalid value' is not allowed for the tag 'execution'. Accept: full, smart, fast")
            ->notContainsStringInOutput('The file githooks.yml has the correct format.');
    }

    /** @test */
    function it_pass_all_checks_with_warnings()
    {
        $this->configurationFile->setPhpCSConfiguration([
            'execution' => 'invalid value'
        ]);
        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());


        $this->artisan('conf:check')
            ->containsStringInOutput("Checking the configuration file:\n")
            ->containsStringInOutput('The file githooks.yml has the correct format.')
            ->containsStringInOutput('execution argument is invalid for tool phpcs');
    }
}
