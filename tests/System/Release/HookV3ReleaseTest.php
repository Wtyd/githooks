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

    /**
     * @test
     *
     * FEAT-16 / AC-007: the embedded binary propagates Git's positional hook
     * argument (the message-file path) through `hook:run commit-msg <file>` to
     * the inline commit-msg job, which validates the file's subject. Fails if
     * the "$@" propagation or the commit-msg job are not embedded in the .phar.
     */
    public function it_validates_commit_message_via_commit_msg_hook()
    {
        $this->configurationFileBuilder
            ->setV3Hooks(['commit-msg' => ['commit-format']])
            ->addV3Job('commit-format', 'commit-msg', ['preset' => 'conventional-commits']);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $validFile = self::TESTS_PATH . '/commit-msg-ok.txt';
        $invalidFile = self::TESTS_PATH . '/commit-msg-bad.txt';
        file_put_contents($validFile, "feat(api): add user endpoint\n");
        file_put_contents($invalidFile, "Add stuff.\n");

        passthru("$this->githooks hook:run commit-msg $validFile --config=$this->configPath 2>&1", $okExit);
        $this->assertEquals(0, $okExit, 'Valid conventional message must pass the commit-msg hook');

        passthru("$this->githooks hook:run commit-msg $invalidFile --config=$this->configPath 2>&1", $koExit);
        $this->assertEquals(1, $koExit, 'Non-conventional message must fail the commit-msg hook');
        $this->assertStringContainsString("rule 'pattern'", $this->getActualOutput());

        @unlink($validFile);
        @unlink($invalidFile);
    }

    /**
     * @test
     *
     * FEAT-16: a merge commit message skips validation (merge-allowed default),
     * so the hook exits 0 without failing the merge.
     */
    public function it_skips_merge_commit_message()
    {
        $this->configurationFileBuilder
            ->setV3Hooks(['commit-msg' => ['commit-format']])
            ->addV3Job('commit-format', 'commit-msg', ['preset' => 'conventional-commits']);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $mergeFile = self::TESTS_PATH . '/commit-msg-merge.txt';
        file_put_contents($mergeFile, "Merge branch 'feature/foo'\n");

        passthru("$this->githooks hook:run commit-msg $mergeFile --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);

        @unlink($mergeFile);
    }

    /**
     * @test
     * BUG-31: `hook --config=<file>` bakes the file into the scripts, relative to
     * the repository root, so every trigger runs that configuration.
     */
    public function it_bakes_the_install_config_into_the_hook_scripts_relative_to_the_repository_root()
    {
        passthru("$this->githooks hook --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString(
            " hook:run --config='$this->configPath' \"$(basename \"$0\")\" \"$@\"",
            (string) file_get_contents('.githooks/pre-commit')
        );
    }

    /**
     * @test
     * BUG-31, end to end: the installed script runs the configuration it was
     * installed from. Verified where the old behaviour cannot get lucky — the
     * hook is triggered from a directory with no githooks.php at all, using a
     * config outside the repository (baked absolute), and the job leaves a
     * marker. Without the fix hook:run finds no configuration and exits 1.
     */
    public function installed_hook_runs_the_configuration_it_was_installed_from()
    {
        $outside = sys_get_temp_dir() . '/githooks_release_outside_' . uniqid();
        mkdir($outside, 0755, true);
        $marker = "$outside/hook-ran.marker";
        $config = [
            // Absolute command: the hook is triggered from $outside, not from the repo.
            'hooks' => ['command' => PHP_BINARY . ' ' . realpath($this->githooks), 'pre-push' => ['mark']],
            'flows' => ['mark' => ['jobs' => ['touch_marker']]],
            'jobs'  => ['touch_marker' => ['type' => 'custom', 'script' => "touch $marker"]],
        ];
        file_put_contents("$outside/githooks.php", "<?php\nreturn " . var_export($config, true) . ";\n");

        try {
            passthru("$this->githooks hook --config=$outside/githooks.php 2>&1", $installExit);
            $this->assertEquals(0, $installExit);
            $this->assertStringContainsString('is outside the repository', $this->getActualOutput());
            $hook = (string) realpath('.githooks/pre-push');

            passthru("cd $outside && sh $hook 2>&1", $hookExit);

            $this->assertEquals(0, $hookExit, 'the hook must find the configuration it was installed from');
            $this->assertFileExists($marker, 'the job of the baked configuration never ran');
        } finally {
            @unlink($marker);
            @unlink("$outside/githooks.php");
            @rmdir($outside);
        }
    }
}
