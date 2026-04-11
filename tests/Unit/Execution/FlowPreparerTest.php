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
use Wtyd\GitHooks\Execution\ExecutionMode;
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

    // ========================================================================
    // Execution mode resolution hierarchy tests
    // (TDD — will fail until FlowPreparer accepts invocationMode parameter)
    // ========================================================================

    /** @test */
    public function invocation_mode_overrides_job_and_flow_execution()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'paths'     => ['src'],
                'execution' => 'full', // job says full
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
        $fileUtils->setModifiedfiles(['src/Foo.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php']);

        $context = ExecutionContext::create($fileUtils, 'master');

        // invocationMode='fast' should override job's execution='full'
        $plan = $this->preparer->prepare($flow, $config, $context, [], [], ExecutionMode::FAST);

        $this->assertCount(1, $plan->getJobs());
        $command = $plan->getJobs()[0]->buildCommand();
        $this->assertStringContainsString('src/Foo.php', $command);
    }

    /** @test */
    public function job_execution_overrides_flow_execution_when_no_invocation()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'paths'     => ['src'],
                'execution' => 'full', // job says full
            ]),
        ];

        // Flow with execution='fast' (once FlowConfiguration supports it)
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

        $context = ExecutionContext::create($fileUtils, 'master');

        // No invocation mode. Job says full → runs with full paths even if no staged files match
        $plan = $this->preparer->prepare($flow, $config, $context);

        $this->assertCount(1, $plan->getJobs());
        $this->assertStringContainsString('src', $plan->getJobs()[0]->buildCommand());
    }

    /** @test */
    public function default_mode_is_full_when_nothing_configured()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'paths' => ['src'],
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

        // No context, no invocation mode, no execution in config → full mode
        $plan = $this->preparer->prepare($flow, $config);

        $this->assertCount(1, $plan->getJobs());
        $this->assertStringContainsString('src', $plan->getJobs()[0]->buildCommand());
    }

    /** @test */
    public function mixed_execution_modes_in_same_flow()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'paths'     => ['src'],
                'execution' => 'fast',
            ]),
            'phpcs_src' => new JobConfiguration('phpcs_src', 'phpcs', [
                'paths'     => ['src'],
                'execution' => 'full',
            ]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src', 'phpcs_src']);
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

        $context = ExecutionContext::create($fileUtils, 'master');
        $plan = $this->preparer->prepare($flow, $config, $context);

        // phpstan_src (fast): no staged files in src/ → skipped
        // phpcs_src (full): runs with full paths
        $this->assertCount(1, $plan->getJobs());
        $this->assertInstanceOf(PhpcsJob::class, $plan->getJobs()[0]);
    }

    /** @test */
    public function fast_branch_mode_uses_branch_diff_files()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'paths'     => ['src'],
                'execution' => 'fast-branch',
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
        $fileUtils->setModifiedfiles([]); // nothing staged
        $fileUtils->setBranchDiffFiles(['src/OldChange.php']); // branch has changes
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/OldChange.php']);

        $context = ExecutionContext::create($fileUtils, 'master');
        $plan = $this->preparer->prepare($flow, $config, $context);

        $this->assertCount(1, $plan->getJobs());
        $this->assertStringContainsString('src/OldChange.php', $plan->getJobs()[0]->buildCommand());
    }

    /** @test */
    public function fast_branch_fallback_to_full_when_diff_fails()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'paths'     => ['src'],
                'execution' => 'fast-branch',
            ]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src']);
        $validation = new ValidationResult();

        $fallbackValidation = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['fast-branch-fallback' => 'full'], $fallbackValidation);

        $config = new ConfigurationResult(
            'githooks.php',
            $options,
            $jobs,
            ['qa' => $flow],
            null,
            $validation
        );

        $fileUtils = new FileUtilsFake();
        $fileUtils->setBranchDiffFiles(null); // diff failed

        $context = ExecutionContext::create($fileUtils, 'master');
        $plan = $this->preparer->prepare($flow, $config, $context);

        // Fallback to full → job runs with original paths
        $this->assertCount(1, $plan->getJobs());
        $this->assertStringContainsString('src', $plan->getJobs()[0]->buildCommand());
    }

    /** @test */
    public function fast_branch_fallback_to_fast_uses_staged_files()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'paths'     => ['src'],
                'execution' => 'fast-branch',
            ]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src']);
        $validation = new ValidationResult();

        $fallbackValidation = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['fast-branch-fallback' => 'fast'], $fallbackValidation);

        $config = new ConfigurationResult(
            'githooks.php',
            $options,
            $jobs,
            ['qa' => $flow],
            null,
            $validation
        );

        $fileUtils = new FileUtilsFake();
        $fileUtils->setBranchDiffFiles(null); // diff failed
        $fileUtils->setModifiedfiles(['src/Staged.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Staged.php']);

        $context = ExecutionContext::create($fileUtils, 'master');
        $plan = $this->preparer->prepare($flow, $config, $context);

        // Fallback to fast → uses staged files
        $this->assertCount(1, $plan->getJobs());
        $this->assertStringContainsString('src/Staged.php', $plan->getJobs()[0]->buildCommand());
    }

    /** @test */
    public function fast_branch_fallback_to_fast_skips_job_when_no_staged_match()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'paths'     => ['src'],
                'execution' => 'fast-branch',
            ]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src']);
        $validation = new ValidationResult();

        $fallbackValidation = new ValidationResult();
        $options = OptionsConfiguration::fromArray(['fast-branch-fallback' => 'fast'], $fallbackValidation);

        $config = new ConfigurationResult(
            'githooks.php',
            $options,
            $jobs,
            ['qa' => $flow],
            null,
            $validation
        );

        $fileUtils = new FileUtilsFake();
        $fileUtils->setBranchDiffFiles(null); // diff failed
        $fileUtils->setModifiedfiles(['tests/FooTest.php']); // no files in src/

        $context = ExecutionContext::create($fileUtils, 'master');
        $plan = $this->preparer->prepare($flow, $config, $context);

        // Fallback to fast, but no staged files in src/ → skipped
        $this->assertCount(0, $plan->getJobs());
        $this->assertNotEmpty($validation->getWarnings());
    }

    /** @test */
    public function non_accelerable_job_runs_full_regardless_of_execution_mode()
    {
        $jobs = [
            'phpunit_tests' => new JobConfiguration('phpunit_tests', 'phpunit', [
                'configuration' => 'phpunit.xml',
                'execution'     => 'fast',
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

        $context = ExecutionContext::create($fileUtils, 'master');
        $plan = $this->preparer->prepare($flow, $config, $context);

        // phpunit is not accelerable, runs regardless of execution mode
        $this->assertCount(1, $plan->getJobs());
    }

    /** @test */
    public function single_job_respects_invocation_mode()
    {
        $jobConfig = new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]);

        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php']);

        $context = ExecutionContext::create($fileUtils, 'master');
        $plan = $this->preparer->prepareSingleJob($jobConfig, new OptionsConfiguration(), $context, ExecutionMode::FAST);

        $this->assertCount(1, $plan->getJobs());
        $command = $plan->getJobs()[0]->buildCommand();
        $this->assertStringContainsString('src/Foo.php', $command);
    }

    // ========================================================================
    // executable-prefix resolution
    // ========================================================================

    /** @test */
    public function it_applies_global_executable_prefix_to_all_jobs()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'executablePath' => 'vendor/bin/phpstan',
                'paths' => ['src'],
            ]),
            'phpcs_src' => new JobConfiguration('phpcs_src', 'phpcs', [
                'executablePath' => 'vendor/bin/phpcs',
                'paths' => ['src'],
            ]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src', 'phpcs_src']);

        $options = new OptionsConfiguration(false, 1, null, 'full', 'docker exec -i app');
        $config = new ConfigurationResult(
            'githooks.php',
            $options,
            $jobs,
            ['qa' => $flow],
            null,
            new ValidationResult()
        );

        $plan = $this->preparer->prepare($flow, $config);

        $this->assertCount(2, $plan->getJobs());
        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpstan', $plan->getJobs()[0]->buildCommand());
        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpcs', $plan->getJobs()[1]->buildCommand());
    }

    /** @test */
    public function it_applies_flow_executable_prefix_over_global()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'executablePath' => 'vendor/bin/phpstan',
                'paths' => ['src'],
            ]),
        ];

        $flowOptions = new OptionsConfiguration(false, 1, null, 'full', 'flow-prefix');
        $flow = new FlowConfiguration('qa', ['phpstan_src'], $flowOptions);

        $globalOptions = new OptionsConfiguration(false, 1, null, 'full', 'global-prefix');
        $config = new ConfigurationResult(
            'githooks.php',
            $globalOptions,
            $jobs,
            ['qa' => $flow],
            null,
            new ValidationResult()
        );

        $plan = $this->preparer->prepare($flow, $config);

        $this->assertStringStartsWith('flow-prefix vendor/bin/phpstan', $plan->getJobs()[0]->buildCommand());
    }

    /** @test */
    public function it_does_not_apply_prefix_when_option_is_empty()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'executablePath' => 'vendor/bin/phpstan',
                'paths' => ['src'],
            ]),
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

        $plan = $this->preparer->prepare($flow, $config);

        $this->assertStringStartsWith('vendor/bin/phpstan', $plan->getJobs()[0]->buildCommand());
    }

    /** @test */
    public function it_respects_per_job_executable_prefix_override()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'executablePath' => 'vendor/bin/phpstan',
                'paths' => ['src'],
            ]),
            'lint_js' => new JobConfiguration('lint_js', 'custom', [
                'script' => 'npx eslint src/',
                'executable-prefix' => 'php7.4',
            ]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src', 'lint_js']);

        $options = new OptionsConfiguration(false, 1, null, 'full', 'docker exec -i app');
        $config = new ConfigurationResult(
            'githooks.php',
            $options,
            $jobs,
            ['qa' => $flow],
            null,
            new ValidationResult()
        );

        $plan = $this->preparer->prepare($flow, $config);

        $this->assertCount(2, $plan->getJobs());
        // phpstan uses global prefix
        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpstan', $plan->getJobs()[0]->buildCommand());
        // lint_js uses per-job prefix
        $this->assertStringStartsWith('php7.4 npx eslint', $plan->getJobs()[1]->buildCommand());
    }

    /** @test */
    public function it_respects_per_job_executable_prefix_null_opt_out()
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', [
                'executablePath' => 'vendor/bin/phpstan',
                'paths' => ['src'],
                'executable-prefix' => null,
            ]),
            'phpcs_src' => new JobConfiguration('phpcs_src', 'phpcs', [
                'executablePath' => 'vendor/bin/phpcs',
                'paths' => ['src'],
            ]),
        ];

        $flow = new FlowConfiguration('qa', ['phpstan_src', 'phpcs_src']);

        $options = new OptionsConfiguration(false, 1, null, 'full', 'docker exec -i app');
        $config = new ConfigurationResult(
            'githooks.php',
            $options,
            $jobs,
            ['qa' => $flow],
            null,
            new ValidationResult()
        );

        $plan = $this->preparer->prepare($flow, $config);

        $this->assertCount(2, $plan->getJobs());
        // phpstan has explicit null → no prefix
        $this->assertStringStartsWith('vendor/bin/phpstan', $plan->getJobs()[0]->buildCommand());
        // phpcs uses global prefix
        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpcs', $plan->getJobs()[1]->buildCommand());
    }

    /** @test */
    public function it_applies_executable_prefix_to_single_job()
    {
        $jobConfig = new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'paths' => ['src'],
        ]);

        $options = new OptionsConfiguration(false, 1, null, 'full', 'docker exec -i app');
        $plan = $this->preparer->prepareSingleJob($jobConfig, $options);

        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpstan', $plan->getJobs()[0]->buildCommand());
    }
}
