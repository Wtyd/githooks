<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Tests\Doubles\FileUtilsFake;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\JobRef;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Jobs\JobRegistry;

/**
 * FEAT-1 · Groups B + C — admission by only-files / exclude-files at flow entry.
 *
 * The skip is binary (run vs skip) and decoupled from path filtering. It is
 * evaluated BEFORE the accelerable check, so all job types (accelerable,
 * non-accelerable, custom) honour the rule.
 */
class FlowPreparerAdmissionTest extends UnitTestCase
{
    private FlowPreparer $preparer;

    protected function setUp(): void
    {
        $this->preparer = new FlowPreparer(new JobRegistry());
    }

    /** @test */
    public function B1_full_mode_treats_admission_rules_as_noop(): void
    {
        $context = $this->fastContextWithStaged(['docs/X.md']);
        $plan = $this->prepareFlowWithOnlyFiles('phpstan', ['src/A/**'], ExecutionMode::FULL, $context);

        $this->assertCount(1, $plan->getJobs(), 'full mode must not apply admission rules');
        $this->assertEmpty($plan->getSkippedJobs());
    }

    /** @test */
    public function B3_fast_mode_admits_job_when_set_matches_only_files(): void
    {
        $context = $this->fastContextWithStaged(['src/A/User.php']);
        $plan = $this->prepareFlowWithOnlyFiles('phpstan', ['src/A/**'], ExecutionMode::FAST, $context);

        $this->assertCount(1, $plan->getJobs());
        $this->assertEmpty($plan->getSkippedJobs());
    }

    /** @test */
    public function B4_fast_mode_skips_job_when_set_does_not_match_only_files(): void
    {
        $context = $this->fastContextWithStaged(['src/B/Other.php']);
        $plan = $this->prepareFlowWithOnlyFiles('phpstan', ['src/A/**'], ExecutionMode::FAST, $context);

        $this->assertCount(0, $plan->getJobs());
        $skipped = $plan->getSkippedJobs();
        $this->assertArrayHasKey('phpstan', $skipped);
        $this->assertStringContainsString('only-files', $skipped['phpstan']['reason']);
    }

    /** @test */
    public function B6_fast_mode_admits_job_when_some_files_outside_exclude_pattern(): void
    {
        $context = $this->fastContextWithStaged(['src/Foo.php']);
        $plan = $this->prepareFlowWithExcludeFiles('phpstan', ['vendor/**'], ExecutionMode::FAST, $context);

        $this->assertCount(1, $plan->getJobs());
    }

    /** @test */
    public function B7_fast_mode_skips_job_when_all_files_match_exclude_pattern(): void
    {
        $context = $this->fastContextWithStaged(['vendor/lib/X.php', 'vendor/lib/Y.php']);
        $plan = $this->prepareFlowWithExcludeFiles('phpstan', ['vendor/**'], ExecutionMode::FAST, $context);

        $this->assertCount(0, $plan->getJobs());
        $skipped = $plan->getSkippedJobs();
        $this->assertArrayHasKey('phpstan', $skipped);
        $this->assertStringContainsString('exclude-files', $skipped['phpstan']['reason']);
    }

    /** @test */
    public function B8_only_files_matches_but_exclude_files_does_not_kill_all_admits_job(): void
    {
        $context = $this->fastContextWithStaged(['src/A.php', 'src/vendor/B.php']);
        $plan = $this->prepareFlowWithBothRules(
            'phpstan',
            ['src/**'],
            ['src/vendor/**'],
            ExecutionMode::FAST,
            $context
        );

        // src/A.php matches only-files AND is NOT in exclude-files → admits the job
        $this->assertCount(1, $plan->getJobs());
    }

    /** @test */
    public function B9_only_files_and_exclude_files_kill_everything_skips_job(): void
    {
        $context = $this->fastContextWithStaged(['src/A.php']);
        $plan = $this->prepareFlowWithBothRules(
            'phpstan',
            ['src/**'],
            ['src/**'],
            ExecutionMode::FAST,
            $context
        );

        $this->assertCount(0, $plan->getJobs());
    }

    /** @test */
    public function C2_non_accelerable_job_is_skipped_by_admission_rules_just_like_accelerable(): void
    {
        // phpunit is non-accelerable but admission rules still apply (orthogonal to type)
        $context = $this->fastContextWithStaged(['src/A.php']);
        $plan = $this->prepareFlowWithOnlyFiles('phpunit', ['tests/**'], ExecutionMode::FAST, $context, 'phpunit', ['tests']);

        $this->assertCount(0, $plan->getJobs());
        $skipped = $plan->getSkippedJobs();
        $this->assertArrayHasKey('phpunit', $skipped);
    }

