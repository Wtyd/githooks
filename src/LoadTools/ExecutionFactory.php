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
        // FIXME Las executions no necesitarán recibir el fichero de configuración en el constructor
        $container =  Container::getInstance();
        // dd($container->getBindings());
        if (!empty($execution)) {
            switch ($execution) {
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
