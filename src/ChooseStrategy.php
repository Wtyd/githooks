<?php

namespace Wtyd\GitHooks;

use Wtyd\GitHooks\LoadTools\FastStrategy;
use Wtyd\GitHooks\LoadTools\FullStrategy;
use Wtyd\GitHooks\LoadTools\SmartStrategy;
use Wtyd\GitHooks\LoadTools\StrategyInterface;
use Illuminate\Container\Container;

class ChooseStrategy
{

    /**
     * Crea y devuelve la estrategia configurada en el fichero de configuración.
     * Por defecto, exista o no la que EXECUTION, se ejecuta la FullStrategy.
     * Si existe la key EXECUTION, se crea la SmartStrategy en caso de que su valor sea 'smart' o la FastStrategy si su valor es 'fast'. En cualquier otro caso
     * se crea la FullStrategy.
     *
     * @param array $file. Fichero de configuración.
     * @return StrategyInterface
     */
    public function __invoke(array $file): StrategyInterface
    {
        $container =  Container::getInstance();
        // dd($container->getBindings());
        if (!empty($file[Constants::OPTIONS][Constants::EXECUTION])) {
            switch ($file[Constants::OPTIONS][Constants::EXECUTION]) {
                case Constants::SMART_EXECUTION:
                    $strategy = $container->makeWith(SmartStrategy::class, ['configurationFile' => $file]);
                    break;
                case Constants::FAST_EXECUTION:
                    $strategy = $container->makeWith(FastStrategy::class, ['configurationFile' => $file]);
                    break;
                default:
                    $strategy = $container->makeWith(FullStrategy::class, ['configurationFile' => $file]);
                    break;
            }
        } else {
            $strategy = $container->makeWith(FullStrategy::class, ['configurationFile' => $file]);
        }

        return $strategy;
    }
}
