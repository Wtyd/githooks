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
    public function it_prints_codeclimate_to_stdout_by_default()
    {
        $defaultPath = getcwd() . '/gl-code-quality-report.json';
        @unlink($defaultPath);

        try {
            $this->artisan("flow qa --format=codeclimate --config=$this->configPath")
                ->assertExitCode(0);

            $this->containsStringInOutput = ['['];
            $this->assertFileDoesNotExist($defaultPath, 'no magic default file should be created');
        } finally {
            @unlink($defaultPath);
        }
    }

    /** @test */
    public function it_writes_codeclimate_to_custom_output_path()
    {
        $customPath = getcwd() . '/' . self::TESTS_PATH . '/custom-cc.json';
        @unlink($customPath);

        try {
            $this->artisan("flow qa --format=codeclimate --output=$customPath --config=$this->configPath")
                ->assertExitCode(0);

            $this->assertFileExists($customPath);
            $content = file_get_contents($customPath);
            $decoded = json_decode(strval($content), true);
            $this->assertIsArray($decoded, 'custom codeclimate file is not valid JSON');
        } finally {
            @unlink($customPath);
        }
    }

    /** @test */
    public function it_prints_sarif_to_stdout_by_default()
    {
        $defaultPath = getcwd() . '/githooks-results.sarif';
        @unlink($defaultPath);

        try {
            $this->artisan("flow qa --format=sarif --config=$this->configPath")
                ->assertExitCode(0);

            $this->containsStringInOutput = ['2.1.0', 'runs'];
            $this->assertFileDoesNotExist($defaultPath, 'no magic default file should be created');
        } finally {
            @unlink($defaultPath);
        }
    }

    /** @test */
    public function it_writes_sarif_to_custom_output_path()
    {
        $customPath = getcwd() . '/' . self::TESTS_PATH . '/custom.sarif';
        @unlink($customPath);

        try {
            $this->artisan("flow qa --format=sarif --output=$customPath --config=$this->configPath")
                ->assertExitCode(0);

            $this->assertFileExists($customPath);
            $decoded = json_decode(strval(file_get_contents($customPath)), true);
            $this->assertSame('2.1.0', $decoded['version'] ?? null);
        } finally {
            @unlink($customPath);
        }
    }

    /** @test */
    public function it_warns_on_unknown_format_and_falls_back_to_text()
    {
        $this->artisan("flow qa --format=xml --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ["Unknown format 'xml'"];
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
    public function returns_exit_1_when_config_has_validation_errors()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'fail-fast' => false,
                'processes' => 1,
                'executable-prefix' => 123,
            ])
            ->buildInFileSystem();

        $this->artisan("flow qa --config=$this->configPath")
            ->assertExitCode(1);
    }

    /** @test */
    public function returns_error_when_both_exclude_and_only_jobs_options_are_provided()
    {
        $this->artisan("flow qa --exclude-jobs=a --only-jobs=b --config=$this->configPath")
            ->assertExitCode(1);
    }

    /** @test */
    public function runs_only_specified_jobs_when_only_jobs_option_is_used()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['pass_job', 'fail_job']]])
            ->setV3Jobs([
                'pass_job' => ['type' => 'custom', 'script' => '/bin/true'],
                'fail_job' => ['type' => 'custom', 'script' => '/bin/false'],
            ])
            ->buildInFileSystem();

        $this->artisan("flow qa --only-jobs=pass_job --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function applies_fast_branch_mode_to_accelerable_jobs()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->setV3Jobs([
                'phpstan_src' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/true',
                    'paths' => ['src'],
                    'accelerable' => true,
                ],
            ])
            ->buildInFileSystem();

        $fileUtils = $this->app->make(FileUtilsInterface::class);
        if ($fileUtils instanceof FileUtilsFake) {
            $fileUtils->setModifiedfiles(['tests/FooTest.php']);
        }

        $this->artisan("flow qa --fast-branch --config=$this->configPath")
            ->assertExitCode(0);
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

    /** @test */
    public function fast_with_skipped_jobs_does_not_contaminate_stdout_in_json_format()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'custom', 'executablePath' => '/bin/true', 'paths' => ['src'], 'accelerable' => true],
            ])
            ->buildInFileSystem();

        $fileUtils = $this->app->make(FileUtilsInterface::class);
        if ($fileUtils instanceof FileUtilsFake) {
            $fileUtils->setModifiedfiles(['tests/FooTest.php']);
        }

        // The '⏩ was skipped' human banner must NOT appear in the captured
        // stdout when the format is structured — otherwise the JSON payload is
        // unparseable by consumers.
        $this->artisan("flow qa --fast --format=json --config=$this->configPath")
            ->notContainsStringInOutput('was skipped')
            ->assertExitCode(0);
    }

    /** @test */
    public function fast_with_skipped_jobs_surfaces_the_notice_via_output_handler()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'custom', 'executablePath' => '/bin/true', 'paths' => ['src'], 'accelerable' => true],
            ])
            ->buildInFileSystem();

        $fileUtils = $this->app->make(FileUtilsInterface::class);
        if ($fileUtils instanceof FileUtilsFake) {
            $fileUtils->setModifiedfiles(['tests/FooTest.php']);
        }

        // Text format: the streaming handler emits `⏩ jobname (reason)`, which is
        // enough feedback — the command must not duplicate it via its own echo.
        $this->artisan("flow qa --fast --config=$this->configPath")
            ->containsStringInOutput('⏩ phpstan_src')
            ->containsStringInOutput('no staged files')
            ->assertExitCode(0);
    }
}
