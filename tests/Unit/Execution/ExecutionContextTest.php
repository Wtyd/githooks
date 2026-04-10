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
}
