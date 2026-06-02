<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;

/**
 * End-to-end coverage of the `commit-msg` job through `githooks job` (FEAT-16):
 * --message / --message-file, mutual exclusion, JSON envelope and conf:check.
 * Uses --message (GUD-003) so the tests never touch a real .git/COMMIT_EDITMSG.
 */
class CommitMsgJobCommandTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->addV3Job('commit-format', 'commit-msg', ['preset' => 'conventional-commits'])
            ->buildInFileSystem();
    }

    /** @test AC-001 */
    public function valid_message_passes(): void
    {
        $this->artisan('job', [
            'name'        => 'commit-format',
            '--message'   => 'feat(api): add user endpoint',
            '--format'    => 'json',
            '--config'    => $this->configPath,
        ])
            ->assertExitCode(0)
            ->containsStringInOutput('"success": true');
    }

    /** @test AC-002 */
    public function invalid_message_fails_on_pattern(): void
    {
        $this->artisan('job', [
            'name'      => 'commit-format',
            '--message' => 'Add stuff.',
            '--format'  => 'json',
            '--config'  => $this->configPath,
        ])
            ->assertExitCode(1)
            ->containsStringInOutput('"success": false');
    }

    /** @test */
    public function invalid_message_text_shows_human_block(): void
    {
        $this->artisan('job', [
            'name'      => 'commit-format',
            '--message' => 'Add stuff.',
            '--config'  => $this->configPath,
        ])
            ->assertExitCode(1)
            ->containsStringInOutput("subject failed rule 'pattern'")
            ->containsStringInOutput('feat(api): add user endpoint');
    }

    /**
     * @test AC-015
     *
     * The mutual-exclusion guard rejects the run with exit 1 before anything
     * executes. The explanatory message goes to the real STDERR stream
     * ({@see EmitsStderr}), which the test buffer drops by design, so only the
     * exit code is asserted here.
     */
    public function message_and_message_file_are_mutually_exclusive(): void
    {
        $this->artisan('job', [
            'name'           => 'commit-format',
            '--message'      => 'feat: x',
            '--message-file' => '/tmp/whatever.txt',
            '--config'       => $this->configPath,
        ])
            ->assertExitCode(1);
    }

    /** @test */
    public function message_file_source_is_validated(): void
    {
        $messageFile = tempnam(sys_get_temp_dir(), 'commitmsgsys-');
        file_put_contents($messageFile, "feat(core): wire commit-msg job\n");

        $this->artisan('job', [
            'name'           => 'commit-format',
            '--message-file' => $messageFile,
            '--format'       => 'json',
            '--config'       => $this->configPath,
        ])
            ->assertExitCode(0)
            ->containsStringInOutput('"success": true');

        unlink($messageFile);
    }

    /** @test AC-003 */
    public function merge_commit_is_skipped(): void
    {
        $this->artisan('job', [
            'name'      => 'commit-format',
            '--message' => "Merge branch 'feature/foo'",
            '--format'  => 'json',
            '--config'  => $this->configPath,
        ])
            ->assertExitCode(0)
            ->containsStringInOutput('"skipped": true');
    }

    /** @test */
    public function conf_check_accepts_commit_msg_job(): void
    {
        $this->artisan('conf:check', ['--config' => $this->configPath])
            ->assertExitCode(0);
    }
}
