<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release-level acceptance for BUG-21: unknown CLI options must produce a clear
 * error and exit 1 on the distributed `.phar`, with no silent loss of other
 * options (notably `--config`).
 *
 * @group release
 */
class UnknownOptionReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['echo_job']]])
            ->setV3Jobs(['echo_job' => ['type' => 'custom', 'script' => 'true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());
    }

    /** @test */
    public function flow_rejects_unknown_long_option_with_exit_1(): void
    {
        passthru("$this->githooks flow qa --foo=bar --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--foo', $this->getActualOutput());
    }

    /** @test */
    public function flows_rejects_unknown_long_option_with_exit_1(): void
    {
        passthru("$this->githooks flows qa --foo=bar --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--foo', $this->getActualOutput());
    }

    /**
     * `--fast-branch-fallback` was an inert CLI flag on `flows` (declared but
     * never read; only the `fast-branch-fallback` config option works). It was
     * removed, so passing it must now be rejected like any unknown option. The
     * config option keeps working (covered by ExecutionModesReleaseTest).
     *
     * @test
     */
    public function flows_rejects_removed_fast_branch_fallback_cli_flag(): void
    {
        passthru("$this->githooks flows qa --fast-branch-fallback=fast --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--fast-branch-fallback', $this->getActualOutput());
    }

    /** @test */
    public function job_rejects_unknown_long_option_before_dashdash_with_exit_1(): void
    {
        passthru("$this->githooks job echo_job --foo=bar --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--foo', $this->getActualOutput());
    }

    /** @test */
    public function flow_rejects_dash_dash_separator_with_specific_message(): void
    {
        passthru("$this->githooks flow qa --config=$this->configPath -- extra 2>&1", $exitCode);

        $this->assertEquals(1, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('flow', $output);
        $this->assertStringContainsString('does not support', $output);
    }

    /** @test */
    public function flows_rejects_dash_dash_separator_with_specific_message(): void
    {
        passthru("$this->githooks flows qa --config=$this->configPath -- extra 2>&1", $exitCode);

        $this->assertEquals(1, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('flows', $output);
        $this->assertStringContainsString('does not support', $output);
    }

    /** @test */
    public function job_still_passes_args_after_dashdash_to_tool(): void
    {
        $this->configurationFileBuilder
            ->setV3Jobs(['echo_job' => ['type' => 'custom', 'script' => '/bin/echo base']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks job echo_job --dry-run --config=$this->configPath -- --tool-flag 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('--tool-flag', $this->getActualOutput());
    }
}
