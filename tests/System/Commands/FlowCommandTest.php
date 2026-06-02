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
            ->assertExitCode(0)
            ->containsStringInOutput('passed');
    }

    /** @test */
    public function it_shows_error_for_undefined_flow()
    {
        $this->artisan("flow nonexistent --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput('is not defined');
    }

    /** @test */
    public function it_shows_available_flows_when_undefined()
    {
        $this->artisan("flow nonexistent --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput('Available flows');
    }

    /** @test */
    public function it_shows_error_for_legacy_config()
    {
        // Overwrite with legacy config
        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->buildInFileSystem();

        $this->artisan("flow qa --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput('requires v3');
    }

    /** @test */
    public function it_excludes_jobs_via_cli_option()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['pass_job', 'excluded_job']]])
            ->setV3Jobs([
                'pass_job' => ['type' => 'custom', 'script' => 'true'],
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
                'ok_first' => ['type' => 'custom', 'script' => 'true'],
                'fail_mid' => ['type' => 'custom', 'script' => '/bin/false'],
                'should_skip' => ['type' => 'custom', 'script' => 'true'],
            ])
            ->buildInFileSystem();

        $this->artisan("flow qa --fail-fast --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput('skipped by fail-fast');
    }

    /** @test */
    public function it_applies_processes_override_via_cli()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['job1', 'job2']]])
            ->setV3Jobs([
                'job1' => ['type' => 'custom', 'script' => 'true'],
                'job2' => ['type' => 'custom', 'script' => 'true'],
            ])
            ->buildInFileSystem();

        $this->artisan("flow qa --processes=2 --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function it_supports_json_output_format()
    {
        $this->artisan("flow qa --format=json --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('"flow"')
            ->containsStringInOutput('"success"')
            ->containsStringInOutput('"jobs"');
    }

    /** @test */
    public function it_supports_junit_output_format()
    {
        $this->artisan("flow qa --format=junit --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('<?xml')
            ->containsStringInOutput('testsuite');
    }

    /** @test */
    public function it_prints_codeclimate_to_stdout_by_default()
    {
        $defaultPath = getcwd() . '/gl-code-quality-report.json';
        @unlink($defaultPath);

        try {
            $this->artisan("flow qa --format=codeclimate --config=$this->configPath")
                ->assertExitCode(0)
                ->containsStringInOutput('[');
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
                ->assertExitCode(0)
                ->containsStringInOutput('2.1.0')
                ->containsStringInOutput('runs');
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
            ->assertExitCode(0)
            ->containsStringInOutput("Unknown format 'xml'");
    }

    /** @test */
    public function it_shows_monitor_report()
    {
        $this->artisan("flow qa --monitor --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('Thread monitor');
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

    /** @test FEAT-15: claude-code blocks a failing flow via stdout JSON but exits 0. */
    public function it_emits_block_json_with_exit_0_on_failure_in_claude_code_format()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['failing_job']]])
            ->setV3Jobs([
                'failing_job' => ['type' => 'custom', 'script' => '/bin/false'],
            ])
            ->buildInFileSystem();

        // The job fails, yet the stop-hook protocol requires exit 0 so the
        // JSON is honoured (a non-zero exit would surface stderr instead).
        // stdout must stay a clean JSON payload — the conditions header must not leak.
        $this->artisan("flow qa --format=claude-code --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('"decision":"block"')
            ->containsStringInOutput('"reason"')
            ->containsStringInOutput('## failing_job')
            ->notContainsStringInOutput('Settings:');
    }

    /** @test FEAT-15: claude-code is silent on success so the agent is not blocked. */
    public function it_is_silent_with_exit_0_on_success_in_claude_code_format()
    {
        $this->artisan("flow qa --format=claude-code --config=$this->configPath")
            ->assertExitCode(0)
            ->notContainsStringInOutput('decision')
            ->notContainsStringInOutput('block');
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
                'pass_job' => ['type' => 'custom', 'script' => 'true'],
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
                    'executablePath' => 'true',
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
                'phpstan_src' => ['type' => 'custom', 'executablePath' => 'true', 'paths' => ['src'], 'accelerable' => true],
            ])
            ->buildInFileSystem();

        // Staged files that don't match the job's paths. Bind a configured fake
        // instance so it reaches the lazily resolved FlowRunner.
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles(['tests/FooTest.php']);
        $this->app->instance(FileUtilsInterface::class, $fileUtils);

        $this->artisan("flow qa --fast --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('no staged files match its paths');
    }

    /** @test */
    public function fast_with_skipped_jobs_does_not_contaminate_stdout_in_json_format()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'custom', 'executablePath' => 'true', 'paths' => ['src'], 'accelerable' => true],
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
                'phpstan_src' => ['type' => 'custom', 'executablePath' => 'true', 'paths' => ['src'], 'accelerable' => true],
            ])
            ->buildInFileSystem();

        // Empty change set → fast mode has nothing to validate, so the
        // accelerable job is skipped and the handler emits the `⏩` notice.
        // Bind an explicit empty fake instance so the (lazily resolved) FlowRunner
        // sees it — deterministic, independent of the real git index (e.g. in a hook).
        $fileUtils = new FileUtilsFake();
        $fileUtils->setModifiedfiles([]);
        $this->app->instance(FileUtilsInterface::class, $fileUtils);

        // Text format: the streaming handler emits `⏩ jobname (reason)`, which is
        // enough feedback — the command must not duplicate it via its own echo.
        $this->artisan("flow qa --fast --config=$this->configPath")
            ->containsStringInOutput('⏩ phpstan_src')
            ->containsStringInOutput('no changes to validate')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_writes_multiple_report_files_via_cli_flags()
    {
        $sarifPath = getcwd() . '/' . self::TESTS_PATH . '/multireport.sarif';
        $junitPath = getcwd() . '/' . self::TESTS_PATH . '/multireport.xml';
        @unlink($sarifPath);
        @unlink($junitPath);

        try {
            $this->artisan(
                "flow qa --report-sarif=$sarifPath --report-junit=$junitPath --config=$this->configPath"
            )->assertExitCode(0);

            $this->assertFileExists($sarifPath);
            $sarif = json_decode(strval(file_get_contents($sarifPath)), true);
            $this->assertSame('2.1.0', $sarif['version'] ?? null);

            $this->assertFileExists($junitPath);
            $this->assertNotFalse(
                simplexml_load_string(strval(file_get_contents($junitPath))),
                'JUnit report file is not valid XML'
            );
        } finally {
            @unlink($sarifPath);
            @unlink($junitPath);
        }
    }

    /** @test */
    public function it_writes_report_files_from_declarative_reports_config()
    {
        $sarifPath = getcwd() . '/' . self::TESTS_PATH . '/declarative.sarif';
        $junitPath = getcwd() . '/' . self::TESTS_PATH . '/declarative.xml';
        @unlink($sarifPath);
        @unlink($junitPath);

        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows([
                'qa' => [
                    'jobs' => ['ok'],
                    'options' => [
                        'reports' => [
                            'sarif' => $sarifPath,
                            'junit' => $junitPath,
                        ],
                    ],
                ],
            ])
            ->setV3Jobs(['ok' => ['type' => 'custom', 'script' => 'true']])
            ->buildInFileSystem();

        try {
            $this->artisan("flow qa --config=$this->configPath")
                ->assertExitCode(0);

            $this->assertFileExists($sarifPath);
            $this->assertFileExists($junitPath);
        } finally {
            @unlink($sarifPath);
            @unlink($junitPath);
        }
    }

    /** @test */
    public function no_reports_flag_silences_config_but_keeps_cli_targets()
    {
        $configSarif = getcwd() . '/' . self::TESTS_PATH . '/config.sarif';
        $cliJunit = getcwd() . '/' . self::TESTS_PATH . '/cli.xml';
        @unlink($configSarif);
        @unlink($cliJunit);

        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows([
                'qa' => [
                    'jobs' => ['ok'],
                    'options' => [
                        'reports' => ['sarif' => $configSarif],
                    ],
                ],
            ])
            ->setV3Jobs(['ok' => ['type' => 'custom', 'script' => 'true']])
            ->buildInFileSystem();

        try {
            $this->artisan(
                "flow qa --no-reports --report-junit=$cliJunit --config=$this->configPath"
            )->assertExitCode(0);

            $this->assertFileDoesNotExist(
                $configSarif,
                '--no-reports must skip the sarif declared in config'
            );
            $this->assertFileExists(
                $cliJunit,
                '--no-reports must NOT cancel the CLI --report-junit flag'
            );
        } finally {
            @unlink($configSarif);
            @unlink($cliJunit);
        }
    }

    /**
     * BUG-21 · casilla #1 — flag desconocido `--foo=bar` coexistiendo con
     * `--config=X`. Antes del fix, `ignoreValidationErrors()` silenciaba
     * `--foo` y descolocaba el parser perdiendo `--config`. Post-fix Symfony
     * rechaza nativamente.
     *
     * @test
     */
    public function unknown_long_option_with_value_returns_exit_1_and_does_not_execute(): void
    {
        $this->artisan("flow qa --foo=bar --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput('--foo')
            ->notContainsStringInOutput('Settings:');
    }

    /**
     * BUG-21 · casilla #4 — shortcut desconocido `-x` antes del `--`.
     *
     * @test
     */
    public function unknown_short_option_returns_exit_1(): void
    {
        $this->artisan("flow qa -x --config=$this->configPath")
            ->assertExitCode(1)
            ->notContainsStringInOutput('Settings:');
    }

    /**
     * BUG-21 · casilla #5 — múltiples flags desconocidos en la misma línea.
     * Verifica que al menos uno aparece en el error y que no se ejecuta.
     *
     * @test
     */
    public function multiple_unknown_options_return_exit_1(): void
    {
        $this->artisan("flow qa --foo --bar --config=$this->configPath")
            ->assertExitCode(1)
            ->notContainsStringInOutput('Settings:');
    }

    /**
     * BUG-21 · casilla #3 — `flow` no soporta el separador `--` (vestigio
     * del commit `8eab746` ya retirado del flujo de extracción).
     *
     * @test
     */
    public function dash_dash_separator_is_rejected_with_custom_message(): void
    {
        $this->artisan("flow qa --config=$this->configPath -- something")
            ->assertExitCode(1)
            ->containsStringInOutput('flow')
            ->containsStringInOutput('does not support')
            ->containsStringInOutput('--')
            ->notContainsStringInOutput('Settings:');
    }

    /** @test FEAT-14: --diag prints the runtime diagnostics block (text format). */
    public function it_prints_the_diagnostics_block_with_the_diag_flag(): void
    {
        // The block's header starts with "githooks <version> …"; present in both
        // the compact (local) and multiline (CI) shapes. The normal flow output
        // never contains "githooks", so this uniquely identifies the diag block.
        $this->artisan("flow qa --diag --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('githooks')
            ->containsStringInOutput('cpus');
    }

    /** @test FEAT-14: JSON v2 always carries the runtime node + per-job timestamps. */
    public function json_output_includes_runtime_node_and_per_job_timestamps(): void
    {
        $this->artisan("flow qa --format=json --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('"runtime"')
            ->containsStringInOutput('"githooksVersion"')
            ->containsStringInOutput('"startedAt"')
            ->containsStringInOutput('"endedAt"');
    }
}
