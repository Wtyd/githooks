<?php

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;

class CheckConfigurationFileCommandTest extends SystemTestCase
{
    /** @test */
    function it_passes_all_file_configuration_checks()
    {
        $this->configurationFileBuilder->buildInFileSystem();

        $this->artisan('conf:check')
            ->assertExitCode(0)
            ->expectsTable(['Options', 'Values'], [['execution', 'full'], ['processes', 1]])
            // It seems that the expectsTable method only allows checking the first table that is printed.
            // ->expectsTable(['Tools', 'Commands'], [
            //     ['phpstan', 'vendor/bin/phpstan analyse -c ./qa/phpstan.neon --no-progress --ansi src'],
            //     ['parallel-lint', 'vendor/bin/parallel-lint --exclude vendor --exclude qa --exclude tools --colors ./'],
            //     ['phpmd', 'tools/php71/phpmd ./src/ ansi ./qa/phpmd-ruleset.xml --exclude "vendor"'],
            //     ['phpcpd', 'tools/php71/phpcpd --exclude vendor --exclude tests --exclude tools ./'],
            //     ['phpcbf', 'tools/php71/phpcbf ./ --standard=./qa/psr12-ruleset.xml --ignore=vendor,tools --error-severity=1 --warning-severity=6 --report=summary --parallel=2'],
            //     ['phpcs', 'tools/php71/phpcs ./ --standard=./qa/psr12-ruleset.xml --ignore=vendor,tools --error-severity=1 --warning-severity=6 --report=summary --parallel=2'],
            // ])
            ->expectsOutput('The configuration file has the correct format.');
    }

    public function optionsDataProvider()
    {
        return [
            'Optins is empty' => [
                'Options' => [],
                'Table Values' => [['execution', 'full (default)'], ['processes', '1 (default)']]
            ],
            'Only execution is setted' => [
                'Options' => ['execution' => 'fast'],
                'Table Values' => [['execution', 'fast'], ['processes', '1 (default)']]
            ],
            'Only processes is setted' => [
                'Options' => ['processes' => 3],
                'Table Values' => [['execution', 'full (default)'], ['processes', '3']]
            ],
        ];
    }

    /**
     * @test
     * @dataProvider optionsDataProvider
     */
    function it_shows_default_options_when_not_are_setted($options, $tableValues)
    {
        $this->configurationFileBuilder->setOptions($options);

        $this->configurationFileBuilder->buildInFileSystem();

        $this->artisan('conf:check')
            ->assertExitCode(0)
            ->expectsTable(['Options', 'Values'], $tableValues)
            // ->expectsTable(['Tools', 'Commands'], [
            //     ['phpcs', 'tools/php71/phpcs ./ --standard=./qa/psr12-ruleset.xml --ignore=vendor,tools --error-severity=1 --warning-severity=6 --report=summary --parallel=2'],
            //     ['phpstan', 'vendor/bin/phpstan analyse -c ./qa/phpstan.neon --no-progress --ansi src'],
            ->expectsOutput('The configuration file has the correct format.');
    }

    /** @test */
    function it_passes_all_file_configuration_checks_with_some_warnings()
    {
        $this->configurationFileBuilder
            ->setOptions([])
            ->setTools(['phpcs', 'phpstan', 'invent']);

        $this->configurationFileBuilder->buildInFileSystem();

        $this->artisan('conf:check')
            ->assertExitCode(0)
            ->expectsTable(['Options', 'Values'], [['execution', 'full (default)'], ['processes', '1 (default)']])
            // ->expectsTable(['Tools', 'Commands'], [
            //     ['phpcs', 'tools/php71/phpcs ./ --standard=./qa/psr12-ruleset.xml --ignore=vendor,tools --error-severity=1 --warning-severity=6 --report=summary --parallel=2'],
            //     ['phpstan', 'vendor/bin/phpstan analyse -c ./qa/phpstan.neon --no-progress --ansi src'],
            ->containsStringInOutput("The tag 'Options' is empty")
            ->containsStringInOutput('The tool invent is not supported by GitHooks.');
    }

    /** @test */
    function it_fails_the_file_configuration_checks_and_prints_errors_and_warnings()
    {
        $this->configurationFileBuilder->setTools([])->setOptions(['invent option' => 1]);

        $this->configurationFileBuilder->buildInFileSystem();

        $this->artisan('conf:check')
            ->assertExitCode(1)
            ->expectsOutput('The configuration file has some errors')
            ->containsStringInOutput("The 'Tools' tag from configuration file is empty") //error
            ->containsStringInOutput("The key 'invent option' is not a valid option"); //warning
    }

    /** @test */
    function it_not_founds_configuration_file()
    {
        $this->artisan('conf:check')
            ->assertExitCode(1)
            ->containsStringInOutput("Configuration file must be 'githooks.yml' in root directory or in qa/ directory");
    }

    /** @test */
    function it_check_the_config_file_pass_as_argument()
    {
        // valid file in custom path
        $configFilePath = 'custom/path';
        $this->configurationFileBuilder->buildInFileSystem($configFilePath);

        // file with erros in root path
        $this->configurationFileBuilder
            ->setTools([])
            ->setOptions(['invent option' => 1])
            ->buildInFileSystem();

        $this->artisan("conf:check --config custom/path/githooks.yml")
            ->assertExitCode(0)
            ->expectsTable(['Options', 'Values'], [['execution', 'full'], ['processes', 1]])
            ->expectsOutput('The configuration file has the correct format.')
            ->notContainsStringInOutput("The 'Tools' tag from configuration file is empty")
            ->notcontainsStringInOutput("The key 'invent option' is not a valid option");
    }
}
