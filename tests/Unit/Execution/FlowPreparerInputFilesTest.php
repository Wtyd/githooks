<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\FileUtilsFake;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Execution\InputFilesResolution;
use Wtyd\GitHooks\Jobs\JobRegistry;

/**
 * Verifies that FlowPreparer treats an ExecutionContext built via
 * forInputFiles() as if it were FAST mode with a custom staged list.
 * Spec coverage: AC-001, AC-009, AC-020, AC-021.
 */
class FlowPreparerInputFilesTest extends TestCase
{
    private FlowPreparer $preparer;

    protected function setUp(): void
    {
        $this->preparer = new FlowPreparer(new JobRegistry());
    }

    private function makeContext(array $files, array $matchableFiles = null): ExecutionContext
    {
        $fileUtils = new FileUtilsFake();
        // FakeFileUtils ignores the $directory argument: any file present in
        // the matchable list returns true for directoryContainsFile against any
        // directory. The "matchable" set defines which files survive a job's
        // path intersection.
        $fileUtils->setFilesThatShouldBeFoundInDirectories($matchableFiles ?? $files);

        $resolution = new InputFilesResolution(
            InputFilesResolution::SOURCE_CLI,
            null,
            $files,
            [],
            [],
            [],
            count($files)
        );

        return ExecutionContext::forInputFiles($resolution, $fileUtils);
    }

    private function makeConfig(array $jobs, FlowConfiguration $flow): ConfigurationResult
    {
        return new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            $jobs,
            [$flow->getName() => $flow],
            null,
            new ValidationResult()
        );
    }

    /** @test */
    public function accelerable_job_paths_are_replaced_by_matching_input_files(): void
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
        ];
        $flow = new FlowConfiguration('lint', ['phpstan_src']);
        $config = $this->makeConfig($jobs, $flow);
        $context = $this->makeContext(['src/User.php']);

        $plan = $this->preparer->prepare($flow, $config, $context, [], [], ExecutionMode::FAST);

        $this->assertCount(1, $plan->getJobs());
        $job = $plan->getJobs()[0];
        $this->assertSame(['src/User.php'], $job->getConfiguredPaths());
        $this->assertNotNull($plan->getInputFiles());
    }

    /** @test */
    public function accelerable_job_with_no_matching_input_files_is_skipped_with_files_reason(): void
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
        ];
        $flow = new FlowConfiguration('lint', ['phpstan_src']);
        $config = $this->makeConfig($jobs, $flow);
        // Input file present, but no match returned by directoryContainsFile -> mismatch
        $context = $this->makeContext(['tests/UserTest.php'], []);

        $plan = $this->preparer->prepare($flow, $config, $context, [], [], ExecutionMode::FAST);

        $this->assertCount(0, $plan->getJobs());
        $skipped = $plan->getSkippedJobs();
        $this->assertArrayHasKey('phpstan_src', $skipped);
        $this->assertSame('no input files match its paths', $skipped['phpstan_src']['reason']);
    }

    /** @test */
    public function non_accelerable_job_keeps_its_original_paths(): void
    {
        $jobs = [
            'phpunit' => new JobConfiguration('phpunit', 'phpunit', ['paths' => ['tests']]),
        ];
        $flow = new FlowConfiguration('all', ['phpunit']);
        $config = $this->makeConfig($jobs, $flow);
        $context = $this->makeContext(['src/User.php'], []);

        $plan = $this->preparer->prepare($flow, $config, $context, [], [], ExecutionMode::FAST);

        $this->assertCount(1, $plan->getJobs());
        $this->assertSame(['tests'], $plan->getJobs()[0]->getConfiguredPaths());
    }

    /** @test */
    public function flow_plan_carries_input_files_resolution(): void
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
        ];
        $flow = new FlowConfiguration('lint', ['phpstan_src']);
        $config = $this->makeConfig($jobs, $flow);
        $context = $this->makeContext(['src/User.php']);

        $plan = $this->preparer->prepare($flow, $config, $context, [], [], ExecutionMode::FAST);

        $this->assertNotNull($plan->getInputFiles());
        $this->assertSame(1, $plan->getInputFiles()->getTotalValid());
    }

    /** @test */
    public function plan_without_input_files_has_null_resolution(): void
    {
        $jobs = [
            'phpstan_src' => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
        ];
        $flow = new FlowConfiguration('lint', ['phpstan_src']);
        $config = $this->makeConfig($jobs, $flow);

        $plan = $this->preparer->prepare($flow, $config);

        $this->assertNull($plan->getInputFiles());
    }
}
