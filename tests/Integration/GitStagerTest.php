<?php

namespace Tests\Integration;

use Tests\Utils\PhpFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Utils\GitStager;

/**
 * Tests GitStager with real git operations.
 * Before executing this test suite after any changes, you must commit these changes.
 * @group git
 */
class GitStagerTest extends SystemTestCase
{
    protected static $gitFilesPathTest = __DIR__ . '/../../' . SystemTestCase::TESTS_PATH . '/gitTests';

    /** @var string */
    protected $headBeforeTest;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure clean git state before each test
        shell_exec('git reset --hard HEAD 2>/dev/null');

        mkdir(self::$gitFilesPathTest);

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
    function it_restages_renamed_file_without_errors_when_original_is_deleted()
    {
        $originalPath = self::$gitFilesPathTest . '/Original.php';
        $renamedPath = self::$gitFilesPathTest . '/Renamed.php';
        file_put_contents($originalPath, "<?php\nclass Original {}\n");

        shell_exec('git add ' . $originalPath);
        shell_exec('git commit -m "temp: add file for restage test"');

        // Rename the file and then modify it (simulating phpcbf fix after rename)
        shell_exec('git mv ' . $originalPath . ' ' . $renamedPath);
        file_put_contents($renamedPath, "<?php\n\nclass Renamed\n{\n}\n");

        // Verify: rename is staged, content modification is unstaged
        $unstaged = trim(shell_exec('git diff --name-only'));
        $this->assertNotEmpty($unstaged, 'Modified renamed file should appear in unstaged changes');

        $gitStager = new GitStager();
        $gitStager->stageTrackedFiles();

        // After restage: the content modification should now be staged, nothing unstaged
        $unstagedAfter = trim(shell_exec('git diff --name-only'));
        $this->assertEmpty($unstagedAfter, 'No unstaged changes should remain after stageTrackedFiles');
    }
}
