<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class FlowReleaseTest extends ReleaseTestCase
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
    public function it_executes_flow_with_all_jobs_passing()
    {
        passthru("$this->githooks flow qa --config=$this->configPath", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_exits_with_error_for_undefined_flow()
    {
        passthru("$this->githooks flow nonexistent --config=$this->configPath 2>&1", $exitCode);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('is not defined', $this->getActualOutput());
    }

    /** @test */
    public function it_shows_available_flows_when_undefined()
    {
        passthru("$this->githooks flow nonexistent --config=$this->configPath 2>&1", $exitCode);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Available flows', $this->getActualOutput());
    }

    /** @test */
    public function it_excludes_jobs_via_cli()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['pass_job', 'fail_job']]])
            ->setV3Jobs([
                'pass_job' => ['type' => 'custom', 'script' => 'echo pass'],
                'fail_job' => ['type' => 'custom', 'script' => 'echo fail && exit 1'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --exclude-jobs=fail_job --config=$this->configPath", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_skips_accelerable_jobs_in_fast_mode_when_no_staged_files()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['lint_job']]])
            ->setV3Jobs([
                'lint_job' => ['type' => 'custom', 'executablePath' => '/bin/true', 'paths' => ['src'], 'accelerable' => true],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --fast --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('skipped', $this->getActualOutput());
    }

    /** @test */
    public function it_outputs_json_format()
    {
        passthru("$this->githooks flow qa --format=json --config=$this->configPath", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $decoded = json_decode($output, true);
        $this->assertNotNull($decoded, 'Output is not valid JSON: ' . $output);
        $this->assertEquals('qa', $decoded['flow']);
    }

    /** @test */
    public function it_outputs_junit_format()
    {
        passthru("$this->githooks flow qa --format=junit --config=$this->configPath", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('<?xml', $output);
        $this->assertStringContainsString('<testsuite', $output);
        $this->assertStringContainsString('<testcase', $output);
    }

    /** @test */
    public function it_applies_fail_fast_via_cli()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['pass_job', 'fail_job', 'skip_job']]])
            ->setV3Jobs([
                'pass_job' => ['type' => 'custom', 'script' => 'echo pass'],
                'fail_job' => ['type' => 'custom', 'script' => 'echo fail && exit 1'],
                'skip_job' => ['type' => 'custom', 'script' => 'echo should-not-run'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --fail-fast --config=$this->configPath 2>&1", $exitCode);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('skipped by fail-fast', $this->getActualOutput());
    }

    /** @test */
    public function it_applies_only_jobs_filter()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['job_a', 'job_b']]])
            ->setV3Jobs([
                'job_a' => ['type' => 'custom', 'script' => 'echo job-a-output'],
                'job_b' => ['type' => 'custom', 'script' => 'echo job-b-output && exit 1'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --only-jobs=job_a --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_shows_commands_in_dry_run()
    {
        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('/bin/true', $output);
        $this->assertStringContainsString('0ms', $output);
    }

    /** @test */
    public function it_shows_monitor_report()
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['fail-fast' => false, 'processes' => 2])
            ->setV3Flows(['qa' => ['jobs' => ['job_a', 'job_b']]])
            ->setV3Jobs([
                'job_a' => ['type' => 'custom', 'script' => '/bin/true'],
                'job_b' => ['type' => 'custom', 'script' => '/bin/true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --monitor --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Thread monitor', $this->getActualOutput());
    }

    /** @test */
    public function it_applies_processes_override()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['job_a', 'job_b']]])
            ->setV3Jobs([
                'job_a' => ['type' => 'custom', 'script' => '/bin/true'],
                'job_b' => ['type' => 'custom', 'script' => '/bin/true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --processes=2 --monitor --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('budget: 2', $this->getActualOutput());
    }

    /** @test */
    public function it_runs_custom_job_with_script()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['audit']]])
            ->setV3Jobs([
                'audit' => ['type' => 'custom', 'script' => 'echo custom-ok'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_runs_custom_job_with_executable_path_and_paths()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['lint']]])
            ->setV3Jobs([
                'lint' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                    'otherArguments' => '--checked',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('/bin/echo src --checked', $this->getActualOutput());
    }

    /** @test */
    public function it_strips_ansi_from_junit_output()
    {
        file_put_contents(
            self::TESTS_PATH . '/src/File.php',
            $this->phpFileBuilder->build()
        );

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'phpstan', 'level' => 0, 'paths' => [self::TESTS_PATH . '/src']],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // Discard stderr: in v3.2 structured formats route progress to stderr;
        // mixing it into stdout would pollute the JUnit XML with ANSI escapes.
        passthru("$this->githooks flow qa --format=junit --config=$this->configPath 2>/dev/null", $exitCode);

        $output = $this->getActualOutput();
        $this->assertStringNotContainsString("\e[", $output);
    }

    /** @test */
    public function parallel_fail_fast_reports_in_flight_jobs()
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['fail-fast' => false, 'processes' => 2])
            ->setV3Flows(['qa' => ['jobs' => ['fast_fail', 'slow_job', 'queued_job']]])
            ->setV3Jobs([
                'fast_fail' => ['type' => 'custom', 'script' => 'echo "fast output" && exit 1'],
                'slow_job'  => ['type' => 'custom', 'script' => 'echo "slow output" && sleep 5'],
                'queued_job' => ['type' => 'custom', 'script' => 'echo "should not run"'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --fail-fast --config=$this->configPath 2>&1", $exitCode);

        $output = $this->getActualOutput();

        $this->assertNotEquals(0, $exitCode);
        // The in-flight job (slow_job) must appear in the output, not vanish
        $this->assertStringContainsString('fast_fail', $output);
        $this->assertStringContainsString('slow_job', $output);
        // The queued job should be skipped
        $this->assertStringContainsString('skipped by fail-fast', $output);
    }

    /** @test */
    public function parallel_fail_fast_json_includes_in_flight_jobs()
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['fail-fast' => false, 'processes' => 2])
            ->setV3Flows(['qa' => ['jobs' => ['fast_fail', 'slow_job']]])
            ->setV3Jobs([
                'fast_fail' => ['type' => 'custom', 'script' => 'echo "fail" && exit 1'],
                'slow_job'  => ['type' => 'custom', 'script' => 'echo "slow" && sleep 5'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // Discard stderr: in v3.2 structured formats route progress to stderr;
        // mixing it into stdout would break json_decode.
        passthru("$this->githooks flow qa --fail-fast --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $output = $this->getActualOutput();
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded, 'Output is not valid JSON: ' . $output);
        $this->assertCount(2, $decoded['jobs'], 'Both jobs (failed + terminated) must appear in JSON');

        $jobNames = array_column($decoded['jobs'], 'name');
        $this->assertContains('fast_fail', $jobNames);
        $this->assertContains('slow_job', $jobNames);
    }

    /** @test */
    public function it_accepts_fast_branch_flag()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['check']]])
            ->setV3Jobs([
                'check' => ['type' => 'custom', 'script' => '/bin/true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --fast-branch --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_skips_accelerable_jobs_in_fast_branch_when_no_diff_files_match()
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['fail-fast' => false, 'processes' => 1, 'fast-branch-fallback' => 'fast'])
            ->setV3Flows(['qa' => ['jobs' => ['lint_job']]])
            ->setV3Jobs([
                'lint_job' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/true',
                    'paths' => ['nonexistent_test_path_xyz'],
                    'accelerable' => true,
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --fast-branch --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('skipped', $this->getActualOutput());
    }

    /** @test */
    public function it_resolves_job_inheritance_with_extends()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['lint_src']]])
            ->setV3Jobs([
                'base_lint' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'otherArguments' => '--lint',
                ],
                'lint_src' => [
                    'extends' => 'base_lint',
                    'paths' => ['src'],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('/bin/echo src --lint', $this->getActualOutput());
    }
}
