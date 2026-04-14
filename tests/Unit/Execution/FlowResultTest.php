<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;

class FlowResultTest extends TestCase
{
    /** @test */
    public function it_stores_execution_mode()
    {
        $result = new FlowResult('qa', [], '1s', 0, 0, 'fast');

        $this->assertEquals('fast', $result->getExecutionMode());
    }

    /** @test */
    public function it_defaults_execution_mode_to_full()
    {
        $result = new FlowResult('qa', [], '1s');

        $this->assertEquals('full', $result->getExecutionMode());
    }

    /** @test */
    public function it_counts_skipped_jobs()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
            JobResult::skipped('phpcs_src', 'phpcs', 'no staged files'),
            new JobResult('phpmd_src', false, 'error', '500ms'),
            JobResult::skipped('phpcbf_src', 'phpcbf', 'excluded'),
        ], '2s');

        $this->assertEquals(2, $result->getSkippedCount());
        $this->assertEquals(1, $result->getPassedCount());
        $this->assertEquals(1, $result->getFailedCount());
    }

    /** @test */
    public function it_returns_zero_skipped_when_none()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
        ], '1s');

        $this->assertEquals(0, $result->getSkippedCount());
    }
}
