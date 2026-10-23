<?php

namespace Tests\Integration;

use Tests\Utils\TestCase\SystemTestCase;
use Tests\Utils\Traits\GitSandboxTrait;
use Wtyd\Alfil\Utils\GitStager;

/**
 * Tests GitStager against a sandboxed git repo created in /tmp. The
 * project's real working tree is never touched — see GitSandboxTrait
 * for the isolation model.
 *
 * @group git
 */
class GitStagerTest extends SystemTestCase
{
    use GitSandboxTrait;

    /** @var string Absolute path inside the sandbox. */
    protected $gitFilesPathTest;

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
        $this->tearDownGitSandbox();

        parent::tearDown();
    }

    /** @test */
    public function it_restages_renamed_file_without_errors_when_original_is_deleted()
    {
        $originalPath = $this->gitFilesPathTest . '/Original.php';
        $renamedPath = $this->gitFilesPathTest . '/Renamed.php';
        file_put_contents($originalPath, "<?php\nclass Original {}\n");

        shell_exec('git add -f ' . escapeshellarg($originalPath));
        shell_exec('git commit --quiet -m "temp: add file for restage test"');

        // The snapshot the executor takes before the jobs run: a clean index.
        $gitStager = new GitStager();
        $snapshot = $gitStager->snapshotStagedFiles();

        // Rename the file and then modify it (simulating phpcbf fix after rename)
        shell_exec('git mv -f ' . escapeshellarg($originalPath) . ' ' . escapeshellarg($renamedPath));
        file_put_contents($renamedPath, "<?php\n\nclass Renamed\n{\n}\n");

        // Verify: rename is staged, content modification is unstaged
        $unstaged = trim((string) shell_exec('git diff --name-only'));
        $this->assertNotEmpty($unstaged, 'Modified renamed file should appear in unstaged changes');

        // The renamed path entered the index after the snapshot, so it is re-staged
        // whole; the deleted original is skipped (`git add` on it would fail).
        $gitStager->stageModifiedSince($snapshot);

        // After restage: the content modification should now be staged, nothing unstaged
        $unstagedAfter = trim((string) shell_exec('git diff --name-only'));
        $this->assertEmpty($unstagedAfter, 'No unstaged changes should remain after stageModifiedSince');
    }
}
