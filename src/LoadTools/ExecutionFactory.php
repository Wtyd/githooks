<?php

namespace Wtyd\GitHooks\LoadTools;

use Illuminate\Container\Container;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\Constants;

class ExecutionFactory
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
    public function __invoke(string $execution): ExecutionMode
    {
        $container =  Container::getInstance();
        if (!empty($execution)) {
            switch ($execution) {
                case ExecutionMode::SMART_EXECUTION:
                    $strategy = $container->make(SmartExecution::class);
                    break;
                case ExecutionMode::FAST_EXECUTION:
                    $strategy = $container->make(FastExecution::class);
                    break;
                default:
                    $strategy = $container->make(FullExecution::class);
                    break;
            }
        } else {
            $strategy = $container->make(FullExecution::class);
        }

        return $strategy;
    }
}
