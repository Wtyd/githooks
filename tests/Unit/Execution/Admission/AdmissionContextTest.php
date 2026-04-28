<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Admission;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\Admission\AdmissionContext;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Configuration\JobConfiguration;

class AdmissionContextTest extends TestCase
{
    /** @test */
    public function fits_passes_when_cores_available_and_no_memory_budget(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(
            4,
            null,
            ['phpstan' => 2],
            ['phpstan' => 2000]
        );

        $this->assertTrue($ctx->fits($job));
    }

    /** @test */
    public function fits_fails_when_cores_exceed_free_pool(): void
    {
        $job = $this->buildJob('phpunit');
        $ctx = new AdmissionContext(
            1,
            null,
            ['phpunit' => 4],
            ['phpunit' => null]
        );

        $this->assertFalse($ctx->fits($job));
    }

    /** @test */
    public function fits_passes_in_2d_when_both_axes_have_room(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(
            6,
            2500,
            ['phpstan' => 2],
            ['phpstan' => 2000]
        );

        $this->assertTrue($ctx->fits($job));
    }

    /** @test */
    public function fits_fails_in_2d_when_memory_runs_out(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(
            10,
            500,
            ['phpstan' => 2],
            ['phpstan' => 2000]
        );

        $this->assertFalse($ctx->fits($job));
    }

    /** @test */
    public function fits_fails_in_2d_when_cores_run_out_even_if_memory_fits(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(
            1,
            5000,
            ['phpstan' => 4],
            ['phpstan' => 2000]
        );

        $this->assertFalse($ctx->fits($job));
    }

    /** @test */
    public function unknown_job_defaults_to_one_core_and_zero_memory(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(1, 100, [], []);

        $this->assertTrue($ctx->fits($job));
    }

    /** @test */
    public function in_1d_mode_memory_reservation_is_ignored_even_if_declared(): void
    {
        $job = $this->buildJob('phpstan');
        $ctx = new AdmissionContext(
            10,
            null,
            ['phpstan' => 2],
            ['phpstan' => 999999]
        );

        $this->assertTrue($ctx->fits($job));
    }

    private function buildJob(string $name): JobAbstract
    {
        $config = new JobConfiguration($name, 'phpcs', []);
        return new PhpcsJob($config);
    }
}
