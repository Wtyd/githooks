<?php

namespace Tests\Unit\LoadTools;

use Wtyd\GitHooks\LoadTools\FullExecution;
use Wtyd\GitHooks\Tools\{
    CodeSniffer,
    CopyPasteDetector,
    CheckSecurity,
    MessDetector,
    ParallelLint,
    Stan,
    ToolsFactoy
};
use PHPUnit\Framework\TestCase;
use Tests\Utils\ConfigurationFileBuilder;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;

class FullExecutionTest extends TestCase
{

    function allToolsProvider()
    {
        return [
            'Php Code Sniffer' => [
                CodeSniffer::class,
                'phpcs'
            ],
            'Php Stan' => [
                Stan::class,
                'phpstan'
            ],
            'Php Mess Detector' => [
                MessDetector::class,
                'phpmd'
            ],
            'Php Copy Paste Detector' => [
                CopyPasteDetector::class,
                'phpcpd'
            ],
            'Parallel-Lint' => [
                ParallelLint::class,
                'parallel-lint'
            ],
            'Composer Check-security' => [
                CheckSecurity::class,
                'check-security'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_can_load_each_tool($toolClass, $tool)
    {
        $fullExecution = new FullExecution(new ToolsFactoy());

        $configurationFileBuilder = new ConfigurationFileBuilder('');
        $configurationFileBuilder->setTools([$tool]);

        $configurationFile = $configurationFileBuilder->buildArray();
        $loadedTools = $fullExecution->getTools(new ConfigurationFile($configurationFile, $tool));

        $this->assertCount(1, $loadedTools);

        $this->assertInstanceOf($toolClass, $loadedTools[$tool]);
    }

    /** @test*/
    function it_can_load_all_tools_at_same_time()
    {

        $fullExecution = new FullExecution(new ToolsFactoy());

        $configurationFileBuilder = new ConfigurationFileBuilder('');

        $loadedTools = $fullExecution->getTools(new ConfigurationFile($configurationFileBuilder->buildArray(), 'all'));


        $this->assertCount(6, $loadedTools);
    }
}