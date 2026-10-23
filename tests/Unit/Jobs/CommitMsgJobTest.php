<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\Alfil\Configuration\JobConfiguration;
use Wtyd\Alfil\Execution\ExecutionContext;
use Wtyd\Alfil\Jobs\CommitMessage\MessageFileResolver;
use Wtyd\Alfil\Jobs\CommitMsgJob;

/**
 * Orchestration of {@see CommitMsgJob::runInline()} (FEAT-16): outcome → JobResult
 * mapping for pass / fail / merge-skip / no-source / unreadable. The validator,
 * presets and resolver have their own unit tests; here we check the wiring and
 * the JobResult contract (REQ-016/017/018, AC-001/002/003/009/010), and that
 * ALFIL_COMMIT_MSG_FILE is really read (4.0; rows 1, 5, 6 and 8 of
 * CommitMessage/factors-message-file.md as the job sees them).
 */
class CommitMsgJobTest extends UnitTestCase
{
    /** @var string[] */
    private array $tmpFiles = [];

    /** @var string[] Fake repository roots, deepest path first for rmdir. */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv(MessageFileResolver::ENV_MESSAGE_FILE);
    }

    protected function tearDown(): void
    {
        putenv(MessageFileResolver::ENV_MESSAGE_FILE);
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ($this->tmpDirs as $dir) {
            @rmdir($dir);
        }
        $this->tmpFiles = [];
        $this->tmpDirs = [];
    }

    private function job(array $config): CommitMsgJob
    {
        return new CommitMsgJob(new JobConfiguration('commit-format', 'commit-msg', $config));
    }

    private function messageFile(string $message): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'commitmsgtest-');
        file_put_contents($path, $message);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function contextWithMessage(string $message): ExecutionContext
    {
        return ExecutionContext::default()->withCommitMessageFile($this->messageFile($message));
    }

    /**
     * A context whose CWD is a fake repository root holding a valid
     * .git/COMMIT_EDITMSG, so the fallback exists and any verdict other than
     * "valid" must have come from a higher-priority source.
     */
    private function contextWithValidFallback(): ExecutionContext
    {
        $root = sys_get_temp_dir() . '/commitmsg-root-' . uniqid();
        mkdir($root . '/.git', 0777, true);
        file_put_contents($root . '/.git/COMMIT_EDITMSG', "feat(api): add user endpoint\n");
        $this->tmpFiles[] = $root . '/.git/COMMIT_EDITMSG';
        $this->tmpDirs[] = $root . '/.git';
        $this->tmpDirs[] = $root;
        return ExecutionContext::default()->withCwd($root);
    }

    private function setEnvFile(string $path): void
    {
        putenv(MessageFileResolver::ENV_MESSAGE_FILE . '=' . $path);
    }

    private function runWithContext(CommitMsgJob $job, ExecutionContext $context): \Wtyd\Alfil\Execution\JobResult
    {
        $job->setExecutionContext($context);
        return $job->runInline();
    }

    /** @test */
    public function preset_pass_returns_success_no_output(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);

        $result = $this->runWithContext($job, $this->contextWithMessage('feat(api): add user endpoint'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->getExitCode());
        $this->assertSame('', $result->getOutput());
        $this->assertFalse($result->isSkipped());
    }

    /** @test */
    public function preset_fail_returns_failure_with_human_block_and_example(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);

        $result = $this->runWithContext($job, $this->contextWithMessage('Add stuff.'));

        $this->assertFalse($result->isSuccess());
        $this->assertSame(1, $result->getExitCode());
        $this->assertStringContainsString("subject failed rule 'pattern'", $result->getOutput());
        $this->assertStringContainsString('Example:   feat(api): add user endpoint', $result->getOutput());
    }

    /** @test */
    public function merge_commit_is_skipped(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);

        $result = $this->runWithContext($job, $this->contextWithMessage("Merge branch 'feature/foo'"));

        $this->assertTrue($result->isSkipped());
        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->getExitCode());
        $this->assertSame('merge or fixup commit', $result->getSkipReason());
    }

    /** @test */
    public function no_rules_passes_any_non_empty_subject(): void
    {
        $job = $this->job([]);

        $result = $this->runWithContext($job, $this->contextWithMessage('anything at all'));

        $this->assertTrue($result->isSuccess());
    }

    /** @test */
    public function empty_subject_fails_forbid_empty_by_default(): void
    {
        $job = $this->job([]);

        $result = $this->runWithContext($job, $this->contextWithMessage(''));

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString("forbid-empty", $result->getOutput());
    }

    /** @test */
    public function explicit_rules_override_preset_length(): void
    {
        // max-length 120 override; a 110-char conventional subject passes (AC-006).
        $job = $this->job([
            'preset' => 'conventional-commits',
            'rules'  => ['max-length' => 120],
        ]);
        $subject = 'feat(api): ' . str_repeat('a', 99); // 11 + 99 = 110 chars

        $result = $this->runWithContext($job, $this->contextWithMessage($subject));

        $this->assertTrue($result->isSuccess(), $result->getOutput());
    }

    /** @test */
    public function unreadable_explicit_file_fails_with_cannot_read(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);
        $context = ExecutionContext::default()->withCommitMessageFile('/no/such/commit/msg/file.txt');

        $result = $this->runWithContext($job, $context);

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isSkipped(), 'a read failure is a failure, not a skip');
        $this->assertStringContainsString('cannot read message file', $result->getOutput());
    }

    /** @test */
    public function no_source_and_no_fallback_fails_with_no_message_available(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);
        // CWD with no .git/COMMIT_EDITMSG and no explicit file.
        $emptyDir = sys_get_temp_dir() . '/commitmsg-empty-' . uniqid();
        mkdir($emptyDir);
        $context = ExecutionContext::default()->withCwd($emptyDir);

        $result = $this->runWithContext($job, $context);

        rmdir($emptyDir);
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isSkipped(), 'a missing-message failure is a failure, not a skip');
        // The whole sentence, not a substring: the half that matters to the
        // committer is the one listing the three ways to supply a message.
        $this->assertSame(
            "commit-msg: no message file available. Provide --message-file, --message, "
            . "set ALFIL_COMMIT_MSG_FILE, or run via the 'commit-msg' git hook.",
            $result->getOutput()
        );
    }

    /**
     * @test
     *
     * 4.0: a commit-msg job inside `flow` / `flows` carries no hook argument and
     * no --message-file, so ALFIL_COMMIT_MSG_FILE is its only way to the message.
     * The fallback exists and is valid; the env file is not, so a job that did
     * not read the variable would pass on the fallback.
     */
    public function the_env_file_wins_over_the_commit_editmsg_fallback(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);
        $this->setEnvFile($this->messageFile('Add stuff.'));

        $result = $this->runWithContext($job, $this->contextWithValidFallback());

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString("\n  Subject:   Add stuff.\n", $result->getOutput());
    }

    /** @test The hook argument / --message-file keeps its priority over the variable. */
    public function the_explicit_file_wins_over_the_env_file(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);
        $this->setEnvFile($this->messageFile('Add stuff.'));

        $result = $this->runWithContext($job, $this->contextWithMessage('feat(api): add user endpoint'));

        $this->assertTrue($result->isSuccess(), $result->getOutput());
    }

    /**
     * @test
     *
     * A variable pointing at a missing file is a misconfiguration to report,
     * not a reason to silently validate .git/COMMIT_EDITMSG instead.
     */
    public function a_missing_env_file_fails_with_cannot_read_instead_of_the_fallback(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);
        $this->setEnvFile('/no/such/env/commit/msg.txt');

        $result = $this->runWithContext($job, $this->contextWithValidFallback());

        $this->assertFalse($result->isSuccess());
        $this->assertSame(
            "commit-msg: cannot read message file '/no/such/env/commit/msg.txt'.",
            $result->getOutput()
        );
    }

    /** @test An empty variable counts as unset: the fallback is used. */
    public function an_empty_env_file_is_absent(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);
        $this->setEnvFile('');

        $result = $this->runWithContext($job, $this->contextWithValidFallback());

        $this->assertTrue($result->isSuccess(), $result->getOutput());
    }

    /**
     * @test
     *
     * The failure block echoes the offending subject back so the committer can
     * see what was rejected. It is a `mb_substr` window, and an off-by-one on
     * its offset silently eats the first character of every rejected subject.
     */
    public function the_failure_block_echoes_the_whole_subject_back(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);

        $result = $this->runWithContext($job, $this->contextWithMessage('Add stuff.'));

        $this->assertStringContainsString("\n  Subject:   Add stuff.\n", $result->getOutput());
    }

    /**
     * @test
     *
     * Sub-second runs report milliseconds; the seconds format is reserved for
     * runs at or over 1s. An inline validation never reaches a second, so an
     * inverted threshold would label every commit-msg run as `0.00s`.
     */
    public function a_sub_second_validation_reports_its_time_in_milliseconds(): void
    {
        $job = $this->job(['preset' => 'conventional-commits']);

        $result = $this->runWithContext($job, $this->contextWithMessage('feat: add endpoint'));

        $this->assertMatchesRegularExpression('/^\d+ms$/', $result->getExecutionTime());
    }
}
