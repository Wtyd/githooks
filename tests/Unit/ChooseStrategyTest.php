<?php

namespace Tests\Unit;

use GitHooks\ChooseStrategy;
use GitHooks\LoadTools\FastStrategy;
use GitHooks\LoadTools\FullStrategy;
use GitHooks\LoadTools\SmartStrategy;
use Tests\UnitTestCase;

/**
 * It choose strategy for Githooks execution with the 'execution' tag inside 'Options'. The posibilities are:
 * full (default): if 'execution' tag not exist, have an invalid value or is 'full', full strategy is choosen.
 * smart: when 'execution' tag is 'smart'.
 * fast: when 'execution' tag is 'fast'.
 */
class ChooseStrategyTest extends UnitTestCase
{
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

        $this->assertInstanceOf(SmartStrategy::class, $strategy);
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

        $this->assertInstanceOf(FastStrategy::class, $strategy);
    }

    public function configurationFileForFullStrategyProvider()
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
     * @dataProvider configurationFileForFullStrategyProvider
     */
    function choose_full_strategy($confFile)
    {
        $chooseStrategy = new ChooseStrategy();

        $strategy = $chooseStrategy->__invoke($confFile);

        $this->assertInstanceOf(FullStrategy::class, $strategy);
    }
}
