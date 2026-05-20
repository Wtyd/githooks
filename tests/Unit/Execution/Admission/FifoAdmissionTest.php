<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Admission;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\Admission\AdmissionContext;
use Wtyd\GitHooks\Execution\Admission\FifoAdmission;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Jobs\PhpcsJob;

class FifoAdmissionTest extends TestCase
{
    /** @test */
    public function picks_the_head_when_it_fits(): void
    {
        $queue = [$this->buildJob('first'), $this->buildJob('second')];
        $ctx = new AdmissionContext(4, null, ['first' => 1, 'second' => 1], []);

        $this->assertSame(0, (new FifoAdmission())->pickNext($queue, $ctx));
    }

    /** @test */
    public function returns_null_when_head_does_not_fit_even_if_others_would(): void
    {
        // Strict FIFO: blocks the entire queue when the head is too heavy.
        $queue = [$this->buildJob('big'), $this->buildJob('small')];
        $ctx = new AdmissionContext(
            1,
            null,
            ['big' => 4, 'small' => 1],
            []
        );

        $this->assertNull((new FifoAdmission())->pickNext($queue, $ctx));
    }

    /** @test */
    public function returns_null_when_queue_is_empty(): void
    {
        $ctx = new AdmissionContext(4, null, [], []);

        $this->assertNull((new FifoAdmission())->pickNext([], $ctx));
    }

    /** @test */
    public function in_2d_mode_blocks_when_head_exceeds_memory(): void
    {
        $queue = [$this->buildJob('heavy'), $this->buildJob('light')];
        $ctx = new AdmissionContext(
            10,
            500,
            ['heavy' => 2, 'light' => 1],
            ['heavy' => 2000, 'light' => 100]
        );

        $this->assertNull((new FifoAdmission())->pickNext($queue, $ctx));
    }

    // ========================================================================
    // FEAT-3 · gate by `needs`
    // ========================================================================

    /** @test */
    public function blocks_head_when_needs_not_completed(): void
    {
        $queue = [$this->buildJob('eslint'), $this->buildJob('phpstan')];
        $ctx = new AdmissionContext(
            10,
            null,
            [],
            [],
            ['eslint' => ['yarn-install']],
            []  // yarn-install not yet completed
        );

        // FIFO blocks the head; phpstan must NOT be admitted out of order.
        $this->assertNull((new FifoAdmission())->pickNext($queue, $ctx));
    }

    /** @test */
    public function admits_head_once_needs_completed(): void
    {
        $queue = [$this->buildJob('eslint')];
        $ctx = new AdmissionContext(
            10,
            null,
            [],
            [],
            ['eslint' => ['yarn-install']],
            ['yarn-install']
        );

        $this->assertSame(0, (new FifoAdmission())->pickNext($queue, $ctx));
    }

    private function buildJob(string $name): JobAbstract
    {
        return new PhpcsJob(new JobConfiguration($name, 'phpcs', []));
    }
}
