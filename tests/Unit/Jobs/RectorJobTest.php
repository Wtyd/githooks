<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\RectorJob;

class RectorJobTest extends TestCase
{
    /** @test */
    public function rector_is_a_supported_job_type()
    {
        $this->assertTrue((new JobRegistry())->isSupported('rector'));
    }

    /** @test */
    public function rector_builds_correct_command_with_all_arguments()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executablePath'  => 'vendor/bin/rector',
            'config'          => 'rector.php',
            'dry-run'         => true,
            'clear-cache'     => true,
            'no-progress-bar' => true,
            'paths'           => ['src'],
            'otherArguments'  => '--ansi',
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('vendor/bin/rector process', $command);
        $this->assertStringContainsString('--config=rector.php', $command);
        $this->assertStringContainsString('--dry-run', $command);
        $this->assertStringContainsString('--clear-cache', $command);
        $this->assertStringContainsString('--no-progress-bar', $command);
        $this->assertStringContainsString('--ansi', $command);
        $this->assertStringEndsWith('src', $command);
    }

    /** @test */
    public function rector_uses_default_executable_when_executable_path_is_empty()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths' => ['src'],
        ]));

        $command = $job->buildCommand();

        $this->assertMatchesRegularExpression('/^(vendor\/bin\/)?rector process/', $command);
    }

    /** @test */
    public function rector_includes_dry_run_and_clear_cache_flags()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executablePath' => 'vendor/bin/rector',
            'dry-run'        => true,
            'clear-cache'    => true,
            'paths'          => ['src'],
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('--dry-run', $command);
        $this->assertStringContainsString('--clear-cache', $command);
    }

    /** @test */
    public function rector_runs_against_several_paths()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executablePath' => 'vendor/bin/rector',
            'paths'          => ['src', 'app'],
        ]));

        $this->assertStringEndsWith('src app', $job->buildCommand());
    }

    /** @test */
    public function rector_ignores_unexpected_arguments()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executablePath'     => 'vendor/bin/rector',
            'paths'              => ['src'],
            'unexpected_arg'     => 'value',
            'another_bad_key'    => true,
        ]));

        $command = $job->buildCommand();

        $this->assertStringNotContainsString('unexpected_arg', $command);
        $this->assertStringNotContainsString('another_bad_key', $command);
    }

    /** @test */
    public function rector_detects_fix_applied_on_success()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths' => ['src'],
        ]));

        $this->assertTrue($job->isFixApplied(0));
    }

    /** @test */
    public function rector_does_not_detect_fix_in_dry_run_mode()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'dry-run' => true,
            'paths'   => ['src'],
        ]));

        $this->assertFalse($job->isFixApplied(0));
    }

    /** @test */
    public function rector_does_not_detect_fix_on_error_exit_code()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths' => ['src'],
        ]));

        $this->assertFalse($job->isFixApplied(1));
        $this->assertFalse($job->isFixApplied(2));
    }

    /** @test */
    public function rector_returns_cache_paths()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths' => ['src'],
        ]));

        $this->assertEquals(['/tmp/rector'], $job->getCachePaths());
    }

    /** @test */
    public function rector_has_no_thread_capability()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths' => ['src'],
        ]));

        $this->assertNull($job->getThreadCapability());
    }

    /** @test */
    public function rector_is_accelerable()
    {
        $this->assertTrue((new JobRegistry())->isAccelerable('rector'));
    }

    /** @test */
    public function rector_with_executable_prefix()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executablePath' => 'vendor/bin/rector',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/rector process', $job->buildCommand());
    }

    /** @test */
    public function rector_with_cli_extra_arguments()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executablePath' => 'vendor/bin/rector',
            'paths'          => ['src'],
        ]));
        $job->applyCliExtraArguments('--debug');

        $command = $job->buildCommand();

        $this->assertStringContainsString('--debug', $command);
        $this->assertStringEndsWith('src', $command);
    }
}
