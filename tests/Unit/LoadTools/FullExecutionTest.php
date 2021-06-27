<?php

namespace Tests\Unit\LoadTools;

use Wtyd\GitHooks\LoadTools\Exception\ToolDoesNotExistException;
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

class FullExecutionTest extends TestCase
{

    function allToolsProvider()
    {
        return [
            'Php Code Sniffer' => [
                [
                    'Tools' => ['phpcs'],
                ],
                CodeSniffer::class,
                'phpcs'
            ],
            'Php Stan' => [
                [
                    'Tools' => ['phpstan'],
                ],
                Stan::class,
                'phpstan'
            ],
            'Php Mess Detector' => [
                [
                    'Tools' => ['phpmd'],
                ],
                MessDetector::class,
                'phpmd'
            ],
            'Php Copy Paste Detector' => [
                [
                    'Tools' => ['phpcpd'],
                ],
                CopyPasteDetector::class,
                'phpcpd'
            ],
            'Parallel-Lint' => [
                [
                    'Tools' => ['parallel-lint'],
                ],
                ParallelLint::class,
                'parallel-lint'
            ],
            'Composer Check-security' => [
                [
                    'Tools' => ['check-security'],
                ],
                CheckSecurity::class,
                'check-security'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_can_load_one_tool($configurationFile, $toolClass, $tool)
    {
        $FullExecution = new FullExecution($configurationFile, new ToolsFactoy());

        $loadedTools = $FullExecution->getTools();

        $this->assertCount(1, $loadedTools);

        $this->assertInstanceOf($toolClass, $loadedTools[$tool]);
    }

    /** @test*/
    function it_can_load_every_tools()
    {
        $configurationFile = [
            'Options' => [
                'OtraOpcion' => null,
            ],
            'Tools' => [
                'phpcs',
                'phpstan',
                'phpmd',
                'phpcpd',
                'parallel-lint',
                'check-security'
            ],
        ];

        $FullExecution = new FullExecution($configurationFile, new ToolsFactoy());

        $loadedTools = $FullExecution->getTools();

        $this->assertCount(6, $loadedTools);
    }


    function toolsWithToolThatDoesNotExistProvider()
    {
        return [
            'Únicamente una herramienta que no existe' => [
                [
                    'Options' => [
                        'execution' => 'full',
                        'OtraOpcion' => null,
                    ],
                    'Tools' => [
                        'herramientaInventada',
                    ],
                ],
            ],
            'Una herramienta que existe y una que NO existe' => [
                [
                    'Options' => [
                        'execution' => 'full',
                        'OtraOpcion' => null,
                    ],
                    'Tools' => [
                        'phpcs',
                        'herramientaInventada',
                    ],
                ],
            ],
            'Una herramienta que NO existe y una que  existe' => [
                [
                    'Options' => [
                        'execution' => 'full',
                        'OtraOpcion' => null,
                    ],
                    'Tools' => [
                        'herramientaInventada',
                        'phpcs',
                    ],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider toolsWithToolThatDoesNotExistProvider
     */
    function it_raise_exception_when_try_to_load_a_tool_that_does_not_exist($configurationFile)
    {
        $FullExecution = new FullExecution($configurationFile, new ToolsFactoy());

        $this->expectException(ToolDoesNotExistException::class);

        $FullExecution->getTools();
    }
}
