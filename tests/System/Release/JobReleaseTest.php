<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class JobReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';

        $this->configurationFileBuilder->enableV3Mode();

        file_put_contents(
            $this->configPath,
            $this->configurationFileBuilder->buildV3Php()
        );
    }

    /** @test */
    public function it_executes_single_job()
    {
        passthru("$this->githooks job phpcs_src --config=$this->configPath", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('passed', $this->getActualOutput());
    }

    /** @test */
    public function it_shows_command_in_dry_run()
    {
        passthru("$this->githooks job phpcs_src --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        // The dry-run renderer indents the command with 5 leading spaces; the
        // exact match keeps the assert tight enough to discriminate the
        // generated `true` script from any other 'true' substring that may
        // appear in headers / settings (e.g. fail-fast=true).
        $this->assertStringContainsString("     true\n", $this->getActualOutput());
    }

    /** @test */
    public function it_outputs_json_format()
    {
        // Discard stderr: in v3.2 structured formats route progress to stderr;
        // mixing it into stdout would break json_decode.
        passthru("$this->githooks job phpcs_src --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $decoded = json_decode($output, true);
        $this->assertNotNull($decoded, 'Output is not valid JSON: ' . $output);
        $this->assertArrayHasKey('jobs', $decoded);
    }

    /** @test */
    public function it_applies_fast_mode_to_single_job()
    {
        $this->configurationFileBuilder
            ->setV3Jobs([
                'lint_job' => ['type' => 'custom', 'executablePath' => 'true', 'paths' => ['src'], 'accelerable' => true],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['lint_job']]]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks job lint_job --fast --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_applies_fast_branch_mode()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['lint_job']]])
            ->setV3Jobs([
                'lint_job' => ['type' => 'custom', 'executablePath' => 'true', 'paths' => ['src'], 'accelerable' => true],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks job lint_job --fast-branch --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_exits_with_error_for_undefined_job()
    {
        passthru("$this->githooks job nonexistent --config=$this->configPath 2>&1", $exitCode);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('is not defined', $this->getActualOutput());
    }

    // ─── 3.2 · new native job types (php-cs-fixer, rector) ────────────

    /**
     * 3.2 — `type: php-cs-fixer` builds a command that calls the binary's
     * `fix` subcommand against the configured `paths`.
     *
     * @test
     */
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

    /**
     * 3.2 — `type: rector` builds a command that calls the binary's `process`
     * subcommand against the configured `paths` and honours the `dry-run` key.
     *
     * @test
     */
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

    /**
     * BUG-26 — the literal reproduction: three Jest shards sharing a base via
     * `extends`, each adding its own `other-arguments`. In legacy mode (custom
     * job without `paths`) `other-arguments` used to be dropped, so the three
     * shards built the identical `yarn tests:ci` command. The fix lives in
     * `src/Jobs/CustomJob.php`, embedded in the `.phar`; without it compiled
     * into the bundled binary the three commands collapse and this fails.
     *
     * Covers AC-004 (extends + other-arguments → 3 distinct commands) and
     * AC-005 (fix exercised over the `.phar`).
     *
     * @test
     */
    public function custom_legacy_extends_shards_build_distinct_commands_in_dry_run()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['jest_ci_shard_1', 'jest_ci_shard_2', 'jest_ci_shard_3']]])
            ->setV3Jobs([
                'jest_base'       => ['type' => 'custom', 'script' => 'yarn tests:ci'],
                'jest_ci_shard_1' => ['extends' => 'jest_base', 'other-arguments' => '--shard 1/3'],
                'jest_ci_shard_2' => ['extends' => 'jest_base', 'other-arguments' => '--shard 2/3'],
                'jest_ci_shard_3' => ['extends' => 'jest_base', 'other-arguments' => '--shard 3/3'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $output = $this->getActualOutput();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('yarn tests:ci --shard 1/3', $output);
        $this->assertStringContainsString('yarn tests:ci --shard 2/3', $output);
        $this->assertStringContainsString('yarn tests:ci --shard 3/3', $output);
    }
}
