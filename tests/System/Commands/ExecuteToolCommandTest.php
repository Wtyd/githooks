<?php

namespace Tests\System\Commands;

use Tests\ConsoleTestCase;
use Tests\Utils\CheckSecurityFake;
use Tests\Utils\FileUtilsFake;
use Tests\Utils\PhpFileBuilder;

class ExecuteToolCommandTest extends ConsoleTestCase
{
    protected $phpFileBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileBuilder = new PhpFileBuilder('File');
    }

    public function allToolsOKDataProvider()
    {
        return [
            'check-security' => [
                'tool' => 'check-security',
                'command' => "check-security",
                'Alias of the tool when is executed' => 'check-security'
            ],
            'phpcs' => [
                'tool' => 'phpcs',
                'command' => "phpcbf $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6",
                'Alias of the tool when is executed' => 'phpcbf'
            ],
            'phpcpd' => [
                'tool' => 'phpcpd',
                'command' => "php phpcpd.phar --exclude $this->path/vendor $this->path/src",
                'Alias of the tool when is executed' => 'phpcpd'
            ],
            'phpmd' => [
                'tool' => 'phpmd',
                'command' => "phpmd $this->path/src ansi unusedcode --exclude \"$this->path/vendor\"",
                'Alias of the tool when is executed' => 'phpmd'
            ],
            'parallel-lint' => [
                'tool' => 'parallel-lint',
                'command' => "parallel-lint $this->path/src --exclude $this->path/vendor",
                'Alias of the tool when is executed' => 'parallel-lint'
            ],
            'phpstan' => [
                'tool' => 'phpstan',
                'command' => "phpstan analyse --no-progress --ansi $this->path/src",
                'Alias of the tool when is executed' => 'phpstan'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider allToolsOKDataProvider
     */
    function it_runs_only_one_tool($tool, $commandUnderTheHood, $toolAlias)
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $this->artisan("tool $tool")
            ->assertExitCode(0)
            ->toolHasBeenExecutedSuccessfully($toolAlias)
            ->containsStringInOutput($commandUnderTheHood);
    }

    public function allToolsKODataProvider()
    {
        return [
            'check-security' => [
                'tool' => 'check-security',
                'command' => 'composer check-security',
                'Alias of the tool when is executed' => 'check-security'
            ],
            'phpcs' => [
                'tool' => 'phpcs',
                'command' => "phpcbf $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6",
                'Alias of the tool when is executed' => 'phpcbf'
            ],
            'phpcpd' => [
                'tool' => 'phpcpd',
                'command' => "php phpcpd.phar --exclude $this->path/vendor $this->path/src",
                'Alias of the tool when is executed' => 'phpcpd.phar'
            ],
            'phpmd' => [
                'tool' => 'phpmd',
                'command' => "phpmd $this->path/src ansi unusedcode --exclude \"$this->path/vendor\"",
                'Alias of the tool when is executed' => 'phpmd'
            ],
            'parallel-lint' => [
                'tool' => 'parallel-lint',
                'command' => "parallel-lint $this->path/src --exclude $this->path/vendor",
                'Alias of the tool when is executed' => 'parallel-lint'
            ],
            'phpstan' => [
                'tool' => 'phpstan',
                'command' => "phpstan analyse --no-progress --ansi $this->path/src",
                'Alias of the tool when is executed' => 'phpstan'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider allToolsKODataProvider
     */
    function it_finish_with_exit_1_when_the_tool_fails($tool, $commandUnderTheHood, $toolAlias)
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->buildWithErrors([$tool]));

        $this->app->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->artisan("tool $tool")
            ->assertExitCode(1)
            ->toolHasFailed($toolAlias)
            ->containsStringInOutput($commandUnderTheHood);
    }


    public function allToolsAtSameTimeDataProvider()
    {
        return [
            'All tools' => [
                'Tools' => [
                    'check-security',
                    'phpcs',
                    'phpcpd',
                    'phpmd',
                    'parallel-lint',
                    'phpstan'
                ],
                'Command' =>  [
                    'check-security' => 'composer check-security',
                    'phpcs' => "phpcbf $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6",
                    'phpcpd' => "php phpcpd.phar --exclude $this->path/vendor $this->path/src",
                    'phpmd' => "phpmd $this->path/src ansi unusedcode --exclude \"$this->path/vendor\"",
                    'parallel-lint' => "parallel-lint $this->path/src --exclude $this->path/vendor",
                    'phpstan' => "phpstan analyse --no-progress --ansi $this->path/src",
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider allToolsAtSameTimeDataProvider
     */
    function it_runs_all_configured_tools_at_same_time($tools, $commandUnderTheHood)
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->setTools($tools)->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $this->artisan('tool all')
            ->assertExitCode(0)
            ->toolHasBeenExecutedSuccessfully('check-security')
            ->toolHasBeenExecutedSuccessfully('phpcbf')
            ->toolHasBeenExecutedSuccessfully('phpcpd')
            ->toolHasBeenExecutedSuccessfully('phpmd')
            ->toolHasBeenExecutedSuccessfully('parallel-lint')
            ->toolHasBeenExecutedSuccessfully('phpstan')
            ->notContainsStringInOutput($commandUnderTheHood['phpcs'])
            ->notContainsStringInOutput($commandUnderTheHood['phpcpd'])
            ->notContainsStringInOutput($commandUnderTheHood['phpmd'])
            ->notContainsStringInOutput($commandUnderTheHood['parallel-lint'])
            ->notContainsStringInOutput($commandUnderTheHood['phpstan']);
    }

    public function onlyConfiguredToolsAtSameTimeDataProvider()
    {
        return [
            'First set of tools' => [
                'Tools' => [
                    'check-security',
                    'phpcs',
                    'phpcpd',
                ],
                'Runned Tools' => [
                    'check-security',
                    'phpcbf',
                    'phpcpd',
                ],
                'Not runned tools' =>  [
                    'phpmd',
                    'parallel-lint',
                    'phpstan'
                ],
            ],
            'Second set of tools' => [
                'Tools' => [
                    'phpmd',
                    'parallel-lint',
                    'phpstan'
                ],
                'Runned Tools' => [
                    'phpmd',
                    'parallel-lint',
                    'phpstan'
                ],
                'Not runned tools' =>  [
                    'check-security',
                    'phpcs',
                    'phpcpd',
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider onlyConfiguredToolsAtSameTimeDataProvider
     */
    function it_runs_only_configured_tools_in_Tools_tag_at_same_time($tools, $runnedTools, $notRunnedTools)
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->setTools($tools)->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $this->artisan('tool all')
            ->assertExitCode(0)
            ->toolHasBeenExecutedSuccessfully($runnedTools[0])
            ->toolHasBeenExecutedSuccessfully($runnedTools[1])
            ->toolHasBeenExecutedSuccessfully($runnedTools[2])
            ->toolDidNotRun($notRunnedTools[0])
            ->toolDidNotRun($notRunnedTools[1])
            ->toolDidNotRun($notRunnedTools[2]);
    }

    public function exit1DataProvider()
    {
        return [
            'Fail phpcpd' => [
                'Tools executed successfully' => [
                    'check-security',
                    'phpcbf',
                    'phpmd',
                    'parallel-lint',
                    'phpstan'
                ],
                'Failed Tool' => 'phpcpd',
            ],
            'Fail phpmd' => [
                'Tools executed successfully' => [
                    'check-security',
                    'phpcbf',
                    'phpcpd',
                    'parallel-lint',
                    'phpstan'
                ],
                'Failed Tool' => 'phpmd',
            ],
            'Fail phpstan' => [
                'Tools executed successfully' => [
                    'check-security',
                    'phpcbf',
                    'phpcpd',
                    'phpmd',
                    'parallel-lint'
                ],
                'Failed Tool' => 'phpstan',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider exit1DataProvider
     */
    function it_returns_exit_1_when_some_tool_fails($toolsExecutedSuccessfully, $failedTool)
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->buildWithErrors([$failedTool]));

        $this->artisan('tool all')
            ->assertExitCode(1)
            ->toolHasBeenExecutedSuccessfully($toolsExecutedSuccessfully[0])
            ->toolHasBeenExecutedSuccessfully($toolsExecutedSuccessfully[1])
            ->toolHasBeenExecutedSuccessfully($toolsExecutedSuccessfully[2])
            ->toolHasBeenExecutedSuccessfully($toolsExecutedSuccessfully[3])
            ->toolHasBeenExecutedSuccessfully($toolsExecutedSuccessfully[4])
            ->toolHasFailed($failedTool);
    }

    /** @test */
    function it_prints_error_when_tries_to_run_an_not_supported_tool()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        $this->artisan('tool notSupportedTool')
            ->assertExitCode(1)
            ->expectsOutput(
                'The tool notSupportedTool is not supported by GiHooks. Tools: phpcs, check-security, parallel-lint, phpmd, phpcpd, phpstan'
            );
    }

    /** @test */
    function it_prints_error_when_the_tool_has_not_configuration_and_it_is_required()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->setConfigurationTools([])->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $this->artisan('tool phpmd')
            ->assertExitCode(1)
            ->expectsOutput('The configuration file has some errors')
            ->expectsOutput('The tag \'phpmd\' is missing.');
    }

    /**
     * @test
     * 1. It creates a githooks.yml with the standard configuration (PSR12 rules, path src, etc)
     * 2. It creates two files: one without erros and another with errors.
     * 3. It add to git stage only the file without errors.
     * 4. It runs the command with the option 'fast'.
     * 5. It overrides the execution: phpcs only runs over files added to git stage (the file without errors) instead 'src'
     */
    function it_can_be_override_full_execution_from_githooksyml_for_fast_execution_from_cli()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->setOptions(['execution' =>  'full'])->buildYalm());

        $pathForFileWithoutErrors = $this->path . '/src/File.php';
        file_put_contents($pathForFileWithoutErrors, $this->fileBuilder->build());

        $pathForFileWithErrors = $this->path . '/src/FileWithErrors.php';
        file_put_contents($pathForFileWithErrors, $this->fileBuilder->buildWithErrors(['phpcs']));

        $this->app->resolving(FileUtilsFake::class, function ($gitFiles) use ($pathForFileWithoutErrors) {
            $gitFiles->setModifiedfiles([$pathForFileWithoutErrors]);
            $gitFiles->setFilesThatShouldBeFoundInDirectories([$pathForFileWithoutErrors]);
        });

        $commandUnderTheHood = "phpcbf $pathForFileWithoutErrors";
        $this->artisan('tool phpcs fast')
            ->containsStringInOutput($commandUnderTheHood)
            ->toolHasBeenExecutedSuccessfully('phpcbf');
    }

    /**
     * @test
     * 1. It creates a githooks.yml with the standard configuration (PSR12 rules, path src, etc)
     * 2. It creates two files: one without erros and another with errors.
     * 3. It add to git stage only the file without errors.
     * 4. It runs the command with the option 'fast'.
     * 5. It overrides the execution: phpcs only runs over files added to git stage (the file without errors) instead 'src'
     */
    function it_prints_error_when_tries_to_override_execution_mode_from_cli_with_a_wrong_value()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        $wrongExecutionMode = 'invent';

        $this->artisan("tool phpcs $wrongExecutionMode")
            ->assertExitCode(1)
            ->expectsOutput("The value '$wrongExecutionMode' is not allowed for the tag 'execution'. Accept: full, fast");
    }
}