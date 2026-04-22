<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for v3.2 features:
 * - JSON v2 schema enrichment (type, exitCode, paths, skipped, fixApplied)
 * - Code Climate output format
 * - SARIF output format
 * - Streaming text + stderr progress split
 * - CI annotations (GitHub Actions)
 * - New native job types: php-cs-fixer, rector
 *
 * @group release
 */
class V32FeaturesReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    // ========================================================================
    // JSON v2 schema enrichment
    // ========================================================================

    /** @test */
    public function json_v2_schema_includes_enriched_fields_per_job()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => '/bin/true', 'paths' => ['src']],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(2, $decoded['version']);
        $this->assertArrayHasKey('executionMode', $decoded);
        $this->assertArrayHasKey('passed', $decoded);
        $this->assertArrayHasKey('failed', $decoded);
        $this->assertArrayHasKey('skipped', $decoded);

        $job = $decoded['jobs'][0];
        $this->assertSame('ok_job', $job['name']);
        $this->assertSame('custom', $job['type']);
        $this->assertSame(0, $job['exitCode']);
        $this->assertSame(['src'], $job['paths']);
        $this->assertFalse($job['skipped']);
        $this->assertNull($job['skipReason']);
        $this->assertFalse($job['fixApplied']);
    }

    // ========================================================================
    // Code Climate format
    // ========================================================================

    /** @test */
    public function codeclimate_format_emits_valid_json_array_to_stdout()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => '/bin/true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --format=codeclimate --stdout --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded, 'Code Climate output must be a JSON array');
    }

    // ========================================================================
    // SARIF format
    // ========================================================================

    /** @test */
    public function sarif_format_emits_valid_2_1_0_report_to_stdout()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => '/bin/true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --format=sarif --stdout --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('2.1.0', $decoded['version'] ?? null);
        $this->assertArrayHasKey('runs', $decoded);
        $this->assertArrayHasKey('$schema', $decoded);
    }

    // ========================================================================
    // Streaming text + stderr progress split
    // ========================================================================

    /** @test */
    public function progress_goes_to_stderr_while_json_payload_stays_on_stdout()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => '/bin/true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $stderrPath = self::TESTS_PATH . '/stderr.log';
        passthru("$this->githooks flow qa --format=json --config=$this->configPath 2>$stderrPath", $exitCode);

        $stdout = $this->getActualOutput();
        $stderr = (string) file_get_contents($stderrPath);

        // stdout is pure JSON
        $this->assertNotNull(json_decode($stdout, true), 'stdout should be decodable JSON: ' . $stdout);

        // stderr contains the progress lines
        $this->assertStringContainsString('OK', $stderr);
        $this->assertStringContainsString('Done.', $stderr);
    }

    // ========================================================================
    // CI annotations (GitHub Actions)
    // ========================================================================

    /** @test */
    public function github_actions_annotations_are_emitted_when_env_var_is_set()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_with_file_location']]])
            ->setV3Jobs([
                'fail_with_file_location' => [
                    'type'   => 'custom',
                    'script' => 'echo "src/Broken.php:42: Unexpected error" && exit 1',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru(
            "GITHUB_ACTIONS=true $this->githooks flow qa --config=$this->configPath 2>&1",
            $exitCode
        );

        $output = $this->getActualOutput();
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('::group::fail_with_file_location', $output);
        $this->assertStringContainsString('::error file=src/Broken.php,line=42::', $output);
        $this->assertStringContainsString('::endgroup::', $output);
    }

    /** @test */
    public function ci_annotations_are_suppressed_when_no_ci_flag_is_passed()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_with_file_location']]])
            ->setV3Jobs([
                'fail_with_file_location' => [
                    'type'   => 'custom',
                    'script' => 'echo "src/Broken.php:42: Unexpected error" && exit 1',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru(
            "GITHUB_ACTIONS=true $this->githooks flow qa --no-ci --config=$this->configPath 2>&1",
            $exitCode
        );

        $output = $this->getActualOutput();
        $this->assertStringNotContainsString('::group::', $output);
        $this->assertStringNotContainsString('::error file=', $output);
    }

    // ========================================================================
    // New native job types
    // ========================================================================

    /** @test */
    public function php_cs_fixer_job_dry_run_emits_fix_subcommand_with_dry_run_flag()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['cs_fixer']]])
            ->setV3Jobs([
                'cs_fixer' => [
                    'type'           => 'php-cs-fixer',
                    'executablePath' => '/bin/echo',
                    'paths'          => ['src'],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $output = $this->getActualOutput();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('/bin/echo fix', $output);
        $this->assertStringContainsString('src', $output);
    }

    /** @test */
    public function rector_job_dry_run_emits_process_subcommand_over_paths()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['rector_job']]])
            ->setV3Jobs([
                'rector_job' => [
                    'type'           => 'rector',
                    'executablePath' => '/bin/echo',
                    'paths'          => ['src'],
                    'dry-run'        => true,
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $output = $this->getActualOutput();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('/bin/echo process', $output);
        $this->assertStringContainsString('--dry-run', $output);
        $this->assertStringContainsString('src', $output);
    }
}
