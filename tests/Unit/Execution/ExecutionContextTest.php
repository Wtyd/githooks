<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\FileUtilsFake;
use Wtyd\GitHooks\Execution\ExecutionContext;

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
}
