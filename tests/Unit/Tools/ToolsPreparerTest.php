<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\LoadTools\ExecutionFactory;
use Wtyd\GitHooks\LoadTools\ExecutionMode;
use Wtyd\GitHooks\LoadTools\FastExecution;
use Wtyd\GitHooks\LoadTools\FullExecution;
use Wtyd\GitHooks\Tools\ToolsFactory;
use Wtyd\GitHooks\Tools\ToolsPreparer;
use Wtyd\GitHooks\Utils\FileUtilsFake;

class ToolsPreparerTest extends UnitTestCase
{
    use MockeryPHPUnitIntegration;

    /** @test */
    function all_tools_inherit_global_execution_when_no_per_tool_mode_is_set()
    {
        $configArray = [
            'Options' => ['execution' => 'full'],
            'Tools' => ['phpcs'],
            'phpcs' => ['paths' => ['src']],
        ];
        $configurationFile = new ConfigurationFile($configArray, 'all');

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);
        $fullExecution = new FullExecution($toolsFactorySpy);

        $executionFactory = Mockery::mock(ExecutionFactory::class);
        $executionFactory->shouldReceive('__invoke')
            ->with(ExecutionMode::FULL_EXECUTION)
            ->once()
            ->andReturn($fullExecution);
        $executionFactory->shouldNotReceive('__invoke')->with(ExecutionMode::FAST_EXECUTION);

        $preparer = new ToolsPreparer($executionFactory);
        $preparer($configurationFile);

