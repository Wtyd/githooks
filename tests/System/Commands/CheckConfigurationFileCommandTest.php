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
            ->containsStringInOutput('Configuration file: githooks.php')
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
            ->containsStringInOutput('Configuration file: githooks.php')
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
            ->containsStringInOutput('Configuration file: githooks.php')
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
            ->containsStringInOutput('Configuration file: githooks.php')
            ->expectsOutput('The configuration file has some errors')
            ->containsStringInOutput("The 'Tools' tag from configuration file is empty") //error
            ->containsStringInOutput("The key 'invent option' is not a valid option"); //warning
    }

    /** @test */
    function it_not_founds_configuration_file()
    {
        $this->artisan('conf:check')
            ->assertExitCode(1)
            ->notContainsStringInOutput('Configuration file:')
            ->containsStringInOutput("Configuration file must be 'githooks.yml' in root directory or in qa/ directory");
    }

    /** @test */
    function it_shows_correct_format_for_valid_v3_config()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0)
            ->expectsOutput('The configuration file has the correct format.');
    }

    /** @test */
    function it_shows_validation_warnings_for_v3_config_with_bad_path()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Jobs([
                'phpstan_src' => [
                    'type' => 'phpstan',
                    'level' => 0,
                    'paths' => ['nonexistent_directory'],
                ],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0)
            ->expectsOutput('The configuration format is correct, but some jobs have validation warnings (see Status column).');
    }

    /** @test */
    function it_handles_v3_config_with_long_commands_without_error()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Jobs([
                'phpcpd_all' => [
                    'type' => 'phpcpd',
                    'paths' => ['./'],
                    'exclude' => [
                        'vendor', 'tools', 'config', 'qa', 'bootstrap',
                        'database', 'storage', 'tests', 'resources',
                        'public', 'node_modules', 'docker',
                    ],
                ],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['phpcpd_all']]])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0);
    }

    /** @test */
    function it_check_the_config_file_pass_as_argument()
    {
        $a = 10;
        // valid file in custom path
        $configFilePath = 'custom/path';
        $this->configurationFileBuilder->buildInFileSystem($configFilePath);

        // file with errors in root path
        $this->configurationFileBuilder
            ->setTools([])
            ->setOptions(['invent option' => 1])
            ->buildInFileSystem();

        $this->artisan("conf:check --config custom/path/githooks.php")
            ->assertExitCode(0)
            ->containsStringInOutput('Configuration file: custom/path/githooks.php')
            ->expectsTable(['Options', 'Values'], [['execution', 'full'], ['processes', 1]])
            ->expectsOutput('The configuration file has the correct format.')
            ->notContainsStringInOutput("The 'Tools' tag from configuration file is empty")
            ->notcontainsStringInOutput("The key 'invent option' is not a valid option");
    }

    /** @test */
    function shows_errors_summary_when_v3_config_has_invalid_option_type()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'fail-fast' => false,
                'processes' => 1,
                'executable-prefix' => 123,
            ])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(1)
            ->containsStringInOutput("'executable-prefix' must be a string");
    }

    /** @test */
    function displays_hook_conditions_in_hooks_table()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([
                'pre-commit' => [
                    [
                        'flow' => 'qa',
                        'only-on' => ['main'],
                        'exclude-on' => ['wip'],
                        'only-files' => ['src/'],
                        'exclude-files' => ['tests/'],
                    ],
                ],
            ])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0);
    }

    /** @test */
    function shows_executable_prefix_row_when_options_include_it()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'fail-fast' => false,
                'processes' => 1,
                'executable-prefix' => 'php7.4',
            ])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0)
            ->expectsTable(
                ['Option', 'Value'],
                [
                    ['processes', '1'],
                    ['fail-fast', 'false'],
                    ['executable-prefix', 'php7.4'],
                ]
            );
    }

    /** @test */
    function check_does_not_crash_when_phpmd_rules_file_path_is_missing()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Jobs([
                'phpmd_src' => [
                    'type' => 'phpmd',
                    'paths' => ['src'],
                    'rules' => 'qa/nonexistent-ruleset.xml',
                ],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['phpmd_src']]])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0);
    }
}
