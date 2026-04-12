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

/**
 * Tests targeting escaped mutants in HookRunner's pattern matching logic:
 * globToRegex, matchesBranch, matchesFiles, shouldExecute, exitCode.
 */
class HookRunnerPatternMatchTest extends TestCase
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

    // ========================================================================
    // globToRegex: boundary cases for ** handling
    // ========================================================================

    /** @test */
    public function doublestar_between_slashes_matches_zero_or_more_dirs()
    {
        // Pattern: src/**/Test.php — should match src/Test.php (zero dirs) and src/a/Test.php
        $this->fileUtils->setModifiedfiles(['src/Test.php']);
        $config = $this->buildFileConfig(['src/**/Test.php'], []);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function doublestar_between_slashes_matches_multiple_dirs()
    {
        $this->fileUtils->setModifiedfiles(['src/a/b/c/Test.php']);
        $config = $this->buildFileConfig(['src/**/Test.php'], []);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function doublestar_at_end_matches_everything_below()
    {
        // Pattern: src/** — should match src/anything
        $this->fileUtils->setModifiedfiles(['src/deep/nested/file.txt']);
        $config = $this->buildFileConfig(['src/**'], []);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function doublestar_at_start_matches_any_prefix()
    {
        // Pattern: **/*.php — should match any .php file at any depth
        $this->fileUtils->setModifiedfiles(['src/deep/File.php']);
        $config = $this->buildFileConfig(['**/*.php'], []);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function doublestar_at_start_matches_root_level_file()
    {
        // Pattern: **/*.php — should also match root-level file
        $this->fileUtils->setModifiedfiles(['File.php']);
        $config = $this->buildFileConfig(['**/*.php'], []);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function multiple_doublestars_in_pattern()
    {
        // Pattern: src/**/models/**/User.php
        $this->fileUtils->setModifiedfiles(['src/app/models/v2/User.php']);
        $config = $this->buildFileConfig(['src/**/models/**/User.php'], []);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function doublestar_does_not_match_wrong_file_extension()
    {
        $this->fileUtils->setModifiedfiles(['src/File.txt']);
        $config = $this->buildFileConfig(['src/**/*.php'], []);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    /** @test */
    public function doublestar_alone_without_slashes()
    {
        // Pattern: ** alone (no slashes) — should match anything
        $this->fileUtils->setModifiedfiles(['any/path/file.txt']);
        $config = $this->buildFileConfig(['**'], []);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    // ========================================================================
    // matchesBranch: boundary cases
    // ========================================================================

    /** @test */
    public function empty_branch_string_skips_execution()
    {
        $this->fileUtils->setCurrentBranch('');
        $config = $this->buildBranchConfig(['main'], []);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    /** @test */
    public function exact_match_branch_works()
    {
        $this->fileUtils->setCurrentBranch('main');
        $config = $this->buildBranchConfig(['main'], []);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function fnmatch_branch_pattern_works()
    {
        $this->fileUtils->setCurrentBranch('feature/login');
        $config = $this->buildBranchConfig(['feature/*'], []);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function empty_include_with_exclude_defaults_to_all_then_excludes()
    {
        // No include patterns = match all → then exclude applies
        $this->fileUtils->setCurrentBranch('temp/experiment');
        $config = $this->buildBranchConfig([], ['temp/*']);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    /** @test */
    public function empty_include_without_exclude_matches_all()
    {
        // No include, no exclude → match all (only-on/exclude-on both empty = no branch condition)
        // This tests via no conditions → should execute
        $this->fileUtils->setCurrentBranch('any-branch');
        $config = $this->buildBranchConfig([], []);
        // No conditions = always execute
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function exclude_exact_string_match_prevails()
    {
        $this->fileUtils->setCurrentBranch('release/v1');
        $config = $this->buildBranchConfig(['release/*'], ['release/v1']);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    /** @test */
    public function include_matches_but_exclude_does_not()
    {
        $this->fileUtils->setCurrentBranch('release/v2');
        $config = $this->buildBranchConfig(['release/*'], ['release/beta*']);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function branch_not_matching_any_include_pattern_skips()
    {
        $this->fileUtils->setCurrentBranch('hotfix/urgent');
        $config = $this->buildBranchConfig(['feature/*', 'release/*'], []);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    // ========================================================================
    // matchesFiles: boundary cases
    // ========================================================================

    /** @test */
    public function empty_files_array_skips_execution()
    {
        $this->fileUtils->setModifiedfiles([]);
        $config = $this->buildFileConfig(['src/*.php'], []);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    /** @test */
    public function file_matches_include_and_exclude_is_excluded()
    {
        $this->fileUtils->setModifiedfiles(['src/Generated.php']);
        $config = $this->buildFileConfig(['src/*.php'], ['src/Generated.php']);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    /** @test */
    public function all_files_excluded_skips_execution()
    {
        $this->fileUtils->setModifiedfiles(['src/A.php', 'src/B.php']);
        $config = $this->buildFileConfig(['src/*.php'], ['src/*.php']);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    /** @test */
    public function file_not_matching_any_include_is_skipped()
    {
        $this->fileUtils->setModifiedfiles(['vendor/autoload.php']);
        $config = $this->buildFileConfig(['src/*.php'], []);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    /** @test */
    public function first_matching_file_triggers_execution()
    {
        // Even if second file doesn't match, first one does
        $this->fileUtils->setModifiedfiles(['src/User.php', 'vendor/boot.php']);
        $config = $this->buildFileConfig(['src/*.php'], []);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function fnm_pathname_prevents_star_from_crossing_directories()
    {
        // Pattern: src/*.php — should NOT match src/sub/File.php
        $this->fileUtils->setModifiedfiles(['src/sub/File.php']);
        $config = $this->buildFileConfig(['src/*.php'], []);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    // ========================================================================
    // shouldExecute: combined conditions (branch + files are AND-ed)
    // ========================================================================

    /** @test */
    public function only_branch_conditions_work_alone()
    {
        $this->fileUtils->setCurrentBranch('main');
        // Build ref with only-on branches and no file conditions
        $ref = new HookRef('job', ['main'], [], [], []);
        $config = $this->buildConfig('job', $ref);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function only_file_conditions_work_alone()
    {
        $this->fileUtils->setModifiedfiles(['src/File.php']);
        // Build ref with only-files and no branch conditions
        $ref = new HookRef('job', [], ['src/*.php'], []);
        $config = $this->buildConfig('job', $ref);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function branch_matches_but_files_dont_skips_execution()
    {
        $this->fileUtils->setCurrentBranch('main');
        $this->fileUtils->setModifiedfiles(['vendor/autoload.php']);
        // Branch matches, but file doesn't match only-files
        $ref = new HookRef('job', ['main'], ['src/*.php'], []);
        $config = $this->buildConfig('job', $ref);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    /** @test */
    public function files_match_but_branch_doesnt_skips_execution()
    {
        $this->fileUtils->setCurrentBranch('develop');
        $this->fileUtils->setModifiedfiles(['src/File.php']);
        $ref = new HookRef('job', ['main'], ['src/*.php'], []);
        $config = $this->buildConfig('job', $ref);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
    }

    /** @test */
    public function no_conditions_always_executes()
    {
        $this->fileUtils->setModifiedfiles(['anything']);
        $ref = new HookRef('job');
        $config = $this->buildConfig('job', $ref);
        $this->executor->expects($this->once())->method('execute')
            ->willReturn(new FlowResult('j', [], '0s'));
        $results = $this->runner->run('pre-commit', $config);
        $this->assertCount(1, $results);
    }

    /** @test */
    public function all_refs_skipped_by_conditions_adds_warning()
    {
        $this->fileUtils->setCurrentBranch('develop');
        // only-on: main → won't match develop
        $ref = new HookRef('job', ['main'], [], []);
        $config = $this->buildConfig('job', $ref);
        $this->executor->expects($this->never())->method('execute');
        $results = $this->runner->run('pre-commit', $config);
        $this->assertEmpty($results);
        // The validation result should have a warning about skipped refs
        $this->assertNotEmpty($config->getValidation()->getWarnings());
    }

    // ========================================================================
    // exitCode
    // ========================================================================

    /** @test */
    public function exitCode_returns_0_when_all_success()
    {
        $results = [
            new FlowResult('a', [], '0s'),
            new FlowResult('b', [], '0s'),
        ];
        $this->assertSame(0, $this->runner->exitCode($results));
    }

    /** @test */
    public function exitCode_returns_1_when_any_failure()
    {
        $failedJobResult = new \Wtyd\GitHooks\Execution\JobResult('job', false, '', '0s');
        $results = [
            new FlowResult('a', [], '0s'),
            new FlowResult('b', [$failedJobResult], '0s'),
        ];
        $this->assertSame(1, $this->runner->exitCode($results));
    }

    /** @test */
    public function exitCode_returns_0_for_empty_results()
    {
        $this->assertSame(0, $this->runner->exitCode([]));
    }

    // ========================================================================
    // Helper builders
    // ========================================================================

    /** @param string[] $onlyFiles @param string[] $excludeFiles */
    private function buildFileConfig(array $onlyFiles, array $excludeFiles): ConfigurationResult
    {
        $ref = new HookRef('job', [], $onlyFiles, $excludeFiles);
        return $this->buildConfig('job', $ref);
    }

    /** @param string[] $onlyOn @param string[] $excludeOn */
    private function buildBranchConfig(array $onlyOn, array $excludeOn): ConfigurationResult
    {
        $ref = new HookRef('job', $onlyOn, [], [], $excludeOn);
        return $this->buildConfig('job', $ref);
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
}
