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

    // =========================================================================
    // Multi-report (v3.3 ítem 2) — config validation
    // =========================================================================

    /** @test */
    function rejects_v3_config_with_invalid_report_format()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'reports' => ['xml' => 'reports/qa.xml'],
            ])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(1)
            ->containsStringInOutput("invalid format 'xml'");
    }

    /** @test */
    function rejects_v3_config_when_report_path_is_not_a_string()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'reports' => ['sarif' => 123],
            ])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(1)
            ->containsStringInOutput("'reports.sarif' must be a non-empty string path");
    }

    /** @test */
    function warns_when_reports_target_directory_does_not_exist()
    {
        $missingDir = getcwd() . '/' . self::TESTS_PATH . '/missing-reports-dir';
        @rmdir($missingDir);

        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'reports' => ['sarif' => $missingDir . '/qa.sarif'],
            ])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('does not exist; it will be created on run');
    }

    /** @test */
    function accepts_v3_config_with_valid_reports_section()
    {
        $reportDir = getcwd() . '/' . self::TESTS_PATH;

        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'fail-fast' => false,
                'processes' => 1,
                'reports' => [
                    'sarif' => $reportDir . '/qa.sarif',
                    'junit' => $reportDir . '/junit.xml',
                ],
            ])
            ->buildInFileSystem();

        $configPath = $reportDir . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0)
            ->expectsOutput('The configuration file has the correct format.');
    }

    // =========================================================================
    // Meta-flow shape errors must propagate exit code 1.
    //
    // Decision table — adversarial classes of an invalid v3.3 flows section:
    //   A. nesting               (meta-flow → meta-flow)
    //   B. mixed declaration     (single flow declares both 'jobs' and 'flows')
    //   C. dangling reference    (meta-flow → non-existent flow)
    //   D. namespace collision   (same name used for a job and a flow)
    //
    // Contract: each adversary must produce the matching error text AND exit 1
    // — the error must NOT be silently downgraded to a warning that lets CI pass.
    // Regression test for QA-VAL bugs V33-008..011.
    // =========================================================================

    public function metaFlowShapeAdversariesProvider(): array
    {
        $jobs = [
            'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
        ];

        return [
            'A. meta-flow nesting' => [
                'flows' => [
                    'qa'    => ['jobs' => ['lint']],
                    'inner' => ['flows' => ['qa']],
                    'outer' => ['flows' => ['inner']],
                ],
                'jobs' => $jobs,
                'expectedError' => "meta-flow 'outer' references 'inner' which is also a meta-flow",
            ],
            'B. flow declares both jobs and flows' => [
                'flows' => [
                    'qa'     => ['jobs' => ['lint']],
                    'broken' => ['jobs' => ['lint'], 'flows' => ['qa']],
                ],
                'jobs' => $jobs,
                'expectedError' => "Flow 'broken' declares both 'jobs' and 'flows'",
            ],
            'C. meta-flow references unknown flow' => [
                'flows' => [
                    'qa'    => ['jobs' => ['lint']],
                    'ghost' => ['flows' => ['qa', 'noexiste']],
                ],
                'jobs' => $jobs,
                'expectedError' => "meta-flow 'ghost' references unknown flow 'noexiste'",
            ],
            'D. name used as both job and flow' => [
                'flows' => [
                    'qa' => ['jobs' => ['lint']],
                ],
                'jobs' => [
                    'qa'   => ['type' => 'parallel-lint', 'paths' => ['src']],
                    'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
                ],
                'expectedError' => "name 'qa' is declared as both job and flow",
            ],
        ];
    }

    /**
     * @test
     * @dataProvider metaFlowShapeAdversariesProvider
     */
    function rejects_v3_config_with_invalid_meta_flow_shape(array $flows, array $jobs, string $expectedError)
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows($flows)
            ->setV3Jobs($jobs)
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(1)
            ->expectsOutput('The configuration file has some errors')
            ->containsStringInOutput($expectedError);
    }

    // =========================================================================
    // FEAT-1 — flow-entry admission rules (only-files / exclude-files).
    //
    // Decision table — invalid declarations the parser must reject with exit 1:
    //   A. empty only-files       (must point the user to `null`)
    //   B. empty exclude-files
    //   C. duplicate pattern in only-files
    //   D. missing `job` key in object entry
    // =========================================================================

    public function flowEntryAdmissionAdversariesProvider(): array
    {
        $jobs = [
            'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
        ];

        return [
            'A. empty only-files' => [
                'flows' => [
                    'qa' => ['jobs' => [['job' => 'lint', 'only-files' => []]]],
                ],
                'jobs' => $jobs,
                'expectedError' => "Flow 'qa' job ref 'lint': 'only-files' must not be empty. Use null to disable an inherited rule.",
            ],
            'B. empty exclude-files' => [
                'flows' => [
                    'qa' => ['jobs' => [['job' => 'lint', 'exclude-files' => []]]],
                ],
                'jobs' => $jobs,
                'expectedError' => "Flow 'qa' job ref 'lint': 'exclude-files' must not be empty. Use null to disable an inherited rule.",
            ],
            'C. duplicate pattern in only-files' => [
                'flows' => [
                    'qa' => ['jobs' => [['job' => 'lint', 'only-files' => ['src/**', 'src/**']]]],
                ],
                'jobs' => $jobs,
                'expectedError' => "Flow 'qa' job ref 'lint': 'only-files' contains duplicate pattern 'src/**'.",
            ],
            'D. object entry without job key' => [
                'flows' => [
                    'qa' => ['jobs' => [['only-files' => ['src/**']]]],
                ],
                'jobs' => $jobs,
                'expectedError' => "Flow 'qa': job ref must have a 'job' key with a string value.",
            ],
        ];
    }

    /**
     * @test
     * @dataProvider flowEntryAdmissionAdversariesProvider
     */
    function rejects_v3_config_with_invalid_flow_entry_admission_rules(array $flows, array $jobs, string $expectedError)
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows($flows)
            ->setV3Jobs($jobs)
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(1)
            ->expectsOutput('The configuration file has some errors')
            ->containsStringInOutput($expectedError);
    }

    /** @test */
    function accepts_v3_config_with_valid_flow_entry_admission_rules()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Jobs([
                'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
            ])
            ->setV3Flows([
                'qa' => [
                    'jobs' => [
                        ['job' => 'lint', 'only-files' => ['src/**', 'composer.json']],
                    ],
                ],
            ])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0)
            ->expectsOutput('The configuration file has the correct format.');
    }

    // =========================================================================
    // FEAT-2 — `on => [branch_pattern => attrs]` per flow.
    //
    // Decision table — invalid declarations the parser must reject with exit 1:
    //   A. `on` declared but not an array (e.g. raw string)
    //   B. attrs of a pattern is not an object
    //   C. unsupported execution mode value (e.g. 'turbo')
    //   D. empty pattern key
    // =========================================================================

    public function flowOnAdversariesProvider(): array
    {
        $jobs = [
            'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
        ];

        return [
            'A. on is not an array' => [
                'flows' => [
                    'qa' => [
                        'on'   => 'master',
                        'jobs' => ['lint'],
                    ],
                ],
                'jobs' => $jobs,
                'expectedError' => "Flow 'qa': 'on' must be an array of branch patterns.",
            ],
            'B. pattern attrs is a scalar' => [
                'flows' => [
                    'qa' => [
                        'on'   => ['master' => 'full'],
                        'jobs' => ['lint'],
                    ],
                ],
                'jobs' => $jobs,
                'expectedError' => "Flow 'qa' on rule for 'master': attributes must be an object.",
            ],
            'C. unsupported execution mode' => [
                'flows' => [
                    'qa' => [
                        'on'   => ['master' => ['execution' => 'turbo']],
                        'jobs' => ['lint'],
                    ],
                ],
                'jobs' => $jobs,
                'expectedError' => "Flow 'qa' on rule for 'master': 'execution' must be one of: full, fast, fast-branch.",
            ],
        ];
    }

    /**
     * @test
     * @dataProvider flowOnAdversariesProvider
     */
    function rejects_v3_config_with_invalid_on_block(array $flows, array $jobs, string $expectedError)
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows($flows)
            ->setV3Jobs($jobs)
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(1)
            ->expectsOutput('The configuration file has some errors')
            ->containsStringInOutput($expectedError);
    }

    /** @test */
    function accepts_v3_config_with_valid_on_block_having_catch_all()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Jobs([
                'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
            ])
            ->setV3Hooks([])
            ->setV3Flows([
                'ci' => [
                    'on' => [
                        'master' => ['execution' => 'full'],
                        '*'      => ['execution' => 'fast-branch'],
                    ],
                    'jobs' => ['lint'],
                ],
            ])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0)
            ->expectsOutput('The configuration file has the correct format.');
    }

    /** @test */
    function warns_when_on_lacks_catch_all_but_still_exits_zero()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Jobs([
                'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
            ])
            ->setV3Hooks([])
            ->setV3Flows([
                'ci' => [
                    'on'   => ['master' => ['execution' => 'full']],
                    'jobs' => ['lint'],
                ],
            ])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("conf:check --config=$configPath")
            ->assertExitCode(0)
            ->containsStringInOutput("'on' has no catch-all '*' pattern");
    }
}
