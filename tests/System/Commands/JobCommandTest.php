<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Doubles\FileUtilsFake;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

class JobCommandTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();
    }

    /** @test */
    public function it_runs_a_single_job_successfully()
    {
        $this->artisan("job phpcs_src --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['passed'];
    }

    /** @test */
    public function it_shows_error_for_undefined_job()
    {
        $this->artisan("job nonexistent --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['is not defined'];
    }

    /** @test */
    public function it_shows_available_jobs_when_undefined()
    {
        $this->artisan("job nonexistent --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['Available jobs'];
    }

    /** @test */
    public function it_shows_error_for_legacy_config()
    {
        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->buildInFileSystem();

        $this->artisan("job phpcs_src --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['requires v3'];
    }

    /** @test */
    public function it_supports_json_output_format()
    {
        $this->artisan("job phpcs_src --format=json --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['"flow"', '"success"', '"jobs"'];
    }

    /** @test */
    public function it_supports_junit_output_format()
    {
        $this->artisan("job phpcs_src --format=junit --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['<?xml', 'testsuite'];
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

        $this->artisan("job phpcs_src --config=$this->configPath")
            ->assertExitCode(1);
    }

    /** @test */
    public function applies_fast_branch_mode_to_accelerable_job()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Jobs([
                'lint_job' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                    'accelerable' => true,
                ],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['lint_job']]])
            ->buildInFileSystem();

        $fileUtils = $this->app->make(FileUtilsInterface::class);
        if ($fileUtils instanceof FileUtilsFake) {
            $fileUtils->setModifiedfiles(['tests/FooTest.php']);
        }

        $this->artisan("job lint_job --fast-branch --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function parses_cli_extra_arguments_after_double_dash()
    {
        $originalArgv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['githooks', 'job', 'phpcs_src', '--', 'extra-one', 'extra-two'];

        try {
            $this->artisan("job phpcs_src --dry-run --config=$this->configPath")
                ->assertExitCode(0);
        } finally {
            $_SERVER['argv'] = $originalArgv;
        }
    }

    /** @test */
    public function it_applies_fast_mode_to_accelerable_job()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Jobs([
                'lint_job' => ['type' => 'custom', 'executablePath' => '/bin/echo', 'paths' => ['src'], 'accelerable' => true],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['lint_job']]])
            ->buildInFileSystem();

        $fileUtils = $this->app->make(FileUtilsInterface::class);
        if ($fileUtils instanceof FileUtilsFake) {
            $fileUtils->setModifiedfiles(['src/Modified.php']);
            $fileUtils->setFilesThatShouldBeFoundInDirectories(['src/Modified.php']);
        }

        $this->artisan("job lint_job --fast --dry-run --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['src/Modified.php'];
    }

    /** @test */
    public function it_writes_a_report_file_via_cli_flag()
    {
        $sarifPath = getcwd() . '/' . self::TESTS_PATH . '/job-multireport.sarif';
        @unlink($sarifPath);

        try {
            $this->artisan("job phpcs_src --report-sarif=$sarifPath --config=$this->configPath")
                ->assertExitCode(0);

            $this->assertFileExists($sarifPath);
            $sarif = json_decode(strval(file_get_contents($sarifPath)), true);
            $this->assertSame('2.1.0', $sarif['version'] ?? null);
        } finally {
            @unlink($sarifPath);
        }
    }

    /**
     * BUG-21 · casilla #9 — flag desconocido `--foo=bar` antes del `--`
     * coexistiendo con `--config=X`. Antes del fix, `--foo` se silenciaba y
     * `--config` se perdía. Post-fix, el concern detecta `--foo` y aborta con
     * exit 1, sin ejecutar el job.
     *
     * @test
     */
    public function unknown_long_option_before_dashdash_returns_exit_1_without_losing_config(): void
    {
        $this->artisan("job phpcs_src --foo=bar --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['--foo'];
        $this->notContainsStringInOutput = ['passed'];
    }

    /**
     * BUG-21 · casilla #10 — flag desconocido antes del `--`, args válidos
     * después. El error sobre `--foo` debe primar; el tool no debe ejecutarse.
     *
     * @test
     */
    public function unknown_option_before_dashdash_still_errors_when_passthrough_exists(): void
    {
        $this->artisan("job phpcs_src --foo=bar --config=$this->configPath -- --filter=X")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['--foo'];
    }

    /**
     * BUG-21 · casilla #13 — shortcut desconocido (`-x`).
     *
     * @test
     */
    public function unknown_short_option_returns_exit_1(): void
    {
        $this->artisan("job phpcs_src -x --config=$this->configPath")
            ->assertExitCode(1);

        $this->notContainsStringInOutput = ['passed'];
    }

    /**
     * BUG-21 · casilla #12 — flag con pinta de githooks (`--config=Y`) **después**
     * del `--` se pasa al tool tal cual, sin que githooks lo procese ni cause
     * error. No-regresión del passthrough.
     *
     * @test
     */
    public function known_looking_option_after_dashdash_is_passthrough_not_validated(): void
    {
        $this->artisan("job phpcs_src --config=$this->configPath -- --config=/tmp/looks-like-githooks-but-goes-to-tool.php")
            ->assertExitCode(0);
    }
}
