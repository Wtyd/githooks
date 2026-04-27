<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\JobResult;

class JobResultTest extends TestCase
{
    /** @test */
    public function it_stores_new_fields_from_constructor()
    {
        $result = new JobResult(
            'phpstan_src',
            true,
            'output',
            '1.23s',
            false,
            'vendor/bin/phpstan analyse src',
            'phpstan',
            0,
            ['src']
        );

        $this->assertEquals('phpstan', $result->getType());
        $this->assertEquals(0, $result->getExitCode());
        $this->assertEquals(['src'], $result->getPaths());
        $this->assertFalse($result->isSkipped());
        $this->assertNull($result->getSkipReason());
        $this->assertEquals('vendor/bin/phpstan analyse src', $result->getCommand());
    }

    /** @test */
    public function it_defaults_new_fields_when_not_provided()
    {
        $result = new JobResult('test', true, '', '100ms');

        $this->assertEquals('', $result->getType());
        $this->assertNull($result->getExitCode());
        $this->assertEquals([], $result->getPaths());
        $this->assertFalse($result->isSkipped());
        $this->assertNull($result->getSkipReason());
    }

    /** @test */
    public function skipped_named_constructor_creates_correct_result()
    {
        $result = JobResult::skipped('phpcs_src', 'phpcs', 'no staged files match its paths', ['src']);

        $this->assertEquals('phpcs_src', $result->getJobName());
        $this->assertEquals('phpcs', $result->getType());
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->isSkipped());
        $this->assertEquals('no staged files match its paths', $result->getSkipReason());
        $this->assertEquals(['src'], $result->getPaths());
        $this->assertEquals('', $result->getOutput());
        $this->assertEquals('0ms', $result->getExecutionTime());
        $this->assertFalse($result->isFixApplied());
        $this->assertNull($result->getCommand());
        $this->assertNull($result->getExitCode());
    }

    /** @test */
    public function skipped_result_defaults_paths_to_empty()
    {
        $result = JobResult::skipped('test', 'phpstan', 'excluded');

        $this->assertEquals([], $result->getPaths());
    }

    /** @test */
    public function stdout_defaults_to_null_when_not_provided()
    {
        $result = new JobResult('phpstan_src', true, 'combined output', '100ms');

        $this->assertNull($result->getStdout());
    }

    /** @test */
    public function stdout_is_stored_when_provided_via_constructor()
    {
        $stdoutJson = '{"files":{"src/User.php":{"messages":[{"line":14,"message":"Method not found"}]}}}';

        $result = new JobResult(
            'phpstan_src',
            false,
            $stdoutJson . "\nextra stderr",
            '1.23s',
            false,
            'vendor/bin/phpstan analyse',
            'phpstan',
            1,
            ['src'],
            false,
            null,
            $stdoutJson
        );

        $this->assertSame($stdoutJson, $result->getStdout());
        $this->assertNotSame($result->getOutput(), $result->getStdout());
    }

    /** @test */
    public function stdout_can_be_empty_string()
    {
        $result = new JobResult(
            'phpcs_src',
            true,
            '',
            '50ms',
            false,
            null,
            'phpcs',
            0,
            [],
            false,
            null,
            ''
        );

        $this->assertSame('', $result->getStdout());
        $this->assertNotNull($result->getStdout());
    }

    // ========================================================================
    // Threshold metadata (v3.3 item 4 — per-job time thresholds)
    // ========================================================================

    /** @test */
    public function threshold_defaults_are_none(): void
    {
        $result = new JobResult('phpcs_src', true, '', '50ms');

        $this->assertSame(0.0, $result->getDurationSeconds());
        $this->assertSame(JobResult::THRESHOLD_NONE, $result->getThresholdState());
        $this->assertNull($result->getThresholdReason());
        $this->assertNull($result->getConfiguredWarnAfter());
        $this->assertNull($result->getConfiguredFailAfter());
        $this->assertFalse($result->hasThreshold());
        $this->assertFalse($result->isThresholdWarned());
        $this->assertFalse($result->isThresholdFailed());
    }

    /** @test */
    public function threshold_metadata_round_trips(): void
    {
        $result = new JobResult(
            'phpunit',
            true,
            '',
            '95.4s',
            false,
            null,
            'phpunit',
            0,
            [],
            false,
            null,
            null,
            null,
            95.4,
            JobResult::THRESHOLD_WARNED,
            JobResult::THRESHOLD_REASON_WARN,
            60,
            180
        );

        $this->assertSame(95.4, $result->getDurationSeconds());
        $this->assertTrue($result->isThresholdWarned());
        $this->assertFalse($result->isThresholdFailed());
        $this->assertSame(JobResult::THRESHOLD_REASON_WARN, $result->getThresholdReason());
        $this->assertSame(60, $result->getConfiguredWarnAfter());
        $this->assertSame(180, $result->getConfiguredFailAfter());
        $this->assertTrue($result->hasThreshold());
    }

    /** @test */
    public function with_threshold_clones_without_mutating_original(): void
    {
        $original = new JobResult('phpcs', false, '', '8.2s');
        $clone = $original->withThreshold(JobResult::THRESHOLD_FAILED, JobResult::THRESHOLD_REASON_FAIL);

        $this->assertSame(JobResult::THRESHOLD_NONE, $original->getThresholdState());
        $this->assertSame(JobResult::THRESHOLD_FAILED, $clone->getThresholdState());
        $this->assertSame(JobResult::THRESHOLD_REASON_FAIL, $clone->getThresholdReason());
    }
}
