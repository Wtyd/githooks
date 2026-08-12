<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Utils\TestCase\SystemTestCase;
use Tests\Utils\Traits\GitSandboxTrait;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\PhpcbfJob;
use Wtyd\GitHooks\Output\NullOutputHandler;
use Wtyd\GitHooks\Utils\GitStager;

/**
 * Verifies that when a fix-applying job (phpcbf) modifies staged files,
 * those changes are automatically re-staged so the commit includes the fixes —
 * and that files the job never touched keep whatever the author left unstaged.
 *
 * The fake's env var is set in $_SERVER, not only putenv(): Symfony Process
 * builds the child env from getenv() ∩ $_SERVER, so putenv()-only vars never
 * reach the spawned job. The staged-content assertions compare the exact file
 * body so they cannot pass vacuously when the fake fails to rewrite.
 *
 * Runs against a sandboxed git repo in /tmp (GitSandboxTrait). It previously
 * ran `git reset --hard HEAD` against the project's own repository in setUp and
 * tearDown, which destroyed any uncommitted work in the checkout whenever the
 * git group was executed.
 *
 * @group git
 */
class RestageAfterFixTest extends SystemTestCase
{
    use GitSandboxTrait;

    private const DIRTY_CONTENT = "<?php\nclass Fixable { public function a(){} }\n";

    /** Must match what tests/Fixtures/scripts/phpcbf-fake-fix.sh writes. */
    private const FIXED_CONTENT = "<?php\n\nclass Fixable\n{\n    public function a()\n    {\n    }\n}\n";

    /** @var string Absolute path inside the sandbox. */
    private $gitFilesPathTest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpGitSandbox();

        $this->gitFilesPathTest = $this->sandboxDir
            . DIRECTORY_SEPARATOR
            . SystemTestCase::TESTS_PATH
            . DIRECTORY_SEPARATOR
            . 'gitTests';
        mkdir($this->gitFilesPathTest, 0755, true);
    }

    protected function tearDown(): void
    {
        putenv('PHPCBF_FAKE_TARGET');
        unset($_SERVER['PHPCBF_FAKE_TARGET']);

        $this->tearDownGitSandbox();

        parent::tearDown();
    }

    /**
     * phpcbf simulator: exits 1 (= fixes applied) and rewrites the file named by
     * PHPCBF_FAKE_TARGET, so the change happens *while the job runs* — which is
     * what the re-stage scope is computed from.
     */
    private function phpcbfJob(string $target): PhpcbfJob
    {
        putenv('PHPCBF_FAKE_TARGET=' . $target);
        $_SERVER['PHPCBF_FAKE_TARGET'] = $target;

        return new PhpcbfJob(new JobConfiguration('phpcbf_test', 'phpcbf', [
            'executable-path' => __DIR__ . '/../Fixtures/scripts/phpcbf-fake-fix.sh',
        ]));
    }

    private function execute(PhpcbfJob $job)
    {
        $executor = new FlowExecutor(new NullOutputHandler(), new GitStager());

        return $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration()));
    }

    private function stagedContent(string $fileName): string
    {
        return (string) shell_exec('git show :' . self::TESTS_PATH . '/gitTests/' . $fileName);
    }

    /** @test */
    function it_restages_files_modified_by_a_fix_job()
    {
        $filePath = $this->gitFilesPathTest . '/Fixable.php';
        file_put_contents($filePath, self::DIRTY_CONTENT);
        shell_exec('git add -f ' . escapeshellarg($filePath));

        $this->assertStringContainsString(
            'Fixable.php',
            (string) shell_exec('git diff --cached --name-only'),
            'File should be staged'
        );

        $result = $this->execute($this->phpcbfJob($filePath));

        $this->assertTrue($result->isSuccess(), 'phpcbf fix should be treated as success');
        $this->assertSame(1, $result->getJobResults()[0]->getExitCode(), 'phpcbf exit 1 = fixes applied');
        $this->assertTrue($result->getJobResults()[0]->isFixApplied(), 'fixApplied should be true');
        $this->assertEmpty(
            trim((string) shell_exec('git diff --name-only')),
            'No unstaged changes should remain — fixes must be re-staged'
        );
        $this->assertSame(
            self::FIXED_CONTENT,
            $this->stagedContent('Fixable.php'),
            'The staged content must be exactly the fixed version'
        );
    }

    /**
     * The re-stage covers what the fixer rewrote, not the whole index: an edit
     * the author staged nothing of must not be dragged into the commit by an
     * unrelated fixer run.
     *
     * @test
     */
    function it_leaves_untouched_files_out_of_the_restage()
    {
        $fixable = $this->gitFilesPathTest . '/Fixable.php';
        file_put_contents($fixable, self::DIRTY_CONTENT);

        $bystanderStaged = "<?php\n// staged version\n";
        $bystander = $this->gitFilesPathTest . '/Bystander.php';
        file_put_contents($bystander, $bystanderStaged);

        shell_exec('git add -f ' . escapeshellarg($fixable) . ' ' . escapeshellarg($bystander));

        // The author keeps working on the bystander but does not stage it.
        file_put_contents($bystander, $bystanderStaged . "// NOT meant for this commit\n");

        $this->execute($this->phpcbfJob($fixable));

        $this->assertSame(
            self::FIXED_CONTENT,
            $this->stagedContent('Fixable.php'),
            'The fixed file must be re-staged — without this the test passes vacuously when the fake rewrites nothing'
        );
        $this->assertSame(
            $bystanderStaged,
            $this->stagedContent('Bystander.php'),
            'An edit the fixer never made must not reach the index'
        );
        $this->assertSame(
            self::TESTS_PATH . '/gitTests/Bystander.php',
            trim((string) shell_exec('git diff --name-only')),
            'The untouched file keeps its unstaged edit — and is the only one'
        );
    }
}
