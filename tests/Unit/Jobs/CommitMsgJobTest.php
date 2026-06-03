<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Jobs\CommitMsgJob;

/**
 * Orchestration of {@see CommitMsgJob::runInline()} (FEAT-16): outcome → JobResult
 * mapping for pass / fail / merge-skip / no-source / unreadable. The validator,
 * presets and resolver have their own unit tests; here we check the wiring and
 * the JobResult contract (REQ-016/017/018, AC-001/002/003/009/010).
 */
class CommitMsgJobTest extends TestCase
{
    /** @var string[] */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    private function job(array $config): CommitMsgJob
    {
        return new CommitMsgJob(new JobConfiguration('commit-format', 'commit-msg', $config));
    }

    private function contextWithMessage(string $message): ExecutionContext
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'commitmsgtest-');
        file_put_contents($path, $message);
        $this->tmpFiles[] = $path;
        return ExecutionContext::default()->withCommitMessageFile($path);
    }

    private function runWithContext(CommitMsgJob $job, ExecutionContext $context): \Wtyd\GitHooks\Execution\JobResult
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
        $this->assertStringContainsString('no message file available', $result->getOutput());
    }
}
