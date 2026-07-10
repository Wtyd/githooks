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
 * (isFixApplied → GitStager) for `custom` across the decision table:
 * re-stage + exit 0 re-stages; no re-stage or non-zero exit does not.
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
    }

    protected function tearDown(): void
    {
        $this->tearDownGitSandbox();

        parent::tearDown();
    }

    /**
     * Stages a file, then rewrites it on disk (simulating what a fixer does to
     * the working tree) so an unstaged change exists for the re-stage to capture.
     */
    private function stageFileAndModifyOnDisk(): void
    {
        file_put_contents($this->filePath, "<?php\nclass CustomFixable { public function a(){} }\n");
        shell_exec('git add -f ' . escapeshellarg($this->filePath));

        $staged = (string) shell_exec('git diff --cached --name-only');
        $this->assertStringContainsString('CustomFixable.php', $staged, 'File should be staged');

        file_put_contents($this->filePath, "<?php\n\nclass CustomFixable\n{\n    public function a()\n    {\n    }\n}\n");
        $this->assertNotEmpty(trim((string) shell_exec('git diff --name-only')), 'Fixed file should be unstaged');
    }

    /** @param array<string, mixed> $config */
    private function runCustomJob(array $config)
    {
        $executor = new FlowExecutor(new NullOutputHandler(), new GitStager());
        $job = new CustomJob(new JobConfiguration('formateo', 'custom', $config));
        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());

        return $executor->execute($plan);
    }

    /** @test */
    public function it_restages_when_re_stage_true_and_exit_zero()
    {
        $this->stageFileAndModifyOnDisk();

        $result = $this->runCustomJob(['script' => 'true', 're-stage' => true]);

        $this->assertTrue($result->isSuccess(), 'exit 0 job is a success');
        $this->assertTrue($result->getJobResults()[0]->isFixApplied(), 'fixApplied should be true');
        $this->assertEmpty(
            trim((string) shell_exec('git diff --name-only')),
            'No unstaged changes should remain — the fix must be re-staged'
        );
    }

    /** @test */
    public function it_does_not_restage_without_re_stage()
    {
        $this->stageFileAndModifyOnDisk();

        $result = $this->runCustomJob(['script' => 'true']);

        $this->assertTrue($result->isSuccess(), 'exit 0 job is still a success');
        $this->assertFalse($result->getJobResults()[0]->isFixApplied(), 'fixApplied should be false without re-stage');
        $this->assertNotEmpty(
            trim((string) shell_exec('git diff --name-only')),
            'Without re-stage the working-tree change stays unstaged'
        );
    }

    /** @test */
    public function it_does_not_restage_when_exit_is_nonzero()
    {
        $this->stageFileAndModifyOnDisk();

        $result = $this->runCustomJob(['script' => 'false', 're-stage' => true]);

        $this->assertFalse($result->isSuccess(), 'non-zero exit is a failure');
        $this->assertFalse($result->getJobResults()[0]->isFixApplied(), 're-stage must not mask a failure');
        $this->assertNotEmpty(
            trim((string) shell_exec('git diff --name-only')),
            'A failed script must not re-stage'
        );
    }
}
