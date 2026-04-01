<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\PhpmdJob;

class FlowPreparerTest extends TestCase
{
    private FlowPreparer $preparer;

    protected function setUp(): void
    {
        $this->preparer = new FlowPreparer(new JobRegistry());
    }

    /** @test */
    public function it_prepares_a_flow_into_job_instances()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
            'phpcs_src'   => new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]),
        ];

        $flow = new FlowConfiguration('lint', ['phpstan_src', 'phpcs_src']);

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(false, 2),
            $jobs,
            ['lint' => $flow],
            null,
            new ValidationResult()
        );

        $plan = $this->preparer->prepare($flow, $config);

        $this->assertEquals('lint', $plan->getFlowName());
        $this->assertCount(2, $plan->getJobs());
        $this->assertInstanceOf(PhpstanJob::class, $plan->getJobs()[0]);
        $this->assertInstanceOf(PhpcsJob::class, $plan->getJobs()[1]);
        $this->assertEquals(2, $plan->getOptions()->getProcesses());
    }

    /** @test */
    public function it_uses_flow_options_over_global()
    {
        $jobs = [
            'phpcs_src' => new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]),
        ];

        $flowOptions = new OptionsConfiguration(true, 4);
        $flow = new FlowConfiguration('lint', ['phpcs_src'], $flowOptions);

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(false, 1),
            $jobs,
            ['lint' => $flow],
            null,
            new ValidationResult()
        );

        $plan = $this->preparer->prepare($flow, $config);

        $this->assertTrue($plan->getOptions()->isFailFast());
        $this->assertEquals(4, $plan->getOptions()->getProcesses());
    }

    /** @test */
    public function it_prepares_a_single_job()
    {
        $jobConfig = new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]);
        $options = new OptionsConfiguration();

        $plan = $this->preparer->prepareSingleJob($jobConfig, $options);

        $this->assertEquals('phpstan_src', $plan->getFlowName());
        $this->assertCount(1, $plan->getJobs());
        $this->assertInstanceOf(PhpstanJob::class, $plan->getJobs()[0]);
    }

    /** @test */
    public function it_skips_missing_jobs_gracefully()
    {
        $jobs = [
            'phpcs_src' => new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]),
        ];

        // Flow references a job that doesn't exist in config
        $flow = new FlowConfiguration('lint', ['phpcs_src', 'nonexistent']);

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            $jobs,
            ['lint' => $flow],
            null,
            new ValidationResult()
        );

        $plan = $this->preparer->prepare($flow, $config);

        $this->assertCount(1, $plan->getJobs());
    }

    /** @test */
    public function it_excludes_jobs_by_name()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
            'phpcs_src'   => new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]),
            'phpmd_src'   => new JobConfiguration('phpmd_src', 'phpmd', ['paths' => ['src'], 'rules' => 'codesize']),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src', 'phpcs_src', 'phpmd_src']);

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            $jobs,
            ['qa' => $flow],
            null,
            new ValidationResult()
        );

        $plan = $this->preparer->prepare($flow, $config, null, ['phpcs_src', 'phpmd_src']);

        $this->assertCount(1, $plan->getJobs());
        $this->assertInstanceOf(PhpstanJob::class, $plan->getJobs()[0]);
    }

    /** @test */
    public function it_excludes_nothing_with_empty_exclude_list()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
            'phpcs_src'   => new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src', 'phpcs_src']);

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            $jobs,
            ['qa' => $flow],
            null,
            new ValidationResult()
        );

        $plan = $this->preparer->prepare($flow, $config, null, []);

        $this->assertCount(2, $plan->getJobs());
    }

    /** @test */
    public function it_ignores_exclude_names_that_dont_exist()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src']);

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            $jobs,
            ['qa' => $flow],
            null,
            new ValidationResult()
        );

        $plan = $this->preparer->prepare($flow, $config, null, ['nonexistent']);

        $this->assertCount(1, $plan->getJobs());
    }
}
