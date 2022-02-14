<?php

namespace Tests\Unit\LoadTools;

use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\LoadTools\FullExecution;
use Wtyd\GitHooks\Tools\ToolsFactoy;
use Wtyd\GitHooks\Tools\Tool\{
    CodeSniffer\Phpcbf,
    CodeSniffer\Phpcs,
    ParallelLint,
    Phpcpd,
    Phpmd,
    Phpstan,
    SecurityChecker
};
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\UnitTestCase;

class FullExecutionTest extends UnitTestCase
{

    function allToolsProvider()
    {
        return [
            'Code Sniffer Phpcs' => [
                Phpcs::class,
                'phpcs'
            ],
            'Code Sniffer Phpcbf' => [
                Phpcbf::class,
                'phpcbf'
            ],
            'Php Stan' => [
                Phpstan::class,
                'phpstan'
            ],
            'Php Mess Detector' => [
                Phpmd::class,
                'phpmd'
            ],
            'Php Copy Paste Detector' => [
                Phpcpd::class,
                'phpcpd'
            ],
            'Parallel-Lint' => [
                ParallelLint::class,
                'parallel-lint'
            ],
            'Composer Check-security' => [
                SecurityChecker::class,
                'security-checker'
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


        $this->assertCount(7, $loadedTools);
    }
}
