<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Doubles\FakeProcessPool;
use Tests\Doubles\InjectableFlowExecutor;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowMemoryHandler;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Output\NullOutputHandler;

/**
 * BUG-7 tick contract of the parallel loop: the memory handler must be
 * ticked (a) while jobs are running — jobs shorter than the throttle window
 * reported peak=0 when no tick fired during their lifetime — and (b) once
 * after the loop. Kills the escaped MethodCallRemoval mutants on
 * FlowExecutor:817 (in-loop tick) and :878 (final tick), via the
 * createMemoryHandler() seam.
 */
class FlowExecutorMemoryTickTest extends UnitTestCase
{
    /** @test */
    public function parallel_loop_ticks_the_memory_handler_while_running_and_once_after(): void
    {
        $spyHandler = new class (new OptionsConfiguration(false, 2), false, microtime(true), []) extends FlowMemoryHandler {
            /** @var int[] size of the running set at each tick */
            public array $tickRunningCounts = [];

            public function tick(array $running): void
            {
                $this->tickRunningCounts[] = count($running);
            }

            public function isActive(): bool
            {
                return true;
            }

            public function shouldKill(): bool
            {
                return false;
            }
        };

        $executor = new class (new NullOutputHandler()) extends InjectableFlowExecutor {
            public ?FlowMemoryHandler $handlerToInject = null;

            protected function createMemoryHandler(OptionsConfiguration $options): FlowMemoryHandler
            {
                return $this->handlerToInject ?? parent::createMemoryHandler($options);
            }
        };
        $executor->handlerToInject = $spyHandler;

        // Two jobs so the executor takes the parallel path (a single job runs
        // sequentially and the tick contract only exists in the parallel loop).
        $jobA = new CustomJob(new JobConfiguration('short_a', 'custom', ['script' => 'unused-by-fake']));
        $jobB = new CustomJob(new JobConfiguration('short_b', 'custom', ['script' => 'unused-by-fake']));
        $pool = new FakeProcessPool(2);
        $pool->programResult('short_a', 0, 'ok');
        $pool->programResult('short_b', 0, 'ok');
        $executor->injectPool($pool);

        $executor->execute(new FlowPlan('test', [$jobA, $jobB], new OptionsConfiguration(false, 2)));

        $this->assertSame(
            [2, 0],
            $spyHandler->tickRunningCounts,
            'exactly one in-loop tick with both jobs running (BUG-7) plus the final tick after the loop'
        );
    }
}
