<?php

namespace Wtyd\GitHooks;

use Wtyd\GitHooks\LoadTools\FastExecution;
use Wtyd\GitHooks\LoadTools\FullExecution;
use Wtyd\GitHooks\LoadTools\SmartExecution;
use Wtyd\GitHooks\LoadTools\ExecutionMode;
use Illuminate\Container\Container;

class ChooseStrategy
{

    /**
     * Crea y devuelve la estrategia configurada en el fichero de configuración.
     * Por defecto, exista o no la que EXECUTION, se ejecuta la FullExecution.
     * Si existe la key EXECUTION, se crea la SmartExecution en caso de que su valor sea 'smart' o la FastStrategy si su valor es 'fast'. En cualquier otro caso
     * se crea la FullExecution.
     *
     * @param array $file. Fichero de configuración.
     * @return ExecutionMode
     */
    public function __invoke(array $file): ExecutionMode
    {
        $container =  Container::getInstance();
        // dd($container->getBindings());
        if (!empty($file[Constants::OPTIONS][Constants::EXECUTION])) {
            switch ($file[Constants::OPTIONS][Constants::EXECUTION]) {
                case ExecutionMode::SMART_EXECUTION:
                    $strategy = $container->makeWith(SmartExecution::class, ['configurationFile' => $file]);
                    break;
                case ExecutionMode::FAST_EXECUTION:
                    $strategy = $container->makeWith(FastExecution::class, ['configurationFile' => $file]);
                    break;
                default:
                    $strategy = $container->makeWith(FullExecution::class, ['configurationFile' => $file]);
                    break;
            }
        } else {
            $strategy = $container->makeWith(FullExecution::class, ['configurationFile' => $file]);
        }

        return $strategy;
    }
}
