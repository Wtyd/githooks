<?php

namespace Tests\System\Commands;

use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Utils\FileUtilsFake;
use Tests\Utils\PhpFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Tools\Process\Execution\MultiProcessesExecutionFake;
use Wtyd\GitHooks\Tools\Process\Execution\ProcessExecutionFake;
use Wtyd\GitHooks\Utils\FileUtils;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

class ExecuteToolCommandTest extends SystemTestCase
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
            'security-checker' => [
                'tool' => 'security-checker',
                'command' => 'local-php-security-checker',
                'Alias of the tool when is executed' => 'security-checker'
            ],
            'phpcs' => [
                'tool' => 'phpcs',
                'command' => "phpcs $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6",
                'Alias of the tool when is executed' => 'phpcs'
            ],
            'phpcbf' => [
                'tool' => 'phpcbf',
                'command' => "phpcbf $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6",
                'Alias of the tool when is executed' => 'phpcbf'
            ],
            'phpcpd' => [
                'tool' => 'phpcpd',
                'command' => "phpcpd --exclude $this->path/vendor --min-lines=5 $this->path/src",
                'Alias of the tool when is executed' => 'phpcpd'
            ],
            'phpmd' => [
                'tool' => 'phpmd',
                'command' => "phpmd $this->path/src ansi unusedcode --exclude \"$this->path/vendor\"",
                'Alias of the tool when is executed' => 'phpmd'
            ],
            'parallel-lint' => [
                'tool' => 'parallel-lint',
                'command' => "parallel-lint --exclude $this->path/vendor --colors $this->path/src",
                'Alias of the tool when is executed' => 'parallel-lint'
            ],
            'phpstan' => [
                'tool' => 'phpstan',
                'command' => "phpstan analyse --no-progress $this->path/src",
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
            'security-checker' => [
                'tool' => 'security-checker',
                'command' => 'local-php-security-checker',
                'Alias of the tool when is executed' => 'security-checker'
            ],
            'phpcs' => [
                'tool' => 'phpcs',
                'command' => "phpcs $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6",
                'Alias of the tool when is executed' => 'phpcs'
            ],
            'phpcbf' => [
                'tool' => 'phpcbf',
                'command' => "phpcbf $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6",
                'Alias of the tool when is executed' => 'phpcbf'
            ],
            'phpcpd' => [
                'tool' => 'phpcpd',
                'command' => "phpcpd --exclude $this->path/vendor --min-lines=5 $this->path/src",
                'Alias of the tool when is executed' => 'phpcpd'
            ],
            'phpmd' => [
                'tool' => 'phpmd',
                'command' => "phpmd $this->path/src ansi unusedcode --exclude \"$this->path/vendor\"",
                'Alias of the tool when is executed' => 'phpmd'
            ],
            'parallel-lint' => [
                'tool' => 'parallel-lint',
                'command' => "parallel-lint --exclude $this->path/vendor --colors $this->path/src",
                'Alias of the tool when is executed' => 'parallel-lint'
            ],
            'phpstan' => [
                'tool' => 'phpstan',
                'command' => "phpstan analyse --no-progress $this->path/src",
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

        $this->app->resolving(ProcessExecutionFake::class, function ($processExecutionFake) use ($toolAlias) {
            $processExecutionFake->setToolsThatMustFail([$toolAlias]);
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
                    'security-checker',
                    'phpcs',
                    'phpcbf',
                    'phpcpd',
                    'phpmd',
                    'parallel-lint',
                    'phpstan'
                ],
                'Command' =>  [
                    'security-checker' => 'local-php-security-checker',
                    'phpcs' => "phpcs $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6",
                    'phpcbf' => "phpcbf $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6",
                    'phpcpd' => "phpcpd --exclude $this->path/vendor $this->path/src",
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
            ->toolHasBeenExecutedSuccessfully('security-checker')
            ->toolHasBeenExecutedSuccessfully('phpcs')
            ->toolHasBeenExecutedSuccessfully('phpcbf')
            ->toolHasBeenExecutedSuccessfully('phpcpd')
            ->toolHasBeenExecutedSuccessfully('phpmd')
            ->toolHasBeenExecutedSuccessfully('parallel-lint')
            ->toolHasBeenExecutedSuccessfully('phpstan')
            ->notContainsStringInOutput($commandUnderTheHood['phpcs'])
            ->notContainsStringInOutput($commandUnderTheHood['phpcbf'])
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
                    'security-checker',
                    'phpcs',
                    'phpcpd',
                ],
                'Runned Tools' => [
                    'security-checker',
                    'phpcs',
                    'phpcpd',
                ],
                'Not runned tools' =>  [
                    'phpmd',
                    'parallel-lint',
                    'phpstan',
                    'phpcbf'
                ],
            ],
            'Second set of tools' => [
                'Tools' => [
                    'phpmd',
                    'parallel-lint',
                    'phpstan',
                    'phpcbf'
                ],
                'Runned Tools' => [
                    'phpmd',
                    'parallel-lint',
                    'phpstan'
                ],
                'Not runned tools' =>  [
                    'security-checker',
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
                    'security-checker',
                    'phpcs',
                    'phpmd',
                    'parallel-lint',
                    'phpstan'
                ],
                'Failed Tool' => 'phpcpd',
            ],
            'Fail phpmd' => [
                'Tools executed successfully' => [
                    'security-checker',
                    'phpcbf',
                    'phpcpd',
                    'parallel-lint',
                    'phpstan'
                ],
                'Failed Tool' => 'phpmd',
            ],
            'Fail phpstan' => [
                'Tools executed successfully' => [
                    'security-checker',
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

        $this->app->resolving(MultiProcessesExecutionFake::class, function ($processExecutionFake) use ($failedTool) {
            $processExecutionFake->setToolsThatMustFail([$failedTool]);
        });

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
                'The tool notSupportedTool is not supported by GiHooks. Tools: phpcs, phpcbf, security-checker, parallel-lint, phpmd, phpcpd, phpstan'
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

        $commandUnderTheHood = "phpcs $pathForFileWithoutErrors";
        $this->artisan('tool phpcs fast')
            ->containsStringInOutput($commandUnderTheHood)
            ->toolHasBeenExecutedSuccessfully('phpcs');
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

    /**
     * @test
     * Fix bug for 2.0.1 release
     */
    function it_skip_the_tool_when_the_tool_runs_against_root_directory_in_fast_mode_and_the_file_was_deleted()
    {
        file_put_contents(
            $this->path . '/githooks.yml',
            $this->configurationFileBuilder
                ->setPhpCSConfiguration([
                    'paths' => ['./'], //root path
                    'standard' => 'PSR12',
                    'ignore' =>  ['vendor'],
                ])
                ->buildYalm()
        );

        $file = $this->path . '/src/File.php';
        Storage::shouldReceive('exists')
            ->once()
            ->with($file)
            ->andReturn(false);

        $this->app->bind(FileUtilsInterface::class, FileUtils::class);

        $this->partialMock(FileUtils::class, function (MockInterface $mock) use ($file) {
            $mock->shouldReceive('getModifiedFiles')->once()->andReturn([$file]);
        });

        $this->artisan('tool phpcs fast')
            ->notContainsStringInOutput("phpcbf $file --standard=PSR12 --ignore=vendor")
            ->toolDidNotRun('phpcs');
    }

    /** @test */
    function it_overrides_Phpcbf_configuration_when_usePhpcsConfiguration_is_true()
    {
        $this->configurationFileBuilder->setPhpcbfConfiguration(['usePhpcsConfiguration' =>  true]);

        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $commandUnderTheHood = "phpcbf $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6";

        $this->artisan('tool phpcbf')
            ->assertExitCode(0)
            ->toolHasBeenExecutedSuccessfully('phpcbf')
            ->containsStringInOutput($commandUnderTheHood);

        //Tag 'phpcbf' of configuration file doesn't have configuration
        $configurationFile = $this->configurationFileBuilder->buildArray()['phpcbf'];
        $this->assertArrayNotHasKey('paths', $configurationFile);
        $this->assertArrayNotHasKey('standard', $configurationFile);
        $this->assertArrayNotHasKey('ignore', $configurationFile);
        $this->assertArrayNotHasKey('error-severity', $configurationFile);
        $this->assertArrayNotHasKey('warning-severity', $configurationFile);

        //Tag 'phpcbf' of configuration file only has 'usePhpcsConfiguration' key
        $this->assertArrayHasKey('usePhpcsConfiguration', $configurationFile);
    }

    /**
     * @test
     * @dataProvider allToolsKODataProvider
     */
    function it_prints_error_when_tool_execution_exceeds_the_timeout($tool, $commandUnderTheHood, $toolAlias)
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->buildWithErrors([$tool]));

        $this->app->resolving(ProcessExecutionFake::class, function ($processExecutionFake) use ($toolAlias) {
            $processExecutionFake->setToolsWithTimeout([$toolAlias]);
        });

        $this->artisan("tool $tool")
            ->assertExitCode(1)
            ->toolHasFailed($toolAlias)
            ->containsStringInOutput('exceeded the timeout');
    }

    /**
     * @test
     * @dataProvider exit1DataProvider
     */
    function it_returns_exit_1_when_some_tool_fails_by_timeout($toolsExecutedSuccessfully, $failedTool)
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->buildWithErrors([$failedTool]));

        $this->app->resolving(MultiProcessesExecutionFake::class, function ($processExecutionFake) use ($failedTool) {
            $processExecutionFake->setToolsWithTimeout([$failedTool]);
        });

        $this->artisan('tool all')
            ->assertExitCode(1)
            ->toolHasBeenExecutedSuccessfully($toolsExecutedSuccessfully[0])
            ->toolHasBeenExecutedSuccessfully($toolsExecutedSuccessfully[1])
            ->toolHasBeenExecutedSuccessfully($toolsExecutedSuccessfully[2])
            ->toolHasBeenExecutedSuccessfully($toolsExecutedSuccessfully[3])
            ->toolHasBeenExecutedSuccessfully($toolsExecutedSuccessfully[4])
            ->toolHasFailed($failedTool);
    }
}
