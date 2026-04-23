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

        passthru("$this->githooks flow qa --format=codeclimate --config=$this->configPath 2>/dev/null", $exitCode);

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

        passthru("$this->githooks flow qa --format=sarif --config=$this->configPath 2>/dev/null", $exitCode);

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
    public function progress_is_silent_without_tty_and_json_payload_stays_on_stdout()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => '/bin/true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // stderr is redirected to a file → not a TTY → progress handler is silent
        // by default. stdout must still carry a clean JSON payload.
        $stderrPath = self::TESTS_PATH . '/stderr.log';
        passthru("$this->githooks flow qa --format=json --config=$this->configPath 2>$stderrPath", $exitCode);

        $stdout = $this->getActualOutput();
        $stderr = (string) file_get_contents($stderrPath);

        $this->assertNotNull(json_decode($stdout, true), 'stdout should be decodable JSON: ' . $stdout);
        $this->assertSame('', trim($stderr), 'stderr should be silent off a TTY without -v');
    }

    /** @test */
    public function verbose_flag_forces_progress_on_stderr_even_without_tty()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => '/bin/true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $stderrPath = self::TESTS_PATH . '/stderr.log';
        passthru("$this->githooks flow qa --format=json -v --config=$this->configPath 2>$stderrPath", $exitCode);

        $stdout = $this->getActualOutput();
        $stderr = (string) file_get_contents($stderrPath);

        $this->assertNotNull(json_decode($stdout, true), 'stdout should be decodable JSON: ' . $stdout);
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

    // ========================================================================
    // Payload guarantees (regression guards for 3.2 bugfixes)
    // ========================================================================

    /** @test */
    public function json_executionMode_reflects_the_cli_flag()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => '/bin/true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $cmd = "$this->githooks flow qa --format=json --config=$this->configPath 2>/dev/null";

        $full = json_decode((string) shell_exec($cmd), true);
        $fast = json_decode((string) shell_exec(str_replace('--format', '--fast --format', $cmd)), true);
        $branch = json_decode((string) shell_exec(str_replace('--format', '--fast-branch --format', $cmd)), true);

        $this->assertSame('full', $full['executionMode']);
        $this->assertSame('fast', $fast['executionMode']);
        $this->assertSame('fast-branch', $branch['executionMode']);
    }

    /** @test */
    public function codeclimate_location_path_is_relative_to_cwd()
    {
        // phpcs emits absolute file paths; the formatter must normalise them.
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        file_put_contents(
            "$srcDir/Bad.php",
            "<?php\nclass Bad { public \$x; }\n"
        );

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_job']]])
            ->setV3Jobs([
                'phpcs_job' => [
                    'type'     => 'phpcs',
                    'standard' => 'PSR12',
                    'paths'    => [$srcDir],
                ],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --format=codeclimate --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded, 'phpcs should have emitted at least one issue');

        foreach ($decoded as $issue) {
            $path = $issue['location']['path'];
            $this->assertStringStartsNotWith('/', $path, "location.path must be relative, got: $path");
        }
    }

    /** @test */
    public function sarif_artifactLocation_uri_is_relative_to_cwd()
    {
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        file_put_contents(
            "$srcDir/Bad.php",
            "<?php\nclass Bad { public \$x; }\n"
        );

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_job']]])
            ->setV3Jobs([
                'phpcs_job' => [
                    'type'     => 'phpcs',
                    'standard' => 'PSR12',
                    'paths'    => [$srcDir],
                ],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --format=sarif --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $sawIssue = false;
        foreach ($decoded['runs'] as $run) {
            foreach ($run['results'] ?? [] as $result) {
                foreach ($result['locations'] ?? [] as $location) {
                    $uri = $location['physicalLocation']['artifactLocation']['uri'] ?? '';
                    $this->assertStringStartsNotWith('/', $uri, "artifactLocation.uri must be relative, got: $uri");
                    $sawIssue = true;
                }
            }
        }
        $this->assertTrue($sawIssue, 'SARIF report should contain at least one result with a location');
    }

    /** @test */
    public function fail_fast_cancelled_jobs_appear_as_skipped_in_json()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_job', 'never_job', 'also_never_job']]])
            ->setV3Jobs([
                'fail_job'        => ['type' => 'custom', 'script' => 'exit 1'],
                'never_job'       => ['type' => 'custom', 'script' => 'echo never'],
                'also_never_job'  => ['type' => 'custom', 'script' => 'echo also'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --fail-fast --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $names = array_column($decoded['jobs'], 'name');
        $this->assertSame(['fail_job', 'never_job', 'also_never_job'], $names, 'jobs[] must contain the full plan');

        $skipped = array_values(array_filter($decoded['jobs'], fn($j) => $j['skipped'] === true));
        $this->assertCount(2, $skipped);
        foreach ($skipped as $job) {
            $this->assertSame('skipped by fail-fast', $job['skipReason']);
        }

        $this->assertSame(2, $decoded['skipped']);
    }

    /** @test */
    public function dry_run_emits_no_progress_on_stderr()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => '/bin/true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $stderrPath = self::TESTS_PATH . '/stderr.log';
        passthru("$this->githooks flow qa --dry-run --format=json --config=$this->configPath 2>$stderrPath", $exitCode);

        $stderr = (string) file_get_contents($stderrPath);
        $this->assertSame('', trim($stderr), 'dry-run should not emit any progress on stderr');
        $this->assertStringNotContainsString('Done.', $stderr, 'the bogus "Done. 0/N completed." banner must be gone');
    }

    // ========================================================================
    // Regression: --output, separator --, JUnit skipped
    // ========================================================================

    /** @test */
    public function output_flag_writes_payload_to_file_for_each_structured_format()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => '/bin/true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $targets = [
            'json'        => self::TESTS_PATH . '/qa.json',
            'junit'       => self::TESTS_PATH . '/qa.xml',
            'codeclimate' => self::TESTS_PATH . '/qa-cc.json',
            'sarif'       => self::TESTS_PATH . '/qa.sarif',
        ];

        foreach ($targets as $format => $path) {
            @unlink($path);
            shell_exec("$this->githooks flow qa --format=$format --output=$path --config=$this->configPath 2>/dev/null");
            $this->assertFileExists($path, "--output=PATH should create the file for format=$format");
            $this->assertNotSame('', (string) file_get_contents($path));
        }
    }

    /** @test */
    public function separator_dash_dash_appends_extra_args_to_tool_command()
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

    /** @test */
    public function junit_skipped_element_is_emitted_for_skipped_jobs()
    {
        // Trigger a skip via --fail-fast — no accelerable job setup required.
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_job', 'never_job']]])
            ->setV3Jobs([
                'fail_job'  => ['type' => 'custom', 'script' => 'exit 1'],
                'never_job' => ['type' => 'custom', 'script' => 'echo never'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $output = (string) shell_exec(
            "$this->githooks flow qa --fail-fast --format=junit --config=$this->configPath 2>/dev/null"
        );

        $this->assertStringContainsString('<?xml', $output);
        $this->assertStringContainsString('<skipped', $output);
        $this->assertStringContainsString('never_job', $output);
    }

    // ========================================================================
    // Misc v3.2 features (truncation, cores conflict warning, dashboard fallback)
    // ========================================================================

    /** @test */
    public function conf_check_truncates_long_commands_to_80_chars()
    {
        // Build a custom job with a deliberately long shell script so the
        // generated command exceeds 80 characters.
        $longScript = '/bin/echo ' . str_repeat('argument-long-enough ', 5) . 'end';
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['long_cmd_job']]])
            ->setV3Jobs([
                'long_cmd_job' => ['type' => 'custom', 'script' => $longScript],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$this->configPath 2>&1", $exitCode);
        $output = $this->getActualOutput();

        // conf:check renders ellipsis to signal truncation (ASCII `...` today,
        // Unicode `…` in some themes). Accept either.
        $this->assertMatchesRegularExpression('/\.{3}|…/u', $output, 'conf:check should truncate long commands');
        // The dry-run path still prints the full command untouched.
        passthru("$this->githooks job long_cmd_job --dry-run --config=$this->configPath 2>&1", $exitCode);
        $dryRunOutput = $this->getActualOutput();
        $this->assertStringContainsString('argument-long-enough argument-long-enough argument-long-enough', $dryRunOutput);
    }

    /** @test */
    public function conf_check_warns_when_cores_conflicts_with_native_flag()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_conflict']]])
            ->setV3Jobs([
                'phpcs_conflict' => [
                    'type'     => 'phpcs',
                    'standard' => 'PSR12',
                    'paths'    => ['src'],
                    'parallel' => 8,
                    'cores'    => 2,
                ],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$this->configPath 2>&1", $exitCode);
        $output = $this->getActualOutput();

        $this->assertStringContainsString("'cores' overrides 'parallel'", $output);
    }

    /** @test */
    public function dashboard_falls_back_to_streaming_when_stdout_is_not_a_tty()
    {
        // Without a TTY (stdout piped to a file here via passthru capture),
        // the parallel dashboard must degrade to append-only streaming: no
        // ANSI cursor-movement, a predictable ⏳ marker per job, and no
        // residual dashboard state after completion.
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['a', 'b', 'c']]])
            ->setV3Jobs([
                'a' => ['type' => 'custom', 'script' => '/bin/true'],
                'b' => ['type' => 'custom', 'script' => '/bin/true'],
                'c' => ['type' => 'custom', 'script' => '/bin/true'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --processes=2 --config=$this->configPath 2>&1", $exitCode);
        $output = $this->getActualOutput();

        $this->assertSame(0, $exitCode);
        // Fallback streams the ⏳ header and the final "- OK" line per job.
        $this->assertStringContainsString('⏳ a', $output);
        $this->assertStringContainsString('a - OK', $output);
        // Cursor-movement escape sequences (`\e[{n}A`) from the TTY dashboard
        // must not appear.
        $this->assertDoesNotMatchRegularExpression('/\x1b\[\d+A/', $output, 'non-TTY output must not contain cursor-up escapes');
    }
}
