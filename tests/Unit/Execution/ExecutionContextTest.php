<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\FileUtilsFake;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\ExecutionMode;

class ExecutionContextTest extends TestCase
{
    /** @test */
    function default_context_is_not_fast_mode()
    {
        $context = ExecutionContext::default();

        $this->assertFalse($context->isFastMode());
        $this->assertEmpty($context->getStagedFiles());
    }

    /** @test */
    function fast_mode_context_stores_modified_files()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php', 'src/Bar.php']);

        $context = ExecutionContext::forFastMode($fileUtils);

        $this->assertTrue($context->isFastMode());
        $this->assertEquals(['src/Foo.php', 'src/Bar.php'], $context->getStagedFiles());
    }

    /** @test */
    function it_filters_files_that_match_paths()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php', 'tests/FooTest.php', 'src/Bar.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php', 'src/Bar.php']);

        $context = ExecutionContext::forFastMode($fileUtils);

        $filtered = $context->filterFilesForPaths(['src']);

        $this->assertEquals(['src/Foo.php', 'src/Bar.php'], $filtered);
    }

    /** @test */
    function it_returns_empty_when_no_files_match_paths()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['tests/FooTest.php']);

        $context = ExecutionContext::forFastMode($fileUtils);

        $filtered = $context->filterFilesForPaths(['src']);

        $this->assertEmpty($filtered);
    }

    /** @test */
    function it_filters_across_multiple_paths()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php', 'app/Bar.php', 'tests/Test.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php', 'app/Bar.php']);

        $context = ExecutionContext::forFastMode($fileUtils);

        $filtered = $context->filterFilesForPaths(['src', 'app']);

        $this->assertEquals(['src/Foo.php', 'app/Bar.php'], $filtered);
    }

    /** @test */
    function it_excludes_files_outside_job_paths_even_if_same_extension()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles([
            'src/Foo.php',
            'database/migration.php',
            'config/app.php',
        ]);
        // Only src/Foo.php is inside the 'src' directory
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php']);

        $context = ExecutionContext::forFastMode($fileUtils);

        $filtered = $context->filterFilesForPaths(['src']);

        $this->assertEquals(['src/Foo.php'], $filtered);
        $this->assertNotContains('database/migration.php', $filtered);
        $this->assertNotContains('config/app.php', $filtered);
    }

    /** @test */
    function it_returns_empty_when_no_staged_files()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles([]);

        $context = ExecutionContext::forFastMode($fileUtils);

        $filtered = $context->filterFilesForPaths(['src']);

        $this->assertEmpty($filtered);
    }

    // ========================================================================
    // 3-mode execution tests (TDD — will fail until ExecutionContext is refactored)
    // ========================================================================

    /** @test */
    function create_factory_provides_context_instance()
    {
        $fileUtils = new FileUtilsFake();
        $context = ExecutionContext::create($fileUtils, 'master');

        $this->assertInstanceOf(ExecutionContext::class, $context);
    }

    /** @test */
    function filter_files_for_mode_fast_uses_staged_files()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php', 'tests/FooTest.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php']);

        $context = ExecutionContext::create($fileUtils, 'master');
        $filtered = $context->filterFilesForMode(ExecutionMode::FAST, ['src']);

        $this->assertEquals(['src/Foo.php'], $filtered);
    }

    /** @test */
    function filter_files_for_mode_fast_branch_uses_branch_diff_files()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/New.php']);
        $fileUtils->setBranchDiffFiles(['src/Old.php', 'src/New.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Old.php', 'src/New.php']);

        $context = ExecutionContext::create($fileUtils, 'master');
        $filtered = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);

        $this->assertContains('src/Old.php', $filtered);
        $this->assertContains('src/New.php', $filtered);
    }

    /** @test */
    function filter_files_for_mode_full_returns_null()
    {
        $fileUtils = new FileUtilsFake();
        $context = ExecutionContext::create($fileUtils, 'master');

        $result = $context->filterFilesForMode(ExecutionMode::FULL, ['src']);

        $this->assertNull($result);
    }

    /** @test */
    function filter_files_for_mode_fast_branch_returns_null_when_diff_fails()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setBranchDiffFiles(null);

        $context = ExecutionContext::create($fileUtils, 'master');
        $result = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);

        $this->assertNull($result);
    }

    /** @test */
    function filter_files_for_mode_fast_branch_deduplicates_staged_and_branch_diff()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Staged.php']);
        $fileUtils->setBranchDiffFiles(['src/Staged.php', 'src/BranchOnly.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Staged.php', 'src/BranchOnly.php']);

        $context = ExecutionContext::create($fileUtils, 'master');
        $filtered = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);

        // Should contain both files, but src/Staged.php only once
        $this->assertCount(2, $filtered);
        $this->assertContains('src/Staged.php', $filtered);
        $this->assertContains('src/BranchOnly.php', $filtered);
    }

    /** @test */
    function filter_files_for_paths_backward_compat_still_works()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php', 'tests/FooTest.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php']);

        // Using the old forFastMode factory
        $context = ExecutionContext::forFastMode($fileUtils);
        $filtered = $context->filterFilesForPaths(['src']);

        $this->assertEquals(['src/Foo.php'], $filtered);
    }

    // ========================================================================
    // Lazy loading edge cases (targeting escaped mutants)
    // ========================================================================

    /** @test */
    function create_with_null_main_branch_returns_null_for_fast_branch()
    {
        $fileUtils = new FileUtilsFake();
        $context = ExecutionContext::create($fileUtils, null);

        $result = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);

        $this->assertNull($result);
    }

    /** @test */
    function fast_branch_diff_failure_is_cached_returns_null_on_retry()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setBranchDiffFiles(null); // simulate diff failure

        $context = ExecutionContext::create($fileUtils, 'master');

        // First call: diff fails
        $result1 = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);
        $this->assertNull($result1);

        // Second call: should return null without retrying (cached false sentinel)
        $result2 = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);
        $this->assertNull($result2);
    }

    /** @test */
    function fast_branch_success_is_cached()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/New.php']);
        $fileUtils->setBranchDiffFiles(['src/Old.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Old.php', 'src/New.php']);

        $context = ExecutionContext::create($fileUtils, 'master');

        $result1 = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);
        $result2 = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);

        // Both should return same result (cached)
        $this->assertEquals($result1, $result2);
    }

    /** @test */
    function filter_files_for_mode_with_unknown_mode_returns_null()
    {
        $fileUtils = new FileUtilsFake();
        $context = ExecutionContext::create($fileUtils, 'master');

        $result = $context->filterFilesForMode('turbo', ['src']);

        $this->assertNull($result);
    }

    /** @test */
    function create_lazy_loads_staged_files_on_first_access()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php']);

        $context = ExecutionContext::create($fileUtils, 'master');

        // Staged files should be loaded lazily on first access
        $staged = $context->getStagedFiles();
        $this->assertEquals(['src/Foo.php'], $staged);
    }

    /** @test */
    function filter_file_list_with_empty_paths_returns_empty()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php']);

        $context = ExecutionContext::forFastMode($fileUtils);
        $filtered = $context->filterFilesForPaths([]);

        $this->assertEmpty($filtered);
    }

    /** @test */
    function fast_branch_with_empty_diff_returns_empty_filtered_list()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles([]);
        $fileUtils->setBranchDiffFiles([]);

        $context = ExecutionContext::create($fileUtils, 'master');
        $result = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ========================================================================
    // Infection Tier 2 — lazy loading, cache sentinel, retry paths
    // ========================================================================

    /**
     * @test
     * Kills L86 MethodCallRemoval on `ensureStagedLoaded`: calling
     * filterFilesForPaths after create() must trigger a lazy load. Without
     * the method call the staged file list stays empty and no file matches.
     */
    function filter_files_for_paths_loads_staged_lazily_from_create_factory()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php']);

        $context = ExecutionContext::create($fileUtils, 'master');

        $this->assertSame(0, $fileUtils->getModifiedFilesCallCount);

        $filtered = $context->filterFilesForPaths(['src']);

        $this->assertSame(['src/Foo.php'], $filtered);
        $this->assertSame(1, $fileUtils->getModifiedFilesCallCount);
    }

    /**
     * @test
     * Kills L142 ReturnRemoval after the cache hit branch: once the branch
     * diff has been successfully loaded, subsequent calls must NOT re-invoke
     * FileUtils::getBranchDiffFiles. Without the return the second call
     * re-queries and the counter jumps to 2.
     */
    function fast_branch_success_does_not_requery_on_second_call()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/New.php']);
        $fileUtils->setBranchDiffFiles(['src/Old.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Old.php', 'src/New.php']);

        $context = ExecutionContext::create($fileUtils, 'master');

        $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);
        $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);

        $this->assertSame(1, $fileUtils->branchDiffCallCount);
    }

    /**
     * @test
     * Kills L138 ReturnRemoval + L153 FalseValue: a diff failure must be
     * cached as a sticky sentinel so the second call returns null without
     * re-querying. If the sentinel flips to `true` or the return disappears,
     * the counter grows past 1.
     */
    function fast_branch_failure_is_cached_and_not_retried()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setBranchDiffFiles(null);

        $context = ExecutionContext::create($fileUtils, 'master');

        $first = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);
        $second = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $fileUtils->branchDiffCallCount);
    }

    /**
     * @test
     * Kills L147 FalseValue: when mainBranch is null the lazy loader must
     * record the failure and never query FileUtils. A mutant flipping the
     * sentinel to `true` would not stop subsequent re-entries from the
     * contract.
     */
    function fast_branch_without_main_branch_returns_null_without_querying()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setBranchDiffFiles(['src/Other.php']);

        $context = ExecutionContext::create($fileUtils, null);

        $first = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);
        $second = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(0, $fileUtils->branchDiffCallCount);
    }

    /**
     * @test
     * Kills L158 MethodCallRemoval on ensureStagedLoaded inside the dedup
     * branch + L159 UnwrapArrayMerge: the branch-diff result must be the
     * deduplicated union of staged and branch files. Without array_merge
     * one side disappears.
     */
    function fast_branch_result_is_dedup_union_of_staged_and_branch_files()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Staged.php', 'src/Shared.php']);
        $fileUtils->setBranchDiffFiles(['src/Branch.php', 'src/Shared.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories([
            'src/Staged.php', 'src/Shared.php', 'src/Branch.php',
        ]);

        $context = ExecutionContext::create($fileUtils, 'master');
        $result = $context->filterFilesForMode(ExecutionMode::FAST_BRANCH, ['src']);

        sort($result);
        $this->assertSame(['src/Branch.php', 'src/Shared.php', 'src/Staged.php'], $result);
    }

    /**
     * @test
     * Kills L99 ReturnRemoval on the FULL branch: filterFilesForMode('full', …)
     * must return null. Since the method eventually falls through to a final
     * `return null;` when no branch matches, the mutant would only survive
     * if the intermediate assignments drifted — here we also check that
     * staged files are NOT loaded as a side-effect in FULL mode.
     */
    function filter_files_for_mode_returns_null_without_loading_in_full_mode()
    {
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php']);

        $context = ExecutionContext::create($fileUtils, 'master');

        $this->assertNull($context->filterFilesForMode(ExecutionMode::FULL, ['src']));
        $this->assertSame(0, $fileUtils->getModifiedFilesCallCount);
        $this->assertSame(0, $fileUtils->branchDiffCallCount);
    }

    // ========================================================================
    // Infection Tier 2 — fileIsInPaths with real files on disk
    // ========================================================================

    /** @var string[] Temporary files to clean up in tearDown */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }
        $this->tempPaths = [];
    }

    private function makeTempFile(string $contents = ''): string
    {
        $path = sys_get_temp_dir() . '/ctx_test_' . uniqid() . '.php';
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;
        return $path;
    }

    /**
     * @test
     * Kills L186 guards (six mutants on the file-path match): the first
     * branch of `fileIsInPaths` reaches `isSameFile($file, $path)` ONLY when
     * `$path` is a real file on disk. Previous tests used synthetic paths
     * so `is_file($path)` was always false, leaving the branch untested.
     *
     * When paths points to an existing file whose isSameFile returns true,
     * the file is included in the filtered list.
     */
    function fileIsInPaths_matches_via_isSameFile_when_path_is_a_real_file()
    {
        $realPath = $this->makeTempFile('<?php // test');

        $fileUtils = new FileUtilsFake();
        // The staged entry uses the same real path — isSameFile will match.
        $fileUtils->setModifiedfiles([$realPath]);

        $context = ExecutionContext::forFastMode($fileUtils);
        $filtered = $context->filterFilesForPaths([$realPath]);

        $this->assertSame([$realPath], $filtered);
    }

    /**
     * @test
     * Kills the second guard in L186: `directoryContainsFile` is only
     * reached when is_file($path) is false (i.e. $path is a directory or
     * doesn't exist). A real file in paths that DOESN'T match the staged
     * file must not be included.
     */
    function fileIsInPaths_does_not_match_when_real_file_path_differs_from_staged()
    {
        $realPath = $this->makeTempFile();

        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Other.php']);
        // Don't register 'src/Other.php' under $realPath directory.
        $fileUtils->setFilesThatShouldBeFoundInDirectories([]);

        $context = ExecutionContext::forFastMode($fileUtils);
        $filtered = $context->filterFilesForPaths([$realPath]);

        $this->assertSame([], $filtered);
    }

    /**
     * @test
     * Kills the short-circuit in the second guard: when $path is a directory
     * (is_file false) and directoryContainsFile returns true, the file is
     * included even without isSameFile matching.
     */
    function fileIsInPaths_matches_via_directoryContainsFile_when_path_is_a_directory()
    {
        $realDir = sys_get_temp_dir();

        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Foo.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Foo.php']);

        $context = ExecutionContext::forFastMode($fileUtils);
        $filtered = $context->filterFilesForPaths([$realDir]);

        $this->assertSame(['src/Foo.php'], $filtered);
    }

    /**
     * @test
     * With both guards failing (path is a real file but neither isSameFile
     * nor directoryContainsFile matches), the file must be excluded. This
     * forces both branches of the compound guard to be exercised.
     */
    function fileIsInPaths_excludes_file_when_neither_guard_matches()
    {
        $realPath = $this->makeTempFile();

        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['src/Unrelated.php']);
        $fileUtils->setFilesThatShouldBeFoundInDirectories([]);

        $context = ExecutionContext::forFastMode($fileUtils);
        $filtered = $context->filterFilesForPaths([$realPath]);

        $this->assertSame([], $filtered);
    }
}
