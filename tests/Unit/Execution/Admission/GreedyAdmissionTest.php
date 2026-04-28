<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Admission;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\Admission\AdmissionContext;
use Wtyd\GitHooks\Execution\Admission\GreedyAdmission;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Jobs\PhpcsJob;

class GreedyAdmissionTest extends TestCase
{
    /** @test */
    public function picks_head_when_head_fits(): void
    {
        $queue = [$this->buildJob('first'), $this->buildJob('second')];
        $ctx = new AdmissionContext(4, null, ['first' => 1, 'second' => 1], []);

        $this->assertSame(0, (new GreedyAdmission())->pickNext($queue, $ctx));
    }

    /** @test */
    public function picks_first_fitting_job_skipping_a_blocked_head(): void
    {
        // The head needs 4 cores but only 1 is free; greedy moves on and
        // picks the lighter job behind it (REQ-018, AC-010).
        $queue = [$this->buildJob('big'), $this->buildJob('small')];
        $ctx = new AdmissionContext(
            1,
            null,
            ['big' => 4, 'small' => 1],
            []
        );

        $this->assertSame(1, (new GreedyAdmission())->pickNext($queue, $ctx));
    }

    /** @test */
    public function returns_null_when_no_job_fits(): void
    {
        $queue = [$this->buildJob('a'), $this->buildJob('b')];
        $ctx = new AdmissionContext(
            0,
            null,
            ['a' => 1, 'b' => 1],
            []
        );

        $this->assertNull((new GreedyAdmission())->pickNext($queue, $ctx));
    }

    /** @test */
    public function in_2d_mode_picks_first_job_that_fits_both_axes(): void
    {
        // First job needs 2000 MB but only 500 are free; second needs 100 MB.
        $queue = [$this->buildJob('heavy'), $this->buildJob('light')];
        $ctx = new AdmissionContext(
            10,
            500,
            ['heavy' => 2, 'light' => 1],
            ['heavy' => 2000, 'light' => 100]
        );

        $this->assertSame(1, (new GreedyAdmission())->pickNext($queue, $ctx));
    }

    /** @test */
    public function returns_null_for_empty_queue(): void
    {
        $ctx = new AdmissionContext(4, null, [], []);

        $this->assertNull((new GreedyAdmission())->pickNext([], $ctx));
    }

    private function buildJob(string $name): JobAbstract
    {
        return new PhpcsJob(new JobConfiguration($name, 'phpcs', []));
    }
}
