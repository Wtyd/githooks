<?php

namespace Tests\Unit\LoadTools;

use Wtyd\GitHooks\LoadTools\FastExecution;
use Wtyd\GitHooks\Tools\ToolsFactoy;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Utils\FileUtilsFake;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\CheckSecurity;
use Wtyd\GitHooks\Tools\CopyPasteDetector;

class FastExecutionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    function acelerableToolsProvider()
    {
        return [
            'Php Code Sniffer' => ['phpcs'],
            'Php Stan' => ['phpstan'],
            'Php Mess Detector' => ['phpmd'],
            'Parallel-Lint' => ['parallel-lint'],
        ];
    }

    /**
     * @test
     * @dataProvider acelerableToolsProvider
     */
    function it_replaces_the_Paths_array_of_the_configuration_file_of_each_acelerable_tool_with_the_modified_files_that_are_inside_the_tool_Paths(
        $tool
    ) {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php', 'database/my_migration.php', 'otherPath/file3.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php']);

        $toolsFactorySpy = Mockery::spy(ToolsFactoy::class);
        $configurationFile = [
            'Tools' => [$tool],
            $tool => [
                'paths' => ['src', 'app', 'tests']
            ]
        ];

        $expectedFiles = ['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php'];
        $configurationFile[$tool]['paths'] = $expectedFiles;
        $expectedToolConfigurationArray = [new ToolConfiguration($tool, $configurationFile[$tool])];

        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy);

        $fastExecution->getTools(new ConfigurationFile($configurationFile, $tool));

        $toolsFactorySpy->shouldHaveReceived('__invoke', [$expectedToolConfigurationArray]);
    }


    function noAcelerableToolsProvider()
    {
        return [
            'Set of files' => [
                'Git modified files' => ['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php', 'database/my_migration.php', 'otherPath/file3.php'],
                'Files that should be found in directories' => ['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider noAcelerableToolsProvider
     */
    function it_dont_replaces_the_Paths_array_of_the_configuration_file_with_phpcpd($gitModifiedFiles, $filesThatShouldBeFoundInDirectories)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($gitModifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories($filesThatShouldBeFoundInDirectories);

        $toolsFactorySpy = Mockery::spy(ToolsFactoy::class);

        $tool = 'phpcpd';
        $configurationFile = [
            'Tools' => [$tool],
            $tool => [
                'paths' => ['src', 'app', 'tests']
            ]
        ];
        $expectedToolConfigurationArray = [new ToolConfiguration($tool, $configurationFile[$tool])];
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy);

        $fastExecution->getTools(new ConfigurationFile($configurationFile, $tool));

        $toolsFactorySpy->shouldHaveReceived('__invoke', [$expectedToolConfigurationArray]);
    }

    /**
     * @test
     * @dataProvider noAcelerableToolsProvider
     */
    function it_dont_replaces_the_Paths_array_of_the_configuration_file_with_checkSecurity($gitModifiedFiles, $filesThatShouldBeFoundInDirectories)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($gitModifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories($filesThatShouldBeFoundInDirectories);

        $toolsFactorySpy = Mockery::spy(ToolsFactoy::class);

        $tool = 'check-security';
        $configurationFile = [
            'Tools' => [$tool],
        ];
        $expectedToolConfigurationArray = [new ToolConfiguration($tool, [])];
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy);

        $fastExecution->getTools(new ConfigurationFile($configurationFile, $tool));

        $toolsFactorySpy->shouldHaveReceived('__invoke', [$expectedToolConfigurationArray]);
    }

    /**
     * @test
     * @dataProvider acelerableToolsProvider
     */
    function it_skips_the_acelerable_tool_when_substituting_Paths_and_Paths_are_left_empty($tool)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['database/my_migration.php', 'otherPath/file3.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories([]);

        $configurationFile = [
            'Tools' => [$tool],
            $tool => [
                'paths' => ['src',]
            ]
        ];
        $fastExecution = new FastExecution($gitFiles, new ToolsFactoy());

        $loadedTools = $fastExecution->getTools(new ConfigurationFile($configurationFile, $tool));

        $this->assertCount(0, $loadedTools);
    }

    function noAcelerableTools2Provider()
    {
        return [
            'Copy Paste Detector' => [
                'Tool Name' => 'phpcpd',
                'Tool Class' => CopyPasteDetector::class
            ],
            'Check-Security' => [
                'Tool Name' => 'check-security',
                'Tool Class' => CheckSecurity::class
            ],
        ];
    }

    /**
     * @test
     * @dataProvider noAcelerableTools2Provider
     */
    function it_instance_the_no_acelerable_tool_even_if_the_modified_files_are_not_in_they_Paths($toolName, $toolClass)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['database/my_migration.php', 'otherPath/file3.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories([]);

        $configurationFile = [
            'Tools' => [$toolName],
            $toolName => [
                'paths' => ['src',]
            ]
        ];
        $fastExecution = new FastExecution($gitFiles, new ToolsFactoy());

        $loadedTools = $fastExecution->getTools(new ConfigurationFile($configurationFile, $toolName));

        $this->assertCount(1, $loadedTools);

        $this->assertInstanceOf($toolClass, $loadedTools[$toolName]);
    }
}