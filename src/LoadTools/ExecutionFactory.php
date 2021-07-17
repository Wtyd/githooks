<?php

namespace Wtyd\GitHooks\LoadTools;

use Illuminate\Container\Container;

class ExecutionFactory
{

    /**
     * @param string $executionMode The execution mode: full (default) or fast
     *
     * @return ExecutionMode
     */
    public function __invoke(string $executionMode): ExecutionMode
    {
        $container =  Container::getInstance();
        if (!empty($executionMode)) {
            switch ($executionMode) {
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
