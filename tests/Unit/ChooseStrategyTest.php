<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Wtyd\GitHooks\ChooseStrategy;
use Wtyd\GitHooks\LoadTools\ExecutionMode;
use Wtyd\GitHooks\LoadTools\FullExecution;
use Wtyd\GitHooks\LoadTools\SmartExecution;
use Tests\UnitTestCase;
use Wtyd\GitHooks\Container\RegisterBindings;

/**
 * It choose strategy for Githooks execution with the 'execution' tag inside 'Options'. The posibilities are:
 * full (default): if 'execution' tag not exist, have an invalid value or is 'full', full strategy is choosen.
 * smart: when 'execution' tag is 'smart'.
 * fast: when 'execution' tag is 'fast'.
 */
class ChooseStrategyTest extends UnitTestCase
{
    public function setUp(): void
    {
        $this->registerBindings();
    }

    /** @test*/
    function choose_smart_strategy_in_Options_section()
    {
        $chooseStrategy = new ChooseStrategy();

        $confFile = [
            'Options' => [
                'execution' => 'smart',
                'AnotherOption' => null,
            ],
            'Otro tag' => [],
        ];
        $strategy = $chooseStrategy->__invoke($confFile);

        $this->assertInstanceOf(SmartExecution::class, $strategy);
    }

    /** @test*/
    function choose_fast_strategy_in_Options_section()
    {
        $chooseStrategy = new ChooseStrategy();

        $confFile = [
            'Options' => [
                'execution' => 'fast',
                'AnotherOption' => null,
            ],
            'Otro tag' => [],
        ];
        $strategy = $chooseStrategy->__invoke($confFile);

        $this->assertInstanceOf(ExecutionMode::class, $strategy);
    }

    public function configurationFileForFullExecutionProvider()
    {
        return [
            'Explicit "full" strategy' => [
                [
                    'Options' => [
                        'execution' => 'full',
                        'AnotherOption' => null,
                    ],
                ],
            ],
            '"execution" tag with another strategy is out of Options' => [
                [
                    'Options' => [
                        'AnotherOption' => null,
                    ],
                    'execution' => 'fast',
                ],
            ],
            '"execution" tag is false' => [
                [
                    'Options' => [
                        'execution' => false,
                        'AnotherOption' => null,
                    ],
                ],
            ],
            '"execution" tag is integer' => [
                [
                    'Options' => [
                        'execution' => 12,
                        'AnotherOption' => null,
                    ],
                ],
            ],
            '"execution" tag is an invalid strategy' => [
                [
                    'Options' => [
                        'execution' => 'invalid-execution',
                        'AnotherOption' => null,
                    ],
                ],
            ],
            '"execution" tag does not existe' => [
                [
                    'Options' => [
                        'AnotherOption' => null,
                    ],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider configurationFileForFullExecutionProvider
     */
    function choose_full_strategy($confFile)
    {
        $chooseStrategy = new ChooseStrategy();

        $strategy = $chooseStrategy->__invoke($confFile);

        $this->assertInstanceOf(FullExecution::class, $strategy);
    }
}
