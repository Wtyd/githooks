<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for v3.1 features:
 * - Local override (githooks.local.php)
 * - executable-prefix option
 * - CLI extra arguments (-- separator, job command only)
 *
 * @group release
 */
class V31FeaturesReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    private string $localConfigPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->localConfigPath = self::TESTS_PATH . '/githooks.local.php';

        $this->configurationFileBuilder->enableV3Mode();
    }

    protected function tearDown(): void
    {
        @unlink($this->localConfigPath);
        parent::tearDown();
    }

    // ========================================================================
    // CLI extra arguments (-- separator, job only — flow ignores them)
    // ========================================================================

    /** @test */
    public function job_passes_extra_args_after_double_dash_to_tool()
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
    public function job_dry_run_shows_extra_args_in_command()
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
    public function flow_ignores_args_after_double_dash()
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
        // Flow ignores -- args: they should NOT appear in the commands
        $this->assertStringNotContainsString('--extra', $output);
        $this->assertStringContainsString('job-a', $output);
        $this->assertStringContainsString('job-b', $output);
    }

    /** @test */
    public function job_without_double_dash_works_normally()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['echo_job']]])
            ->setV3Jobs([
                'echo_job' => ['type' => 'custom', 'script' => '/bin/echo hello'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // Without --, dry-run should show the command without extra args
        passthru("$this->githooks job echo_job --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('/bin/echo hello', $output);
        $this->assertStringNotContainsString('--extra', $output);
    }

    // ========================================================================
    // executable-prefix
    // ========================================================================

    /** @test */
    public function executable_prefix_is_prepended_to_command()
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['executable-prefix' => '/bin/echo PREFIX'])
            ->setV3Flows(['qa' => ['jobs' => ['my_job']]])
            ->setV3Jobs([
                'my_job' => [
                    'type' => 'custom',
                    'executablePath' => 'original-command',
                    'paths' => ['src'],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('PREFIX original-command', $this->getActualOutput());
    }

    /** @test */
    public function per_job_executable_prefix_overrides_global()
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['executable-prefix' => '/bin/echo GLOBAL'])
            ->setV3Flows(['qa' => ['jobs' => ['job_a', 'job_b']]])
            ->setV3Jobs([
                'job_a' => [
                    'type' => 'custom',
                    'executablePath' => 'tool-a',
                    'paths' => ['src'],
                ],
                'job_b' => [
                    'type' => 'custom',
                    'executablePath' => 'tool-b',
                    'paths' => ['src'],
                    'executable-prefix' => '/bin/echo LOCAL',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('GLOBAL tool-a', $output);
        $this->assertStringContainsString('LOCAL tool-b', $output);
    }

    /** @test */
    public function per_job_null_prefix_opts_out_of_global()
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['executable-prefix' => '/bin/echo GLOBAL'])
            ->setV3Flows(['qa' => ['jobs' => ['job_a', 'job_b']]])
            ->setV3Jobs([
                'job_a' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                ],
                'job_b' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                    'executable-prefix' => null,
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        // job_a has global prefix, job_b does not
        $this->assertStringContainsString('GLOBAL /bin/echo', $output);
        // job_b should start with /bin/echo directly (no GLOBAL prefix)
        $this->assertMatchesRegularExpression('/job_b.*\n\s+\/bin\/echo src/', $output);
    }

    // ========================================================================
    // Local override (githooks.local.php)
    // ========================================================================

    /** @test */
    public function local_override_merges_executable_prefix()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['my_job']]])
            ->setV3Jobs([
                'my_job' => [
                    'type' => 'custom',
                    'executablePath' => 'original-tool',
                    'paths' => ['src'],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // Local override adds executable-prefix under flows.options
        file_put_contents($this->localConfigPath, '<?php return [
            "flows" => ["options" => ["executable-prefix" => "/bin/echo DOCKER"]],
        ];');

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('DOCKER original-tool', $this->getActualOutput());
    }

    /** @test */
    public function local_override_replaces_job_property()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['my_job']]])
            ->setV3Jobs([
                'my_job' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                    'otherArguments' => '--original',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // Local override changes otherArguments
        file_put_contents($this->localConfigPath, '<?php return [
            "jobs" => [
                "my_job" => ["otherArguments" => "--overridden"],
            ],
        ];');

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('--overridden', $output);
    }

    /** @test */
    public function without_local_override_works_normally()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['my_job']]])
            ->setV3Jobs([
                'my_job' => ['type' => 'custom', 'script' => '/bin/echo no-override'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // No githooks.local.php exists — dry-run shows command normally
        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('/bin/echo no-override', $this->getActualOutput());
    }

    // ========================================================================
    // Combined: all v3.1 features together
    // ========================================================================

    /** @test */
    public function job_extra_args_appear_in_json_format()
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

        passthru("$this->githooks job echo_job --dry-run --format=json --config=$this->configPath -- --extra-flag 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertStringContainsString('--extra-flag', $json['jobs'][0]['command'] ?? '');
    }

    /** @test */
    public function job_real_execution_passes_extra_args_to_tool()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['echo_job']]])
            ->setV3Jobs([
                'echo_job' => ['type' => 'custom', 'script' => '/bin/echo base'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // Real execution with --format=json to capture the tool's output
        passthru("$this->githooks job echo_job --format=json --config=$this->configPath -- --appended 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $json = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($json);
        $this->assertStringContainsString('base --appended', $json['jobs'][0]['output'] ?? '');
    }

    /** @test */
    public function per_job_empty_prefix_opts_out_of_global()
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['executable-prefix' => '/bin/echo GLOBAL'])
            ->setV3Flows(['qa' => ['jobs' => ['job_a', 'job_b']]])
            ->setV3Jobs([
                'job_a' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                ],
                'job_b' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                    'executable-prefix' => '',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        // job_a has global prefix, job_b opted out with empty string
        $this->assertStringContainsString('GLOBAL /bin/echo', $output);
        $this->assertMatchesRegularExpression('/job_b.*\n\s+\/bin\/echo src/', $output);
    }

    // ========================================================================
    // Combined: local override + executable-prefix
    // ========================================================================

    /** @test */
    public function local_override_and_prefix_work_together()
    {
        // Base config: job without prefix
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['my_job']]])
            ->setV3Jobs([
                'my_job' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // Local override: add executable-prefix (simulating Docker)
        file_put_contents($this->localConfigPath, '<?php return [
            "flows" => ["options" => ["executable-prefix" => "/bin/echo DOCKER"]],
        ];');

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();

        // Both features visible in the command:
        // 1. executable-prefix from local override
        $this->assertStringContainsString('DOCKER', $output);
        // 2. original executable + paths
        $this->assertStringContainsString('/bin/echo', $output);
        $this->assertStringContainsString('src', $output);
    }
}
