<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\FlowPlan;

class FlowPlanTest extends TestCase
{
    /** @test */
    public function it_stores_the_execution_mode()
    {
        $plan = new FlowPlan('qa', [], new OptionsConfiguration(), null, [], ExecutionMode::FAST);

        $this->assertSame(ExecutionMode::FAST, $plan->getExecutionMode());
    }

    /** @test */
    public function it_defaults_execution_mode_to_full()
    {
        $plan = new FlowPlan('qa', [], new OptionsConfiguration());

        $this->assertSame(ExecutionMode::FULL, $plan->getExecutionMode());
    }

    /** @test */
    public function it_stores_fast_branch_mode()
    {
        $plan = new FlowPlan('qa', [], new OptionsConfiguration(), null, [], ExecutionMode::FAST_BRANCH);

        $this->assertSame(ExecutionMode::FAST_BRANCH, $plan->getExecutionMode());
    }
}