    /** @test */
    public function entry_without_admission_rules_runs_as_before(): void
    {
        // String entry (A1) and object without rules (A2) must keep the prior behaviour.
        $context = $this->fastContextWithStaged(['src/A.php']);
        $jobConfig = new JobConfiguration('phpstan', 'phpstan', ['paths' => ['src']]);
        $flow = new FlowConfiguration(
            'qa',
            ['phpstan'],
            null,
            null,
            null,
            [JobRef::fromString('phpstan')]
        );
        $config = $this->makeConfig(['phpstan' => $jobConfig], $flow);

        $plan = $this->preparer->prepare($flow, $config, $context, [], [], ExecutionMode::FAST);

        $this->assertCount(1, $plan->getJobs());
    }

    /**
     * Infection mutant on FlowPreparer:96 (Continue_ → Break_): a flow with
     * several entries where the first is admission-skipped must keep iterating
     * to admit the rest. With `break` the second entry would never enter the
     * plan, silently dropped. All previous tests use a single entry and miss
     * this contract.
     *
     * @test
     */
    public function admission_skip_of_first_entry_does_not_stop_iteration_for_following_entries(): void
    {
        // Staged file is in src/B, so:
        //   - entry 1 (only-files src/A/**) → admission-skipped
        //   - entry 2 (only-files src/B/**) → admitted
        $context = $this->fastContextWithStaged(['src/B/Two.php']);

        $jobs = [
            'lint_a' => new JobConfiguration('lint_a', 'phpstan', ['paths' => ['src']]),
            'lint_b' => new JobConfiguration('lint_b', 'phpstan', ['paths' => ['src']]),
        ];
        $jobRefs = [
            new JobRef('lint_a', ['src/A/**'], null),
            new JobRef('lint_b', ['src/B/**'], null),
        ];
        $flow = new FlowConfiguration('qa', ['lint_a', 'lint_b'], null, null, null, $jobRefs);
        $config = $this->makeConfig($jobs, $flow);

        $plan = $this->preparer->prepare($flow, $config, $context, [], [], ExecutionMode::FAST);

        $skipped = $plan->getSkippedJobs();
        $this->assertArrayHasKey('lint_a', $skipped, 'lint_a must be admission-skipped (only-files src/A/** ≠ staged src/B/Two.php)');

        $admittedJobs = $plan->getJobs();
        $this->assertCount(
            1,
            $admittedJobs,
            'lint_b must reach the plan despite lint_a being admission-skipped — Continue→Break would drop it silently.'
        );
        $this->assertSame('lint_b', $admittedJobs[0]->getName());
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function fastContextWithStaged(array $stagedFiles): ExecutionContext
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles($stagedFiles);
        // FlowPreparer also calls directoryContainsFile() once the job is admitted
        // and accelerable; declare everything matchable so paths filtering does not
        // skip the job for an unrelated reason.
        $fileUtils->setFilesThatShouldBeFoundInDirectories($stagedFiles);
        return ExecutionContext::forFastMode($fileUtils);
    }

    private function prepareFlowWithOnlyFiles(
        string $jobName,
        array $onlyFiles,
        string $mode,
        ExecutionContext $context,
        string $type = 'phpstan',
        array $paths = ['src']
    ) {
        return $this->prepareFlowWithRules($jobName, $onlyFiles, null, $mode, $context, $type, $paths);
    }

    private function prepareFlowWithExcludeFiles(
        string $jobName,
        array $excludeFiles,
        string $mode,
        ExecutionContext $context,
        string $type = 'phpstan',
        array $paths = ['src']
    ) {
        return $this->prepareFlowWithRules($jobName, null, $excludeFiles, $mode, $context, $type, $paths);
    }

    private function prepareFlowWithBothRules(
        string $jobName,
        array $onlyFiles,
        array $excludeFiles,
        string $mode,
        ExecutionContext $context,
        string $type = 'phpstan',
        array $paths = ['src']
    ) {
        return $this->prepareFlowWithRules($jobName, $onlyFiles, $excludeFiles, $mode, $context, $type, $paths);
    }

    private function prepareFlowWithRules(
        string $jobName,
        ?array $onlyFiles,
        ?array $excludeFiles,
        string $mode,
        ExecutionContext $context,
        string $type,
        array $paths
    ) {
        $jobConfig = new JobConfiguration($jobName, $type, ['paths' => $paths]);
        $jobs = [$jobName => $jobConfig];
        $jobRef = new JobRef($jobName, $onlyFiles, $excludeFiles);

        $flow = new FlowConfiguration(
            'qa',
            [$jobName],
            null,
            null,
            null,
            [$jobRef]
        );

        $config = $this->makeConfig($jobs, $flow);

        return $this->preparer->prepare($flow, $config, $context, [], [], $mode);
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
}
