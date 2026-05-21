<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\ProcessPool;

/**
 * Test-only `FlowExecutor` subclass that lets a test inject a pre-built
 * `ProcessPool` (typically a `FakeProcessPool`) instead of letting the
 * executor build a real one from the plan options. Production code never
 * sees this class; production keeps the protected `buildProcessPool` seam
 * intact.
 */
class InjectableFlowExecutor extends FlowExecutor
{
    private ?ProcessPool $injectedPool = null;

    public function injectPool(ProcessPool $pool): void
    {
        $this->injectedPool = $pool;
    }

    protected function buildProcessPool(
        int $maxProcesses,
        int $coresBudget,
        array $jobs,
        OptionsConfiguration $options
    ): ProcessPool {
        return $this->injectedPool
            ?? parent::buildProcessPool($maxProcesses, $coresBudget, $jobs, $options);
    }
}
