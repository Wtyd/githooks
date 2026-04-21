<?php

declare(strict_types=1);

namespace Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\FileUtilsFake;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\HookConfiguration;
use Wtyd\GitHooks\Configuration\HookRef;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Hooks\HookRunner;

class HookRunnerTest extends TestCase
{
    private FileUtilsFake $fileUtils;

    /** @var FlowPreparer&\PHPUnit\Framework\MockObject\MockObject */
    private $preparer;

    /** @var FlowExecutor&\PHPUnit\Framework\MockObject\MockObject */
    private $executor;

    private HookRunner $runner;

    protected function setUp(): void
    {
        $this->fileUtils = new FileUtilsFake();
        $this->preparer = $this->createMock(FlowPreparer::class);
        $this->executor = $this->createMock(FlowExecutor::class);
        $this->runner = new HookRunner($this->preparer, $this->executor, $this->fileUtils);
    }

    /** @test */
    public function only_files_without_exclude_files_works_as_before()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], []);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function only_files_skips_when_no_files_match()
    {
        $this->fileUtils->setModifiedfiles(['vendor/autoload.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], []);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function exclude_files_prevents_execution_when_all_matching_files_are_excluded()
    {
        $this->fileUtils->setModifiedfiles(['src/Runner.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], ['src/Runner.php']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function exclude_files_allows_execution_when_non_excluded_files_match()
    {
        $this->fileUtils->setModifiedfiles([
            'src/Runner.php',
            'src/User.php',
        ]);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], ['src/Runner.php']);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_files_without_only_files_excludes_from_all()
    {
        $this->fileUtils->setModifiedfiles(['vendor/autoload.php']);

        $config = $this->buildConfigWithJobRef('phpcs', [], ['vendor/*.php']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function exclude_files_without_only_files_allows_non_excluded()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        $config = $this->buildConfigWithJobRef('phpcs', [], ['vendor/*.php']);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_prevails_over_include_for_same_pattern()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], ['src/*.php']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function doublestar_matches_files_in_nested_subdirectories()
    {
        $this->fileUtils->setModifiedfiles(['src/Models/User.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/**/*.php'], []);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function doublestar_matches_deeply_nested_files()
    {
        $this->fileUtils->setModifiedfiles(['src/Tools/Process/Runner.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/**/*.php'], []);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function doublestar_matches_direct_children()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/**/*.php'], []);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function doublestar_alone_matches_everything_under_directory()
    {
        $this->fileUtils->setModifiedfiles(['src/Tools/Process/Runner.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/**'], []);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function doublestar_does_not_match_outside_prefix()
    {
        $this->fileUtils->setModifiedfiles(['vendor/autoload.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/**/*.php'], []);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function doublestar_works_in_exclude_files()
    {
        $this->fileUtils->setModifiedfiles([
            'src/Tools/Process/Runner.php',
            'src/Models/User.php',
        ]);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/**/*.php'], ['src/Tools/Process/**']);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function singlestar_still_does_not_cross_directories()
    {
        $this->fileUtils->setModifiedfiles(['src/Models/User.php']);

        $config = $this->buildConfigWithJobRef('phpcs', ['src/*.php'], []);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function no_conditions_always_executes()
    {
        $this->fileUtils->setModifiedfiles(['anything.txt']);

        $config = $this->buildConfigWithJobRef('phpcs', [], []);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_on_skips_matching_branch()
    {
        $this->fileUtils->setCurrentBranch('GH-42');

        $config = $this->buildConfigWithBranchRef('phpcs', [], ['GH-*']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function exclude_on_allows_non_matching_branch()
    {
        $this->fileUtils->setCurrentBranch('main');

        $config = $this->buildConfigWithBranchRef('phpcs', [], ['GH-*']);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_on_prevails_over_only_on()
    {
        $this->fileUtils->setCurrentBranch('release/beta-1');

        $config = $this->buildConfigWithBranchRef('phpcs', ['release/*'], ['release/beta-*']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    /** @test */
    public function only_on_with_exclude_on_allows_non_excluded()
    {
        $this->fileUtils->setCurrentBranch('release/v2.0');

        $config = $this->buildConfigWithBranchRef('phpcs', ['release/*'], ['release/beta-*']);

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_on_without_only_on_excludes_from_all()
    {
        $this->fileUtils->setCurrentBranch('temp/experiment');

        $config = $this->buildConfigWithBranchRef('phpcs', [], ['temp/*']);

        $this->executor->expects($this->never())->method('execute');

        $results = $this->runner->run('pre-commit', $config);

        $this->assertEmpty($results);
    }

    // ========================================================================
    // Fix: pre-commit no longer forces fast mode
    // (TDD — will fail until HookRunner is refactored)
    // ========================================================================

    /** @test */
    public function pre_commit_no_longer_forces_fast_mode()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        $config = $this->buildConfigWithJobRef('phpcs', [], []);

        // Expect executor to be called — the plan's context should NOT have isFastMode()=true
        // (unless the config explicitly sets execution mode)
        $this->executor->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($plan) {
                $context = $plan->getContext();
                // Context should exist (for lazy file loading) but NOT be in forced fast mode
                return $context === null || !$context->isFastMode();
            }))
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $this->runner->run('pre-commit', $config);
    }

    /** @test */
    public function non_pre_commit_events_also_work_without_forced_mode()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        // Build config with pre-push event
        $ref = new HookRef('phpcs', [], [], []);
        $hookConfig = new HookConfiguration(['pre-push' => [$ref]]);
        $jobConfig = new JobConfiguration('phpcs', 'phpcs', []);

        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            ['phpcs' => $jobConfig],
            [],
            $hookConfig,
            new ValidationResult()
        );

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $results = $this->runner->run('pre-push', $config);

        $this->assertCount(1, $results);
    }

    /**
     * @param string[] $onlyFiles
     * @param string[] $excludeFiles
     */
    private function buildConfigWithJobRef(string $jobName, array $onlyFiles, array $excludeFiles): ConfigurationResult
    {
        $ref = new HookRef($jobName, [], $onlyFiles, $excludeFiles);

        return $this->buildConfig($jobName, $ref);
    }

    /**
     * @param string[] $onlyOn
     * @param string[] $excludeOn
     */
    private function buildConfigWithBranchRef(string $jobName, array $onlyOn, array $excludeOn): ConfigurationResult
    {
        $ref = new HookRef($jobName, $onlyOn, [], [], $excludeOn);

        return $this->buildConfig($jobName, $ref);
    }

    private function buildConfig(string $jobName, HookRef $ref): ConfigurationResult
    {
        $hookConfig = new HookConfiguration(['pre-commit' => [$ref]]);

        $jobConfig = new JobConfiguration($jobName, 'phpcs', []);

        return new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [$jobName => $jobConfig],
            [],
            $hookConfig,
            new ValidationResult()
        );
    }

    // ========================================================================
    // Mutation coverage — multiple refs, warning conditions, flow execution
    // ========================================================================

    /** @test */
    public function run_executes_all_refs_configured_for_event_returning_one_result_per_ref()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        $refs = [
            new HookRef('phpcs', [], [], []),
            new HookRef('phpstan', [], [], []),
        ];
        $hookConfig = new HookConfiguration(['pre-commit' => $refs]);
        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [
                'phpcs'   => new JobConfiguration('phpcs', 'phpcs', []),
                'phpstan' => new JobConfiguration('phpstan', 'phpstan', []),
            ],
            [],
            $hookConfig,
            new ValidationResult()
        );

        $this->executor->expects($this->exactly(2))
            ->method('execute')
            ->willReturnOnConsecutiveCalls(
                new FlowResult('phpcs', [], '0.00s'),
                new FlowResult('phpstan', [], '0.00s')
            );

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(2, $results);
    }

    /** @test */
    public function run_continues_to_next_ref_after_one_is_skipped_by_conditions()
    {
        $this->fileUtils->setModifiedfiles(['src/User.php']);
        $this->fileUtils->setCurrentBranch('main');

        $skippedRef = new HookRef('phpcs', [], [], [], ['main']); // excluded by current branch
        $executedRef = new HookRef('phpstan', [], [], []);
        $hookConfig = new HookConfiguration(['pre-commit' => [$skippedRef, $executedRef]]);
        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [
                'phpcs'   => new JobConfiguration('phpcs', 'phpcs', []),
                'phpstan' => new JobConfiguration('phpstan', 'phpstan', []),
            ],
            [],
            $hookConfig,
            new ValidationResult()
        );

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpstan', [], '0.00s'));

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
    }

    /** @test */
    public function run_propagates_flow_result_when_ref_targets_a_flow()
    {
        $flowConfig = new \Wtyd\GitHooks\Configuration\FlowConfiguration('qa', ['phpcs'], null);
        $ref = new HookRef('qa', [], [], []);
        $hookConfig = new HookConfiguration(['pre-commit' => [$ref]]);
        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            ['phpcs' => new JobConfiguration('phpcs', 'phpcs', [])],
            ['qa' => $flowConfig],
            $hookConfig,
            new ValidationResult()
        );

        $expected = new FlowResult('qa', [], '0.00s');
        $this->executor->expects($this->once())->method('execute')->willReturn($expected);

        $results = $this->runner->run('pre-commit', $config);

        $this->assertCount(1, $results);
        $this->assertSame($expected, $results[0]);
    }

    /** @test */
    public function run_adds_warning_when_all_refs_skipped_by_conditions()
    {
        $this->fileUtils->setCurrentBranch('main');

        $config = $this->buildConfigWithBranchRef('phpcs', [], ['main']);
        $this->executor->expects($this->never())->method('execute');

        $this->runner->run('pre-commit', $config);

        $warnings = $config->getValidation()->getWarnings();
        $this->assertNotEmpty($warnings, 'expected a warning when all refs are skipped by conditions');
    }

    /** @test */
    public function run_does_not_add_warning_when_event_has_no_refs_configured()
    {
        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [],
            [],
            new HookConfiguration([]),
            new ValidationResult()
        );

        $this->runner->run('pre-commit', $config);

        $this->assertSame([], $config->getValidation()->getWarnings());
    }

    /**
     * @test
     * Kills HookRunner:76 LogicalAnd→Or: the "all refs skipped by conditions"
     * warning must only appear when NO ref produced a result AND at least one
     * was skipped. When one ref executes and another is skipped, the mutant
     * `||` would falsely claim "all skipped".
     */
    public function run_does_not_warn_when_only_some_refs_skipped_by_conditions()
    {
        $this->fileUtils->setCurrentBranch('develop');
        $this->fileUtils->setModifiedfiles(['src/User.php']);

        // First ref: no conditions → executes.
        // Second ref: only-on=main → skipped on develop.
        $refs = [
            new HookRef('phpcs', [], [], []),
            new HookRef('phpstan', ['main'], [], []),
        ];
        $hookConfig = new HookConfiguration(['pre-commit' => $refs]);
        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [
                'phpcs'   => new JobConfiguration('phpcs', 'phpcs', []),
                'phpstan' => new JobConfiguration('phpstan', 'phpstan', []),
            ],
            [],
            $hookConfig,
            new ValidationResult()
        );

        $this->executor->expects($this->once())
            ->method('execute')
            ->willReturn(new FlowResult('phpcs', [], '0.00s'));

        $this->runner->run('pre-commit', $config);

        $this->assertSame(
            [],
            $config->getValidation()->getWarnings(),
            'no warning should appear when at least one ref executed successfully'
        );
    }

    /**
     * @test
     * Kills HookRunner:76 GreaterThan→Eq (`> 0` vs `>= 0`): when no ref is
     * skipped by conditions but all fail to resolve (unknown target), the
     * mutant `>= 0` would warn falsely since `skipped === 0` satisfies it.
     */
    public function run_does_not_warn_when_results_empty_without_any_skip_by_conditions()
    {
        // Ref with no conditions (passes shouldExecute) pointing to a
        // non-existent target → executeRef returns null → results empty,
        // skippedByConditions stays at 0.
        $ref = new HookRef('nonexistent_target', [], [], []);
        $hookConfig = new HookConfiguration(['pre-commit' => [$ref]]);
        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [],
            [],
            $hookConfig,
            new ValidationResult()
        );

        $this->executor->expects($this->never())->method('execute');

        $this->runner->run('pre-commit', $config);

        $this->assertSame(
            [],
            $config->getValidation()->getWarnings(),
            'no warning should appear when nothing was skipped by conditions'
        );
    }
}
