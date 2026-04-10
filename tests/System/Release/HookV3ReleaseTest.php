<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class HookV3ReleaseTest extends ReleaseTestCase
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

    protected function tearDown(): void
    {
        shell_exec('git config --unset core.hooksPath 2>/dev/null');
        if (is_dir('.githooks')) {
            array_map('unlink', glob('.githooks/*') ?: []);
            @rmdir('.githooks');
        }

        parent::tearDown();
    }

    /** @test */
    public function it_installs_hooks_via_core_hooks_path()
    {
        passthru("$this->githooks hook --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('installed', $this->getActualOutput());
        $this->assertDirectoryExists('.githooks');
        $this->assertFileExists('.githooks/pre-commit');
    }

    /** @test */
    public function it_cleans_hooks()
    {
        passthru("$this->githooks hook --config=$this->configPath 2>&1", $exitCode);
        $this->assertEquals(0, $exitCode);

        passthru("$this->githooks hook:clean 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertDirectoryDoesNotExist('.githooks');
    }

    /** @test */
    public function it_shows_synced_status_after_install()
    {
        passthru("$this->githooks hook --config=$this->configPath 2>&1", $exitCode);
        $this->assertEquals(0, $exitCode);

        passthru("$this->githooks status --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('synced', $this->getActualOutput());
    }

    /** @test */
    public function it_runs_hook_event()
    {
        passthru("$this->githooks hook:run pre-commit --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('OK', $this->getActualOutput());
    }

    /** @test */
    public function it_executes_hook_when_only_on_matches_current_branch()
    {
        $branch = trim(shell_exec('git rev-parse --abbrev-ref HEAD'));

        $this->configurationFileBuilder
            ->setV3Hooks([
                'pre-commit' => [
                    ['flow' => 'qa', 'only-on' => [$branch]],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks hook:run pre-commit --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('OK', $this->getActualOutput());
    }

    /** @test */
    public function it_skips_hook_when_only_on_does_not_match()
    {
        $this->configurationFileBuilder
            ->setV3Hooks([
                'pre-commit' => [
                    ['flow' => 'qa', 'only-on' => ['main', 'develop']],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks hook:run pre-commit --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('skipped by execution conditions', $this->getActualOutput());
    }

    /** @test */
    public function it_skips_hook_when_exclude_on_matches_current_branch()
    {
        $branch = trim(shell_exec('git rev-parse --abbrev-ref HEAD'));

        $this->configurationFileBuilder
            ->setV3Hooks([
                'pre-commit' => [
                    ['flow' => 'qa', 'exclude-on' => [$branch]],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks hook:run pre-commit --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('skipped by execution conditions', $this->getActualOutput());
    }

    /** @test */
    public function it_skips_hook_when_exclude_on_prevails_over_only_on()
    {
        $branch = trim(shell_exec('git rev-parse --abbrev-ref HEAD'));

        $this->configurationFileBuilder
            ->setV3Hooks([
                'pre-commit' => [
                    ['flow' => 'qa', 'only-on' => [$branch], 'exclude-on' => [$branch]],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks hook:run pre-commit --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('skipped by execution conditions', $this->getActualOutput());
    }

    /** @test */
    public function it_skips_hook_when_no_staged_files_match_only_files()
    {
        $this->configurationFileBuilder
            ->setV3Hooks([
                'pre-commit' => [
                    ['flow' => 'qa', 'only-files' => ['nonexistent_dir/**/*.php']],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks hook:run pre-commit --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('skipped by execution conditions', $this->getActualOutput());
    }

    /** @test */
    public function it_reports_no_hooks_section_when_config_has_none()
    {
        $this->configurationFileBuilder
            ->setV3Hooks([]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks hook:run pre-commit --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertTrue(
            strpos($output, 'No') !== false || strpos($output, 'not found') !== false || strpos($output, 'Nothing') !== false,
            'Expected informative message about missing hooks section'
        );
    }

    /** @test */
    public function it_reports_no_config_for_unknown_event()
    {
        passthru("$this->githooks hook:run post-merge --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertTrue(
            strpos($output, 'No flows or jobs') !== false || strpos($output, 'No') !== false,
            'Expected message about no config for event'
        );
    }
}
