<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for the `--` separator that forwards extra arguments to the
 * underlying tool of a single `job` invocation (introduced in 3.1, extended
 * in 3.2 with structured-format support). The `flow` command intentionally
 * does NOT propagate `--` args to its constituent jobs.
 *
 * @group release
 */
class ExtraArgsReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /** @test */
    public function job_passes_extra_args_after_double_dash_to_tool(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['echo_job']]])
            ->setV3Jobs([
                'echo_job' => ['type' => 'custom', 'script' => '/bin/echo base'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks job echo_job --dry-run --config=$this->configPath -- --extra-flag 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('--extra-flag', $this->getActualOutput());
    }

    /** @test */
    public function job_dry_run_shows_extra_args_in_command(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['echo_job']]])
            ->setV3Jobs([
                'echo_job' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks job echo_job --dry-run --config=$this->configPath -- --verbose --strict 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('--verbose', $output);
        $this->assertStringContainsString('--strict', $output);
    }

    /** @test */
    public function flow_ignores_args_after_double_dash(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['job_a', 'job_b']]])
            ->setV3Jobs([
                'job_a' => ['type' => 'custom', 'script' => '/bin/echo job-a'],
                'job_b' => ['type' => 'custom', 'script' => '/bin/echo job-b'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath -- --extra 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringNotContainsString('--extra', $output);
        $this->assertStringContainsString('job-a', $output);
        $this->assertStringContainsString('job-b', $output);
    }

    /** @test */
    public function job_without_double_dash_works_normally(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['echo_job']]])
            ->setV3Jobs([
                'echo_job' => ['type' => 'custom', 'script' => '/bin/echo hello'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks job echo_job --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('/bin/echo hello', $output);
        $this->assertStringNotContainsString('--extra', $output);
    }

    /** @test */
    public function job_extra_args_appear_in_json_format(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['echo_job']]])
            ->setV3Jobs([
                'echo_job' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks job echo_job --dry-run --format=json --config=$this->configPath -- --extra-flag 2>/dev/null", $exitCode);

        $this->assertEquals(0, $exitCode);
        $json = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($json);
        $this->assertStringContainsString('--extra-flag', $json['jobs'][0]['command'] ?? '');
    }

    /** @test */
    public function job_real_execution_passes_extra_args_to_tool(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['echo_job']]])
            ->setV3Jobs([
                'echo_job' => ['type' => 'custom', 'script' => '/bin/echo base'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks job echo_job --format=json --config=$this->configPath -- --appended 2>/dev/null", $exitCode);

        $this->assertEquals(0, $exitCode);
        $json = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($json);
        $this->assertStringContainsString('base --appended', $json['jobs'][0]['output'] ?? '');
    }

    /**
     * 3.2 added the `--` separator to all job dry-runs with JSON output —
     * the structured payload's `command` must reflect the appended args
     * verbatim regardless of order or quoting.
     *
     * @test
     */
    public function separator_dash_dash_appends_extra_args_to_tool_command(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['job_with_args']]])
            ->setV3Jobs([
                'job_with_args' => [
                    'type'           => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths'          => ['src'],
                ],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $output = (string) shell_exec(
            "$this->githooks job job_with_args --dry-run --format=json --config=$this->configPath -- --custom-flag=value extra-arg 2>/dev/null"
        );
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $command = $decoded['jobs'][0]['command'];
        $this->assertStringContainsString('--custom-flag=value', $command);
        $this->assertStringContainsString('extra-arg', $command);
    }
}
