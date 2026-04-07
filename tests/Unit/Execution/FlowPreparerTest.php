<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\PhpmdJob;
use Tests\Doubles\FileUtilsFake;

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

    /** @test */
    public function it_filters_paths_for_accelerable_jobs_in_fast_mode()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src']);
        $validation = new ValidationResult();

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            $jobs,
            ['qa' => $flow],
            null,
            $validation
        );

        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php', 'tests/FooTest.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php']);

        $context = ExecutionContext::forFastMode($fileUtils);
        $plan = $this->preparer->prepare($flow, $config, $context);

        $this->assertCount(1, $plan->getJobs());
        // The command should reference the filtered file, not the original directory
        $command = $plan->getJobs()[0]->buildCommand();
        $this->assertEquals('vendor/bin/phpstan analyse src/Foo.php', $command);
    }

    /** @test */
    public function it_skips_accelerable_jobs_when_no_staged_files_match()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src']);
        $validation = new ValidationResult();

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            $jobs,
            ['qa' => $flow],
            null,
            $validation
        );

        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['tests/FooTest.php']); // no files in src/

        $context = ExecutionContext::forFastMode($fileUtils);
        $plan = $this->preparer->prepare($flow, $config, $context);

        $this->assertCount(0, $plan->getJobs());
        $this->assertNotEmpty($validation->getWarnings());
        $this->assertStringContainsString('skipped', $validation->getWarnings()[0]);
    }

    /** @test */
    public function it_does_not_filter_non_accelerable_jobs_in_fast_mode()
    {
        $jobs = [
            'phpunit_tests' => new JobConfiguration('phpunit_tests', 'phpunit', [
                'configuration' => 'phpunit.xml',
            ]),
        ];

        $flow = new FlowConfiguration('qa', ['phpunit_tests']);
        $validation = new ValidationResult();

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            $jobs,
            ['qa' => $flow],
            null,
            $validation
        );

        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php']);

        $context = ExecutionContext::forFastMode($fileUtils);
        $plan = $this->preparer->prepare($flow, $config, $context);

        // phpunit is not accelerable, so it runs normally
        $this->assertCount(1, $plan->getJobs());
    }

    /** @test */
    public function it_filters_paths_in_prepare_single_job_fast_mode()
    {
        $jobConfig = new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]);

        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php', 'tests/FooTest.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php']);

        $context = ExecutionContext::forFastMode($fileUtils);
        $plan = $this->preparer->prepareSingleJob($jobConfig, new OptionsConfiguration(), $context);

        $this->assertCount(1, $plan->getJobs());
        $command = $plan->getJobs()[0]->buildCommand();
        $this->assertEquals('vendor/bin/phpstan analyse src/Foo.php', $command);
    }

    /** @test */
    public function it_only_passes_files_within_job_paths_not_other_directories()
    {
        $jobs = [
            'phpmd_src' => new JobConfiguration('phpmd_src', 'phpmd', [
                'paths' => ['src'],
                'rules' => 'unusedcode',
            ]),
        ];

        $flow = new FlowConfiguration('qa', ['phpmd_src']);
        $validation = new ValidationResult();

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            $jobs,
            ['qa' => $flow],
            null,
            $validation
        );

        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles([
            'src/Service.php',
            'database/migration.php',
            'config/app.php',
        ]);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Service.php']);

        $context = ExecutionContext::forFastMode($fileUtils);
        $plan = $this->preparer->prepare($flow, $config, $context);

        $this->assertCount(1, $plan->getJobs());
        $command = $plan->getJobs()[0]->buildCommand();
        // phpmd only receives src/Service.php, not database/ or config/ files
        $this->assertStringContainsString('src/Service.php', $command);
        $this->assertStringNotContainsString('database', $command);
        $this->assertStringNotContainsString('config', $command);
    }

    /** @test */
    public function it_respects_explicit_accelerable_false_override()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'paths' => ['src'],
                'accelerable' => false,
            ]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src']);
        $validation = new ValidationResult();

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            $jobs,
            ['qa' => $flow],
            null,
            $validation
        );

        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['tests/FooTest.php']); // no match in src/

        $context = ExecutionContext::forFastMode($fileUtils);
        $plan = $this->preparer->prepare($flow, $config, $context);

        // accelerable=false overrides SUPPORTS_FAST=true, so phpstan runs with full paths
        $this->assertCount(1, $plan->getJobs());
        $this->assertStringContainsString('src', $plan->getJobs()[0]->buildCommand());
    }
}
