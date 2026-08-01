<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Utils\TestCase\SystemTestCase;
use Tests\Utils\Traits\GitSandboxTrait;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Output\NullOutputHandler;
use Wtyd\GitHooks\Utils\GitStager;

/**
 * FEAT-23: a `custom` job with `re-stage: true` inherits the auto-re-stage that
 * only native fixer types had. Verifies the end-to-end wiring
 * (mayApplyFixes → isFixApplied → GitStager) across the decision table.
 *
 * Factor table — what gets re-staged after a fix:
 *
 * | staged | touched by the job | had unstaged edits before | exit | re-staged? |
 * |--------|--------------------|---------------------------|------|------------|
 * | yes    | yes                | no                        | 0    | yes        |
 * | yes    | no                 | yes                       | 0    | **no**     |
 * | yes    | no                 | no                        | 0    | no         |
 * | yes    | yes                | yes                       | 0    | yes, whole |
 * | yes    | yes                | —                         | ≠0   | no         |
 *
 * Row 2 is the one that matters: the job did not touch that file, so edits the
 * author deliberately left out of the index must stay out of it. Row 4 is the
 * inherent limit of `git add`, which cannot stage part of a file.
 *
 * Runs against a sandboxed git repo in /tmp (GitSandboxTrait) — the project's
 * real working tree is never touched.
 *
 * @group git
 */
class CustomRestageTest extends SystemTestCase
{
    use GitSandboxTrait;

    /** @var string Absolute path inside the sandbox. */
    private $filePath;

    /** @var string A second staged file the jobs under test never touch. */
    private $bystanderPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpGitSandbox();

        $gitFilesPathTest = $this->sandboxDir
            . DIRECTORY_SEPARATOR
            . SystemTestCase::TESTS_PATH
            . DIRECTORY_SEPARATOR
            . 'gitTests';
        mkdir($gitFilesPathTest, 0755, true);

        $this->filePath = $gitFilesPathTest . DIRECTORY_SEPARATOR . 'CustomFixable.php';
        $this->bystanderPath = $gitFilesPathTest . DIRECTORY_SEPARATOR . 'Bystander.php';
    }

    protected function tearDown(): void
    {
        $this->tearDownGitSandbox();

        parent::tearDown();
    }

    /**
     * Stage the target file. The fix itself happens inside the job (see
     * {@see fixScript()}), which is what a real fixer does and what the
     * re-stage scope is computed from.
     */
    private function stageFile(): void
    {
        file_put_contents($this->filePath, "<?php\nclass CustomFixable { public function a(){} }\n");
        shell_exec('git add -f ' . escapeshellarg($this->filePath));

        $staged = (string) shell_exec('git diff --cached --name-only');
        $this->assertStringContainsString('CustomFixable.php', $staged, 'File should be staged');
    }

    /**
     * Stage a second file and then edit it without staging that edit — the
     * "partially staged" state an author leaves behind when committing only
     * part of their work.
     */
    private function stageBystanderWithUnstagedEdit(): void
    {
        file_put_contents($this->bystanderPath, "<?php\n// staged version\n");
        shell_exec('git add -f ' . escapeshellarg($this->bystanderPath));
        file_put_contents($this->bystanderPath, "<?php\n// staged version\n// NOT meant for this commit\n");
    }

    /** Shell command that rewrites the target file, as a fixer would. */
    private function fixScript(): string
    {
        return 'printf \'<?php\n\nclass CustomFixable\n{\n}\n\' > ' . escapeshellarg($this->filePath);
    }

    /** @param array<string, mixed> $config */
    private function runCustomJob(array $config)
    {
        $executor = new FlowExecutor(new NullOutputHandler(), new GitStager());
        $job = new CustomJob(new JobConfiguration('formateo', 'custom', $config));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        return $executor->execute($plan);
    }

    private function unstagedFiles(): string
    {
        return trim((string) shell_exec('git diff --name-only'));
    }

    /** @test Row 1 — the job's own change to a staged file is re-staged */
    public function it_restages_when_re_stage_true_and_exit_zero()
    {
        $this->stageFile();

        $result = $this->runCustomJob(['script' => $this->fixScript(), 're-stage' => true]);

        $this->assertTrue($result->isSuccess(), 'exit 0 job is a success');
        $this->assertTrue($result->getJobResults()[0]->isFixApplied(), 'fixApplied should be true');
        $this->assertEmpty($this->unstagedFiles(), 'No unstaged changes should remain — the fix must be re-staged');
    }

    /**
     * Row 2 — the patogenic case. `git add` used to be run over the whole index,
     * so a re-staging job swept in edits it never made and the author never
     * staged, silently putting them in the commit.
     *
     * @test
     */
    public function it_does_not_restage_files_the_job_did_not_touch()
    {
        $this->stageFile();
        $this->stageBystanderWithUnstagedEdit();

        $result = $this->runCustomJob(['script' => $this->fixScript(), 're-stage' => true]);

        $this->assertTrue($result->getJobResults()[0]->isFixApplied());
        $this->assertStringNotContainsString(
            '// NOT meant for this commit',
            (string) shell_exec('git show :' . self::TESTS_PATH . '/gitTests/Bystander.php'),
            'An edit the job never made must not reach the index'
        );
        $this->assertStringContainsString(
            'Bystander.php',
            $this->unstagedFiles(),
            'The untouched file keeps its unstaged edit'
        );
    }

    /** @test Row 3 — a job that changes nothing re-stages nothing */
    public function it_restages_nothing_when_the_job_modifies_no_file()
    {
        $this->stageFile();
        $this->stageBystanderWithUnstagedEdit();

        $this->runCustomJob(['script' => 'true', 're-stage' => true]);

        $this->assertStringContainsString('Bystander.php', $this->unstagedFiles());
    }

    /** @test */
    public function it_does_not_restage_without_re_stage()
    {
        $this->stageFile();

        $result = $this->runCustomJob(['script' => $this->fixScript()]);

        $this->assertTrue($result->isSuccess(), 'exit 0 job is still a success');
        $this->assertFalse($result->getJobResults()[0]->isFixApplied(), 'fixApplied should be false without re-stage');
        $this->assertNotEmpty($this->unstagedFiles(), 'Without re-stage the working-tree change stays unstaged');
    }

    /** @test Row 5 — a failing script never re-stages, not even what it did modify */
    public function it_does_not_restage_when_exit_is_nonzero()
    {
        $this->stageFile();

        $result = $this->runCustomJob(['script' => $this->fixScript() . '; exit 1', 're-stage' => true]);

        $this->assertFalse($result->isSuccess(), 'non-zero exit is a failure');
        $this->assertFalse($result->getJobResults()[0]->isFixApplied(), 're-stage must not mask a failure');
        $this->assertNotEmpty($this->unstagedFiles(), 'A failed script must not re-stage');
    }
}
