<?php

namespace Tests\Unit\LoadTools;

use Wtyd\GitHooks\LoadTools\FastExecution;
use Wtyd\GitHooks\Tools\ToolsFactory;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Doubles\FileUtilsFake;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\{
    SecurityChecker,
    Phpcpd
};
use Wtyd\GitHooks\Registry\ToolRegistry;

class FastExecutionTest extends UnitTestCase
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

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);
        $configurationFile = [
            'Tools' => [$tool],
            $tool => [
                'paths' => ['src', 'app', 'tests']
            ]
        ];

        $expectedFiles = ['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php'];
        $configurationFile[$tool]['paths'] = $expectedFiles;
        $expectedToolConfigurationArray = [new ToolConfiguration($tool, $configurationFile[$tool], new ToolRegistry())];

        $registry = new ToolRegistry();
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy, $registry);

        $fastExecution->getTools(new ConfigurationFile($configurationFile, $tool, $registry));

        $toolsFactorySpy->shouldHaveReceived('__invoke', [$expectedToolConfigurationArray]);
    }


    /**
     * @test
     * @dataProvider acelerableToolsProvider
     */
    function it_distributes_modified_files_across_multiple_configured_paths($tool)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['src/ClassA.php', 'app/ClassB.php', 'lib/ClassC.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['src/ClassA.php', 'app/ClassB.php']);

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);
        $configurationFile = [
            'Tools' => [$tool],
            $tool => [
                'paths' => ['src', 'app']
            ]
        ];

        $expectedFiles = ['src/ClassA.php', 'app/ClassB.php'];
        $configurationFile[$tool]['paths'] = $expectedFiles;
        $expectedToolConfigurationArray = [new ToolConfiguration($tool, $configurationFile[$tool], new ToolRegistry())];

        $registry = new ToolRegistry();
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy, $registry);
        $fastExecution->getTools(new ConfigurationFile($configurationFile, $tool, $registry));

        $toolsFactorySpy->shouldHaveReceived('__invoke', [$expectedToolConfigurationArray]);
    }

    /**
     * @test
     * @dataProvider acelerableToolsProvider
     */
    function it_handles_paths_with_trailing_slash($tool)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['src/File.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['src/File.php']);

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);
        $configurationFile = [
            'Tools' => [$tool],
            $tool => [
                'paths' => ['src/']
            ]
        ];

        $expectedFiles = ['src/File.php'];
        $configurationFile[$tool]['paths'] = $expectedFiles;
        $expectedToolConfigurationArray = [new ToolConfiguration($tool, $configurationFile[$tool], new ToolRegistry())];

        $registry = new ToolRegistry();
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy, $registry);
        $fastExecution->getTools(new ConfigurationFile($configurationFile, $tool, $registry));

        $toolsFactorySpy->shouldHaveReceived('__invoke', [$expectedToolConfigurationArray]);
    }

    /**
     * @test
     * @dataProvider acelerableToolsProvider
     */
    function it_matches_individual_file_path_in_configuration($tool)
    {
        $targetFile = tempnam(sys_get_temp_dir(), 'githooks_test_');

        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles([$targetFile, 'other/file.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories([]);

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);
        $configurationFile = [
            'Tools' => [$tool],
            $tool => [
                'paths' => [$targetFile]
            ]
        ];

        $expectedFiles = [$targetFile];
        $configurationFile[$tool]['paths'] = $expectedFiles;
        $expectedToolConfigurationArray = [new ToolConfiguration($tool, $configurationFile[$tool], new ToolRegistry())];

        $registry = new ToolRegistry();
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy, $registry);
        $fastExecution->getTools(new ConfigurationFile($configurationFile, $tool, $registry));

        $toolsFactorySpy->shouldHaveReceived('__invoke', [$expectedToolConfigurationArray]);

        unlink($targetFile);
    }

    /**
     * @test
     * @dataProvider acelerableToolsProvider
     */
    function it_skips_tool_when_no_modified_files_match_any_path($tool)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['unrelated/path/file.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories([]);

        $configurationFile = [
            'Tools' => [$tool],
            $tool => [
                'paths' => ['src', 'app']
            ]
        ];
        $registry = new ToolRegistry();
        $fastExecution = new FastExecution($gitFiles, new ToolsFactory($registry), $registry);

        $configurationFile = new ConfigurationFile($configurationFile, $tool, $registry);
        $loadedTools = $fastExecution->getTools($configurationFile);

        $this->assertCount(0, $loadedTools);
        $this->assertEquals(["The tool $tool was skipped."], $configurationFile->getWarnings());
    }

    function noAcelerableToolsProvider()
    {
        return [
            'security-checker' => [
                'tool' => 'security-checker',
                'Configuration File' => [
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'local-php-security-checker']
                ],
            ],
            'phpcpd' => [
                'tool' => 'phpcpd',
                'Configuration File' => [
                    'Tools' => ['phpcpd'],
                    'phpcpd' => [
                        'executablePath' => 'phpcpd',
                        'paths' => ['src', 'app', 'tests']
                    ]
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider noAcelerableToolsProvider
     */
    function it_dont_replaces_the_Paths_array_of_the_configuration_file_with_non_acelerable_tools($tool, $configurationFile)
    {
        $gitModifiedFiles = ['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php', 'database/my_migration.php', 'otherPath/file3.php'];
        $filesThatShouldBeFoundInDirectories = ['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php'];

        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($gitModifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories($filesThatShouldBeFoundInDirectories);

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);


        $expectedToolConfigurationArray = [new ToolConfiguration($tool, $configurationFile[$tool], new ToolRegistry())];
        $registry = new ToolRegistry();
        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy, $registry);

        $fastExecution->getTools(new ConfigurationFile($configurationFile, $tool, $registry));

        $toolsFactorySpy->shouldHaveReceived('__invoke', [$expectedToolConfigurationArray]);
    }

    /**
     * @test
     * @dataProvider acelerableToolsProvider
     */
    function it_skips_the_acelerable_tool_and_adds_warning_when_substituting_Paths_and_Paths_are_left_empty($tool)
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
        $registry = new ToolRegistry();
        $fastExecution = new FastExecution($gitFiles, new ToolsFactory($registry), $registry);

        $configurationFile = new ConfigurationFile($configurationFile, $tool, $registry);
        $loadedTools = $fastExecution->getTools($configurationFile);

        $this->assertCount(0, $loadedTools);
        $this->assertEquals(["The tool $tool was skipped."], $configurationFile->getWarnings());
    }

    function noAcelerableTools2Provider()
    {
        return [
            'Copy Paste Detector' => [
                'Tool Name' => 'phpcpd',
                'Tool Class' => Phpcpd::class
            ],
            'Check-Security' => [
                'Tool Name' => 'security-checker',
                'Tool Class' => SecurityChecker::class
            ],
        ];
    }

    /** @test */
    function processTools_accelerates_a_subset_of_tools()
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles(['src/File.php']);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['src/File.php']);

        $toolsFactorySpy = Mockery::spy(ToolsFactory::class);

        $configurationFile = [
            'Tools' => ['phpcs', 'phpmd'],
            'phpcs' => ['paths' => ['src']],
            'phpmd' => ['paths' => ['src'], 'rules' => 'unusedcode'],
        ];
        $registry = new ToolRegistry();
        $configFile = new ConfigurationFile($configurationFile, 'all', $registry);
        $subset = $configFile->getToolsConfiguration();

        $expectedPhpcs = new ToolConfiguration('phpcs', ['paths' => ['src/File.php']], new ToolRegistry());
        $expectedPhpmd = new ToolConfiguration('phpmd', ['paths' => ['src/File.php'], 'rules' => 'unusedcode'], new ToolRegistry());

        $fastExecution = new FastExecution($gitFiles, $toolsFactorySpy, $registry);
        $fastExecution->processTools($subset, $configFile);

        $toolsFactorySpy->shouldHaveReceived('__invoke', [[$expectedPhpcs, $expectedPhpmd]]);
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
        $registry = new ToolRegistry();
        $fastExecution = new FastExecution($gitFiles, new ToolsFactory($registry), $registry);

        $loadedTools = $fastExecution->getTools(new ConfigurationFile($configurationFile, $toolName, $registry));

        $this->assertCount(1, $loadedTools);

        $this->assertInstanceOf($toolClass, $loadedTools[$toolName]);
    }
}
