<?php

namespace Tests\Unit\LoadTools;

use Wtyd\GitHooks\LoadTools\FastStrategy;
use Wtyd\GitHooks\Tools\CopyPasteDetector;
use Wtyd\GitHooks\Tools\CheckSecurity;
use Wtyd\GitHooks\Tools\ToolsFactoy;
use Wtyd\GitHooks\Utils\GitFiles;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class FastStrategyTest extends TestCase
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

        $gitFiles = Mockery::mock(GitFiles::class);
        $modifiedFiles = ['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php', 'database/my_migration.php', 'otherPath/file3.php'];
        $gitFiles->shouldReceive('getModifiedFiles')->andReturn($modifiedFiles);

        $ToolsFactorySpy = Mockery::spy(ToolsFactoy::class);
        $configurationFile = [
            'Tools' => [$tool],
            $tool => [
                'paths' => ['src', 'app', 'tests']
            ]
        ];
        $fastStrategy = new FastStrategy($configurationFile, $gitFiles, $ToolsFactorySpy);

        $fastStrategy->getTools();

        $expectedFiles = ['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php'];
        $configurationFile[$tool]['paths'] = $expectedFiles;
        $ToolsFactorySpy->shouldHaveReceived('__invoke', [[$tool], $configurationFile]);
    }

    function noAcelerableToolsProvider()
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
     * @dataProvider noAcelerableToolsProvider
     */
    function it_dont_replaces_the_Paths_array_of_the_configuration_file_of_each_NO_acerelerable_tool($toolName)
    {

        $gitFiles = Mockery::mock(GitFiles::class);
        $modifiedFiles = ['app/file1.php', 'src/file2.php', 'tests/Unit/test1.php', 'database/my_migration.php', 'otherPath/file3.php'];
        $gitFiles->shouldReceive('getModifiedFiles')->andReturn($modifiedFiles);

        $ToolsFactorySpy = Mockery::spy(ToolsFactoy::class);

        $configurationFile = [
            'Tools' => [$toolName],
            $toolName => [
                'paths' => ['src', 'app', 'tests']
            ]
        ];
        $fastStrategy = new FastStrategy($configurationFile, $gitFiles, $ToolsFactorySpy);

        $fastStrategy->getTools();

        $ToolsFactorySpy->shouldHaveReceived('__invoke', [[$toolName], $configurationFile]);
    }

    /**
     * @test
     * @dataProvider acelerableToolsProvider
     */
    function it_skips_the_acelerable_tool_when_substituting_Paths_and_Paths_are_left_empty($tool)
    {
        $gitFiles = Mockery::mock(GitFiles::class);
        $modifiedFilesOnOtherPathsThanSrc = ['database/my_migration.php', 'otherPath/file3.php'];
        $gitFiles->shouldReceive('getModifiedFiles')->andReturn($modifiedFilesOnOtherPathsThanSrc);

        $configurationFile = [
            'Tools' => [$tool],
            $tool => [
                'paths' => ['src',]
            ]
        ];
        $fastStrategy = new FastStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $fastStrategy->getTools();

        $this->assertCount(0, $loadedTools);
    }

    /**
     * @test
     * @dataProvider noAcelerableToolsProvider
     */
    function it_instance_the_no_acelerable_tool_even_if_the_modified_files_are_not_in_they_Paths($toolName, $toolClass)
    {
        $gitFiles = Mockery::mock(GitFiles::class);
        $modifiedFilesOnOtherPathsThanSrc = ['database/my_migration.php', 'otherPath/file3.php'];
        $gitFiles->shouldReceive('getModifiedFiles')->andReturn($modifiedFilesOnOtherPathsThanSrc);

        $configurationFile = [
            'Tools' => [$toolName],
            $toolName => [
                'paths' => ['src',]
            ]
        ];
        $fastStrategy = new FastStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $fastStrategy->getTools();

        $this->assertCount(1, $loadedTools);

        $this->assertInstanceOf($toolClass, $loadedTools[$toolName]);
    }
}
