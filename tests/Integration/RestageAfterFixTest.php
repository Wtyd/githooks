<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PhpcbfJob;
use Wtyd\GitHooks\Output\NullOutputHandler;
use Wtyd\GitHooks\Utils\GitStager;

/**
 * Verifies that when a fix-applying job (phpcbf) modifies staged files,
 * those changes are automatically re-staged so the commit includes the fixes.
 *
 * @group git
 */
class RestageAfterFixTest extends SystemTestCase
{
    protected static $gitFilesPathTest = __DIR__ . '/../../' . SystemTestCase::TESTS_PATH . '/gitTests';

    /** @var string */
    protected $headBeforeTest;

    protected function setUp(): void
    {
        parent::setUp();

        shell_exec('git reset --hard HEAD 2>/dev/null');
        shell_exec('git config user.email "test@test.com" 2>/dev/null');
        shell_exec('git config user.name "Test" 2>/dev/null');

        mkdir(self::$gitFilesPathTest, 0777, true);

        $this->headBeforeTest = trim(shell_exec('git rev-parse HEAD'));
    }

    protected function tearDown(): void
    {
        $currentHead = trim(shell_exec('git rev-parse HEAD'));
        if ($currentHead !== $this->headBeforeTest) {
            shell_exec('git reset --hard ' . $this->headBeforeTest);
        } else {
            shell_exec('git reset --hard HEAD 2>/dev/null');
        }

        parent::tearDown();
    }

    /** @test */
    function it_restages_files_modified_by_a_fix_job()
    {
        // 1. Create a PHP file with bad formatting, force-stage it (testsDir is in .gitignore)
        $filePath = self::$gitFilesPathTest . '/Fixable.php';
        $badCode = "<?php\nclass Fixable { public function a(){} }\n";
        file_put_contents($filePath, $badCode);
        shell_exec('git add -f ' . escapeshellarg($filePath));

        // 2. Verify: file is staged with bad code
        $stagedBefore = shell_exec('git diff --cached --name-only') ?? '';
        $this->assertStringContainsString('Fixable.php', $stagedBefore, 'File should be staged');

        // 3. Simulate phpcbf fixing the file on disk (this is what happens during pre-commit:
        //    the file is staged with bad code, phpcbf runs and fixes the working tree copy)
        $fixedCode = "<?php\n\nclass Fixable\n{\n    public function a()\n    {\n    }\n}\n";
        file_put_contents($filePath, $fixedCode);

        // 4. Verify: the fix is NOT staged yet (working tree differs from index)
        $unstagedBefore = trim(shell_exec('git diff --name-only') ?? '');
        $this->assertNotEmpty($unstagedBefore, 'Fixed file should appear as unstaged change');

        // 5. Execute FlowExecutor with GitStager — phpcbf job that exits 1 (= fix applied)
        $executor = new FlowExecutor(new NullOutputHandler(), new GitStager());
        $job = new PhpcbfJob(new JobConfiguration('phpcbf_test', 'phpcbf', [
            'executablePath' => '/bin/sh -c',
            'otherArguments' => '"exit 1"',
        ]));

        $plan = new FlowPlan('test', [$job], new OptionsConfiguration());
        $result = $executor->execute($plan);

        // 6. Job should be treated as success (fix applied)
        $this->assertTrue($result->isSuccess(), 'phpcbf fix should be treated as success');
        $this->assertTrue($result->getJobResults()[0]->isFixApplied(), 'fixApplied should be true');

        // 7. The working tree fix should now be staged (re-staged by GitStager)
        $unstagedAfter = trim(shell_exec('git diff --name-only') ?? '');
        $this->assertEmpty($unstagedAfter, 'No unstaged changes should remain — fixes must be re-staged');

        // 8. Verify the staged content is the FIXED version, not the original bad code
        $stagedContent = shell_exec('git diff --cached -- ' . escapeshellarg($filePath)) ?? '';
        $this->assertStringContainsString('public function a()', $stagedContent);
    }
}
