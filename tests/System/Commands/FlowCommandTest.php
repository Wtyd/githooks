<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Doubles\FileUtilsFake;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

class FlowCommandTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();
    }

    /** @test */
    public function it_runs_a_flow_with_all_jobs_passing()
    {
        $this->artisan("flow qa --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['passed'];
    }

    /** @test */
    public function it_shows_error_for_undefined_flow()
    {
        $this->artisan("flow nonexistent --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['is not defined'];
    }

    /** @test */
    public function it_shows_available_flows_when_undefined()
    {
        $this->artisan("flow nonexistent --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['Available flows'];
    }

    /** @test */
    public function it_shows_error_for_legacy_config()
    {
        // Overwrite with legacy config
        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->buildInFileSystem();

        $this->artisan("flow qa --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['requires v3'];
    }

    /** @test */
    public function it_excludes_jobs_via_cli_option()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['pass_job', 'excluded_job']]])
            ->setV3Jobs([
                'pass_job' => ['type' => 'custom', 'script' => '/bin/true'],
                'excluded_job' => ['type' => 'custom', 'script' => '/bin/false'],
            ])
            ->buildInFileSystem();

        // Without exclude: exit 1 (excluded_job fails)
        // With exclude: exit 0 (only pass_job runs)
        $this->artisan("flow qa --exclude-jobs=excluded_job --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function it_applies_fail_fast_via_cli_option()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['ok_first', 'fail_mid', 'should_skip']]])
            ->setV3Jobs([
                'ok_first' => ['type' => 'custom', 'script' => '/bin/true'],
                'fail_mid' => ['type' => 'custom', 'script' => '/bin/false'],
                'should_skip' => ['type' => 'custom', 'script' => '/bin/true'],
            ])
            ->buildInFileSystem();

        $this->artisan("flow qa --fail-fast --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['skipped by fail-fast'];
    }

    /** @test */
    public function it_applies_processes_override_via_cli()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['job1', 'job2']]])
            ->setV3Jobs([
                'job1' => ['type' => 'custom', 'script' => '/bin/true'],
                'job2' => ['type' => 'custom', 'script' => '/bin/true'],
            ])
            ->buildInFileSystem();

        $this->artisan("flow qa --processes=2 --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function it_supports_json_output_format()
    {
        $this->artisan("flow qa --format=json --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['"flow"', '"success"', '"jobs"'];
    }

    /** @test */
    public function it_supports_junit_output_format()
    {
        $this->artisan("flow qa --format=junit --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['<?xml', 'testsuite'];
    }

    /** @test */
    public function it_shows_monitor_report()
    {
        $this->artisan("flow qa --monitor --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['Thread monitor'];
    }

    /** @test */
    public function it_returns_exit_1_when_a_job_fails()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['failing_job']]])
            ->setV3Jobs([
                'failing_job' => ['type' => 'custom', 'script' => '/bin/false'],
            ])
            ->buildInFileSystem();

        $this->artisan("flow qa --config=$this->configPath")
            ->assertExitCode(1);
    }

    /** @test */
    public function it_skips_accelerable_jobs_when_no_staged_files_match_in_fast_mode()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'custom', 'executablePath' => '/bin/true', 'paths' => ['src'], 'accelerable' => true],
            ])
            ->buildInFileSystem();

        // Staged files that don't match the job's paths
        $fileUtils = $this->app->make(FileUtilsInterface::class);
        if ($fileUtils instanceof FileUtilsFake) {
            $fileUtils->setModifiedfiles(['tests/FooTest.php']);
        }

        $this->artisan("flow qa --fast --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['skipped'];
    }
}
