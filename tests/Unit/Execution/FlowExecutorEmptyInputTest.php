<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\OutputHandlerSpy;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Output\NullOutputHandler;

/**
 * BUG-1: FlowExecutor reinterprets a JobResult as `skipped: true` when the
 * underlying tool returned a non-zero exit code accompanied by a
 * known "all inputs were excluded by the tool's internal config" marker.
 *
 * These tests exercise the real cabling in FlowExecutor::buildResult() with
 * synthetic Job subclasses that override buildCommand() to produce the
 * exit-code + output combination of the real tool, without requiring PHPStan
 * or PHPCS to be installed on the test runner.
 */
class FlowExecutorEmptyInputTest extends TestCase
{
    /** @test */
    public function phpstan_job_returning_no_files_found_marker_is_reinterpreted_as_skipped()
    {
        $job = new class (new JobConfiguration('phpstan_luz_ci', 'phpstan', ['paths' => ['src']])) extends PhpstanJob {
            public function buildCommand(): string
            {
                // PHPStan emits the marker to stderr and exits 1. The shell
                // here mirrors that exact signature.
                return 'sh -c \'printf "[ERROR] No files found to analyse.\\n" >&2; exit 1\'';
            }
        };

        $executor = new FlowExecutor(new NullOutputHandler());
        $result = $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration(false, 1)));

        $jobResult = $result->getJobResults()[0];
        $this->assertTrue($jobResult->isSkipped(), 'JobResult must be flagged as skipped');
        $this->assertTrue($jobResult->isSuccess(), 'Skipped is also success (the flow does not fail)');
        $this->assertNotNull($jobResult->getSkipReason());
        $this->assertSame(1, $jobResult->getExitCode());
        $this->assertTrue($result->isSuccess(), 'Whole flow succeeds — skipped jobs do not fail it');
    }

    /** @test */
    public function phpstan_job_with_real_violations_is_not_reinterpreted()
    {
        // Same exit code (1) but different output — must NOT be reinterpreted.
        $job = new class (new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']])) extends PhpstanJob {
            public function buildCommand(): string
            {
                return 'sh -c \'printf " ------ -------\\n  Line   src/Foo.php\\n  42     Class Foo not found.\\n"; exit 1\'';
            }
        };

        $executor = new FlowExecutor(new NullOutputHandler());
        $result = $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration(false, 1)));

        $jobResult = $result->getJobResults()[0];
        $this->assertFalse($jobResult->isSkipped(), 'Real failures must NOT be reinterpreted');
        $this->assertFalse($jobResult->isSuccess());
        $this->assertNull($jobResult->getSkipReason());
        $this->assertFalse($result->isSuccess(), 'Real failure propagates to the flow');
    }

    /** @test */
    public function phpcs_job_with_all_specified_files_excluded_marker_is_reinterpreted_as_skipped()
    {
        $job = new class (new JobConfiguration('phpcs_luz', 'phpcs', ['paths' => ['src']])) extends PhpcsJob {
            public function buildCommand(): string
            {
                return 'sh -c \'printf "ERROR: All specified files were excluded or did not match filtering rules.\\n"; exit 16\'';
            }
        };

        $executor = new FlowExecutor(new NullOutputHandler());
        $result = $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration(false, 1)));

        $jobResult = $result->getJobResults()[0];
        $this->assertTrue($jobResult->isSkipped(), 'PHPCS exit=16 + marker must be skipped');
        $this->assertTrue($jobResult->isSuccess());
        $this->assertSame(16, $jobResult->getExitCode());
        $this->assertNotNull($jobResult->getSkipReason());
    }

    /** @test */
    public function output_handler_receives_on_job_skipped_for_tolerated_empty_input()
    {
        $spy = new OutputHandlerSpy();

        $job = new class (new JobConfiguration('phpstan_luz', 'phpstan', ['paths' => ['src']])) extends PhpstanJob {
            public function buildCommand(): string
            {
                return 'sh -c \'printf "[ERROR] No files found to analyse.\\n" >&2; exit 1\'';
            }
        };

        $executor = new FlowExecutor($spy);
        $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration(false, 1)));

        $this->assertSame(['phpstan_luz'], $spy->skippedJobNames(), 'onJobSkipped called for the tolerated job');
        $this->assertSame([], $spy->successfulJobs, 'onJobSuccess NOT called — skipped is its own status');
        $this->assertSame([], $spy->errorJobs, 'onJobError NOT called');
    }
}
