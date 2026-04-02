<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\GitStagerFake;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\PhpcbfJob;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Output\NullOutputHandler;

class FlowExecutorRestageTest extends TestCase
{
    /** @test */
    public function it_restages_files_when_a_job_applies_a_fix()
    {
        $stager = new GitStagerFake();
        $executor = new FlowExecutor(new NullOutputHandler(), $stager);

        // phpcbf with exit 1 = fix applied. Use a script that exits with 1.
        $job = new PhpcbfJob(new JobConfiguration('phpcbf_test', 'phpcbf', [
            'executablePath' => 'exit 1 && echo',
            'paths' => ['/dev/null'],
        ]));

        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());
        $executor->execute($plan);

        $this->assertTrue($stager->wasCalled(), 'GitStager should be called when fix is applied');
    }

    /** @test */
    public function it_does_not_restage_when_no_fix_applied()
    {
        $stager = new GitStagerFake();
        $executor = new FlowExecutor(new NullOutputHandler(), $stager);

        $job = new CustomJob(new JobConfiguration('ok_job', 'custom', [
            'script' => 'echo ok',
        ]));

        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());
        $executor->execute($plan);

        $this->assertFalse($stager->wasCalled(), 'GitStager should NOT be called when no fix applied');
    }

    /** @test */
    public function it_does_not_restage_when_no_stager_injected()
    {
        // FlowExecutor without GitStager (null) should not crash
        $executor = new FlowExecutor(new NullOutputHandler(), null);

        $job = new PhpcbfJob(new JobConfiguration('phpcbf_test', 'phpcbf', [
            'executablePath' => 'exit 1 && echo',
            'paths' => ['/dev/null'],
        ]));

        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());
        $result = $executor->execute($plan);

        // Should not crash, job should still be treated as success (fix applied)
        $this->assertTrue($result->isSuccess());
    }
}