        $toolsFactorySpy->shouldHaveReceived('__invoke')->once();
    }

    /** @test */
    function per_tool_fast_overrides_global_full()
    {
        $configArray = [
            'Options' => ['execution' => 'full'],
            'Tools' => ['phpcs', 'phpcbf'],
            'phpcs' => ['paths' => ['src']],
            'phpcbf' => ['paths' => ['src'], 'execution' => 'fast'],
        ];
        $configurationFile = new ConfigurationFile($configArray, 'all');

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);
        $fullExecution = new FullExecution($toolsFactorySpy);

        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['src/File.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['src/File.php']);
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy);

        $executionFactory = Mockery::mock(ExecutionFactory::class);
        $executionFactory->shouldReceive('__invoke')
            ->with(ExecutionMode::FULL_EXECUTION)
            ->andReturn($fullExecution);
        $executionFactory->shouldReceive('__invoke')
            ->with(ExecutionMode::FAST_EXECUTION)
            ->andReturn($fastExecution);

        $preparer = new ToolsPreparer($executionFactory);
        $preparer($configurationFile);

        // ToolsFactory should have been called twice: once for full group, once for fast group
        $toolsFactorySpy->shouldHaveReceived('__invoke')->twice();
    }

    /** @test */
    function cli_execution_overrides_per_tool_settings()
    {
        $configArray = [
            'Options' => ['execution' => 'fast'],
            ConfigurationFile::CLI_EXECUTION_OVERRIDE => true,
            'Tools' => ['phpcs', 'phpcbf'],
            'phpcs' => ['paths' => ['src'], 'execution' => 'full'],
            'phpcbf' => ['paths' => ['src']],
        ];
        $configurationFile = new ConfigurationFile($configArray, 'all');

        // CLI says fast, so both tools should use fast (not phpcs's per-tool 'full')
        $this->assertTrue($configurationFile->isCLIExecutionOverride());
        $this->assertEquals('fast', $configurationFile->getExecution());

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);

        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['src/File.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['src/File.php']);
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy);

        $executionFactory = Mockery::mock(ExecutionFactory::class);
        $executionFactory->shouldReceive('__invoke')
            ->with(ExecutionMode::FAST_EXECUTION)
            ->andReturn($fastExecution);
        $executionFactory->shouldNotReceive('__invoke')->with(ExecutionMode::FULL_EXECUTION);

        $preparer = new ToolsPreparer($executionFactory);
        $preparer($configurationFile);

        // Only fast strategy should be used since CLI overrides all
        $toolsFactorySpy->shouldHaveReceived('__invoke')->once();
    }

    /** @test */
    function non_accelerable_tool_with_per_tool_fast_generates_warning_and_runs_full()
    {
        $configArray = [
            'Options' => ['execution' => 'full'],
            'Tools' => ['phpcpd'],
            'phpcpd' => ['paths' => ['src'], 'execution' => 'fast'],
        ];
        $configurationFile = new ConfigurationFile($configArray, 'all');

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);
        $fullExecution = new FullExecution($toolsFactorySpy);

        $executionFactory = Mockery::mock(ExecutionFactory::class);
        $executionFactory->shouldReceive('__invoke')
            ->with(ExecutionMode::FULL_EXECUTION)
            ->andReturn($fullExecution);

        $preparer = new ToolsPreparer($executionFactory);
        $preparer($configurationFile);

        $warnings = $preparer->getConfigurationFileWarnings();
        $this->assertContains(
            "Tool 'phpcpd' does not support fast execution. It will run in full mode.",
            $warnings
        );
    }

    /** @test */
    function non_accelerable_tool_with_cli_all_fast_does_not_generate_warning()
    {
        $configArray = [
            'Options' => ['execution' => 'fast'],
            ConfigurationFile::CLI_EXECUTION_OVERRIDE => true,
            'Tools' => ['phpcs', 'phpcpd'],
            'phpcs' => ['paths' => ['src']],
            'phpcpd' => ['paths' => ['src']],
        ];
        $configurationFile = new ConfigurationFile($configArray, 'all');

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);
        $fullExecution = new FullExecution($toolsFactorySpy);

        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['src/File.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['src/File.php']);
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy);

        $executionFactory = Mockery::mock(ExecutionFactory::class);
        $executionFactory->shouldReceive('__invoke')
            ->with(ExecutionMode::FULL_EXECUTION)
            ->andReturn($fullExecution);
        $executionFactory->shouldReceive('__invoke')
            ->with(ExecutionMode::FAST_EXECUTION)
            ->andReturn($fastExecution);

        $preparer = new ToolsPreparer($executionFactory);
        $preparer($configurationFile);

        $warnings = $preparer->getConfigurationFileWarnings();
        $this->assertNotContains(
            "Tool 'phpcpd' does not support fast execution. It will run in full mode.",
            $warnings
        );
    }

    /** @test */
    function non_accelerable_tool_with_cli_single_tool_fast_generates_warning()
    {
        $configArray = [
            'Options' => ['execution' => 'fast'],
            ConfigurationFile::CLI_EXECUTION_OVERRIDE => true,
            'Tools' => ['phpcpd'],
            'phpcpd' => ['paths' => ['src']],
        ];
        // Single tool run, not 'all'
        $configurationFile = new ConfigurationFile($configArray, 'phpcpd');

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);
        $fullExecution = new FullExecution($toolsFactorySpy);

        $executionFactory = Mockery::mock(ExecutionFactory::class);
        $executionFactory->shouldReceive('__invoke')
            ->with(ExecutionMode::FULL_EXECUTION)
            ->andReturn($fullExecution);

        $preparer = new ToolsPreparer($executionFactory);
        $preparer($configurationFile);

        $warnings = $preparer->getConfigurationFileWarnings();
        $this->assertContains(
            "Tool 'phpcpd' does not support fast execution. It will run in full mode.",
            $warnings
        );
    }

    /** @test */
    function mixed_modes_processes_each_group_with_correct_strategy()
    {
        $configArray = [
            'Options' => ['execution' => 'full'],
            'Tools' => ['phpcs', 'phpmd', 'phpstan'],
            'phpcs' => ['paths' => ['src']],
            'phpmd' => ['paths' => ['src'], 'rules' => 'unusedcode', 'execution' => 'fast'],
            'phpstan' => ['paths' => ['src'], 'execution' => 'fast'],
        ];
        $configurationFile = new ConfigurationFile($configArray, 'all');

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);
        $fullExecution = new FullExecution($toolsFactorySpy);

        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['src/File.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['src/File.php']);
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy);

        $executionFactory = Mockery::mock(ExecutionFactory::class);
        $executionFactory->shouldReceive('__invoke')
            ->with(ExecutionMode::FULL_EXECUTION)
            ->once()
            ->andReturn($fullExecution);
        $executionFactory->shouldReceive('__invoke')
            ->with(ExecutionMode::FAST_EXECUTION)
            ->once()
            ->andReturn($fastExecution);

        $preparer = new ToolsPreparer($executionFactory);
        $preparer($configurationFile);

        // Both strategies called: full for phpcs, fast for phpmd+phpstan
        $toolsFactorySpy->shouldHaveReceived('__invoke')->twice();
    }
}
