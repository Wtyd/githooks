<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ThreadCapability;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\ParatestJob;

/**
 * Paratest is the parallel driver for PHPUnit. It wraps the same CLI and
 * adds `--processes=N` to control worker count.
 */
class ParatestJobTest extends TestCase
{
    /** @test */
    public function paratest_is_a_supported_job_type()
    {
        $registry = new JobRegistry();

        $this->assertTrue($registry->isSupported('paratest'));
    }

    /** @test */
    public function default_executable_is_paratest()
    {
        $this->assertSame('paratest', ParatestJob::getDefaultExecutable());
    }

    /** @test */
    public function thread_capability_defaults_to_four_and_is_controllable()
    {
        $job = new ParatestJob(new JobConfiguration('paratest_all', 'paratest', []));

        $cap = $job->getThreadCapability();

        $this->assertInstanceOf(ThreadCapability::class, $cap);
        $this->assertSame('processes', $cap->getArgumentKey());
        $this->assertSame(4, $cap->getDefaultThreads());
        $this->assertTrue($cap->isControllable());
    }

    /** @test */
    public function thread_capability_reads_processes_value_as_integer()
    {
        $job = new ParatestJob(new JobConfiguration('paratest_all', 'paratest', [
            'processes' => '6',
        ]));

        $this->assertSame(6, $job->getThreadCapability()->getDefaultThreads());
    }

    /** @test */
    public function apply_thread_limit_propagates_value_into_command()
    {
        $job = new ParatestJob(new JobConfiguration('paratest_all', 'paratest', [
            'executablePath' => 'vendor/bin/paratest',
        ]));

        $job->applyThreadLimit(8);

        $this->assertStringContainsString('--processes=8', $job->buildCommand());
    }

    /** @test */
    public function apply_thread_limit_overrides_existing_processes_value()
    {
        $job = new ParatestJob(new JobConfiguration('paratest_all', 'paratest', [
            'executablePath' => 'vendor/bin/paratest',
            'processes'      => 2,
        ]));

        $job->applyThreadLimit(4);

        $command = $job->buildCommand();

        $this->assertStringContainsString('--processes=4', $command);
        $this->assertStringNotContainsString('--processes=2', $command);
    }

    /** @test */
    public function inherits_phpunit_argument_map_for_filter_and_configuration()
    {
        $job = new ParatestJob(new JobConfiguration('paratest_all', 'paratest', [
            'executablePath' => 'vendor/bin/paratest',
            'configuration'  => 'phpunit.xml',
            'filter'         => 'testFoo',
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('-c phpunit.xml', $command);
        $this->assertStringContainsString('--filter testFoo', $command);
    }

    /** @test */
    public function cache_paths_inherit_phpunit_result_cache()
    {
        $job = new ParatestJob(new JobConfiguration('paratest_all', 'paratest', []));

        $this->assertSame(['.phpunit.result.cache'], $job->getCachePaths());
    }
}
