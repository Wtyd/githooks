<?php

use GitHooks\ChooseStrategy;
use GitHooks\LoadTools\FullStrategy;
use GitHooks\LoadTools\SmartStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Tengo que ponerle a los tests la anotacion runInSeparateProcess para que cuando en los tests de integración use la clase original no me de error tras ejecutar estos tests
 */
class ChooseStrategyTest extends TestCase
{
    /** @test*/
    function choose_smart_strategy_when_smart_strategy_is_true_in_Options_section()
    {
        $chooseStrategy = new ChooseStrategy();

        $confFile = [
            'Options' => [
                'smartExecution' => true,
                'OtraOpcion' => null,
            ],
            'Otro tag' => [],
        ];
        $strategy = $chooseStrategy->__invoke($confFile);

        $this->assertInstanceOf(SmartStrategy::class, $strategy);
    }

    public function configurationFileForFullStrategyProvider()
    {
        return [
            'Smartstrategy está fuera de Options' => [
                [
                    'Options' => [
                        'OtraOpcion' => null,
                    ],
                    'smartExecution' => true,
                ],
            ],
            'Smartstrategy es false' => [
                [
                    'Options' => [
                        'smartExecution' => false,
                        'OtraOpcion' => null,
                    ],
                ],
            ],
            'Smartstrategy es entero' => [
                [
                    'Options' => [
                        'smartExecution' => 12,
                        'OtraOpcion' => null,
                    ],
                ],
            ],
            'Smartstrategy es cadena' => [
                [
                    'Options' => [
                        'smartExecution' => 'true',
                        'OtraOpcion' => null,
                    ],
                ],
            ],
            'Smartstrategy no está' => [
                [
                    'Options' => [
                        'OtraOpcion' => null,
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
