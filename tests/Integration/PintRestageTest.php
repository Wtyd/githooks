<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Utils\TestCase\SystemTestCase;
use Tests\Utils\Traits\GitSandboxTrait;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\PintJob;
use Wtyd\GitHooks\Output\NullOutputHandler;
use Wtyd\GitHooks\Utils\GitStager;

/**
 * Verifies the `pint` job through the fixer pipeline end to end
 * (mayApplyFixes → isFixApplied → GitStager): a staged, badly formatted file
 * fixed by Pint ends up staged AND formatted — not left as `AM` (staged dirty
 * version + unstaged fix) — and `--test` mode never re-stages.
 *
 * Pint's exit-code semantics differ from phpcbf's (exit 0 = fixes applied,
 * not exit 1), so the wiring is exercised with a Pint-specific fake
 * (tests/Fixtures/scripts/pint-fake-fix.sh).
 *
 * The fake's env vars are set in $_SERVER, not only putenv(): Symfony Process
 * builds the child env from getenv() ∩ $_SERVER, so putenv()-only vars never
 * reach the spawned job. The staged-content assertions compare the exact file
 * body so they cannot pass vacuously when the fake fails to rewrite.
 *
 * Runs against a sandboxed git repo in /tmp (GitSandboxTrait) — the project's
 * real working tree is never touched.
 *
 * @group git
 */
class PintRestageTest extends SystemTestCase
{
    use GitSandboxTrait;

    private const DIRTY_CONTENT = "<?php\nclass PintFixable { public function a(){} }\n";

    /** Must match what tests/Fixtures/scripts/pint-fake-fix.sh writes. */
    private const FIXED_CONTENT = "<?php\n\nclass PintFixable\n{\n    public function a()\n    {\n    }\n}\n";

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

        $this->filePath = $gitFilesPathTest . DIRECTORY_SEPARATOR . 'PintFixable.php';
    }

    protected function tearDown(): void
    {
        foreach (['PINT_FAKE_TARGET', 'PINT_FAKE_EXIT'] as $var) {
            putenv($var);
            unset($_SERVER[$var]);
        }

        $this->tearDownGitSandbox();

        parent::tearDown();
    }

    /** Env var visible to the job's process (Symfony Process reads $_SERVER). */
    private function setFakeEnv(string $name, string $value): void
    {
        putenv("$name=$value");
        $_SERVER[$name] = $value;
    }

    /** Stage the target file with badly formatted content. */
    private function stageDirtyFile(): void
    {
        file_put_contents($this->filePath, self::DIRTY_CONTENT);
        shell_exec('git add -f ' . escapeshellarg($this->filePath));

        $this->assertStringContainsString(
            'PintFixable.php',
            (string) shell_exec('git diff --cached --name-only'),
            'File should be staged'
        );
    }

    private function stagedContent(): string
    {
        return (string) shell_exec('git show :' . self::TESTS_PATH . '/gitTests/PintFixable.php');
    }

    /** @param array<string, mixed> $extraConfig */
    private function runPintJob(array $extraConfig = [])
    {
        $executor = new FlowExecutor(new NullOutputHandler(), new GitStager());
        $job = new PintJob(new JobConfiguration('pint_test', 'pint', array_merge([
            'executable-path' => __DIR__ . '/../Fixtures/scripts/pint-fake-fix.sh',
        ], $extraConfig)));

        return $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration()));
    }

    /** @test A staged dirty file fixed by Pint ends up staged and formatted — no `AM` state */
    public function it_restages_files_fixed_by_pint()
    {
        $this->stageDirtyFile();
        $this->setFakeEnv('PINT_FAKE_TARGET', $this->filePath);

        $result = $this->runPintJob();

        $this->assertTrue($result->isSuccess(), 'Pint exit 0 with fixes applied is a success');
        $this->assertTrue($result->getJobResults()[0]->isFixApplied(), 'fixApplied should be true');
        $this->assertEmpty(
            trim((string) shell_exec('git diff --name-only')),
            'No unstaged changes should remain — fixes must be re-staged (no AM state)'
        );
        $this->assertSame(
            self::FIXED_CONTENT,
            $this->stagedContent(),
            'The staged content must be exactly the fixed version'
        );
    }

    /** @test `--test` on a dirty tree exits 1, rewrites nothing and never re-stages */
    public function it_does_not_restage_in_test_mode()
    {
        $this->stageDirtyFile();
        $this->setFakeEnv('PINT_FAKE_EXIT', '1');

        $result = $this->runPintJob(['test' => true]);

        $this->assertFalse($result->isSuccess(), 'a dirty --test check is a failed job');
        $this->assertFalse($result->getJobResults()[0]->isFixApplied(), 'check mode never applies fixes');
        $this->assertSame(
            self::DIRTY_CONTENT,
            $this->stagedContent(),
            'The staged content must remain the original, unformatted version'
        );
    }
}
