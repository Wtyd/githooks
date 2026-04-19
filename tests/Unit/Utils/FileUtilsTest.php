<?php

namespace Tests\Unit\Utils;

use Illuminate\Support\Facades\Storage;
use Tests\Zero\ZeroTestCase;
use Wtyd\GitHooks\Utils\FileUtils;

class FileUtilsTest extends ZeroTestCase
{

    /** @test */
    function directory_does_not_contains_a_deleted_file()
    {
        $file = 'src/Hooks.php';
        Storage::shouldReceive('exists')
            ->twice()
            ->with($file)
            ->andReturn(false, false);

        $fileUtils = new FileUtils();

        $directory = 'src';

        $this->assertFalse($fileUtils->directoryContainsFile($directory, $file), 'The file Exists');

        $directory = './';
        $this->assertFalse($fileUtils->directoryContainsFile($directory, $file), 'The file Exists');
    }

    /** @test */
    function directory_contains_any_file_that_exist_if_directory_is_root_directory()
    {
        $file = 'src/Hooks.php';
        Storage::shouldReceive('exists')
            ->with($file)
            ->andReturn(true);

        $fileUtils = new FileUtils();

        $directory = './';

        $this->assertTrue($fileUtils->directoryContainsFile($directory, $file));
    }

    public function directoryContainsFileDataProvider()
    {
        return [
            'File is in root directory' => [
                'src',
                'src/Hooks.php'
            ],
            'File is in subdirectory' => [
                'src',
                'src/SubDir/Hooks.php'
            ],
            'File is in sub subdirectory' => [
                'src',
                'src/SubDir/AnotherDir/Hooks.php'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider directoryContainsFileDataProvider
     */
    function directoy_contains_file($directory, $file)
    {
        Storage::shouldReceive('exists')
            ->with($file)
            ->andReturn(true);

        Storage::shouldReceive('allFiles')
            ->once()
            ->with($directory)
            ->andReturn([
                'src/Hooks.php',
                'src/SubDir/Hooks.php',
                'src/SubDir/AnotherDir/Hooks.php',
            ]);

        $fileUtils = new FileUtils();

        $this->assertTrue($fileUtils->directoryContainsFile($directory, $file));
    }

    /**
     * @test
     * @dataProvider directoryContainsFileDataProvider
     */
    function directoy_DOESNT_contains_file($directory, $file)
    {
        Storage::shouldReceive('exists')
            ->with($file)
            ->andReturn(true);

        Storage::shouldReceive('allFiles')
            ->once()
            ->with($directory)
            ->andReturn([
                'src/OtherFile.php',
                'src/SubDir/OtherFile.php',
                'src/SubDir/AnotherDir/OtherFile.php',
            ]);

        $fileUtils = new FileUtils();

        $this->assertFalse($fileUtils->directoryContainsFile($directory, $file));
    }

    // ========================================================================
    // isSameFile tests
    // ========================================================================

    /** @test */
    function isSameFile_returns_true_for_identical_paths()
    {
        $fileUtils = new FileUtils();

        $this->assertTrue($fileUtils->isSameFile('src/Foo.php', 'src/Foo.php'));
    }

    /** @test */
    function isSameFile_returns_false_for_different_paths()
    {
        $fileUtils = new FileUtils();

        $this->assertFalse($fileUtils->isSameFile('src/Foo.php', 'src/Bar.php'));
    }

    /** @test */
    function isSameFile_strips_root_path_prefix_from_first_file()
    {
        $fileUtils = new FileUtils();

        $this->assertTrue($fileUtils->isSameFile('./src/Foo.php', 'src/Foo.php'));
    }

    /** @test */
    function isSameFile_strips_root_path_prefix_from_second_file()
    {
        $fileUtils = new FileUtils();

        $this->assertTrue($fileUtils->isSameFile('src/Foo.php', './src/Foo.php'));
    }

    /** @test */
    function isSameFile_strips_root_path_prefix_from_both_files()
    {
        $fileUtils = new FileUtils();

        $this->assertTrue($fileUtils->isSameFile('./src/Foo.php', './src/Foo.php'));
    }

    /** @test */
    function isSameFile_returns_false_when_base_paths_differ_with_root_prefix()
    {
        $fileUtils = new FileUtils();

        $this->assertFalse($fileUtils->isSameFile('./src/Foo.php', './tests/Foo.php'));
    }

    // ========================================================================
    // detectMainBranch
    // ========================================================================

    /** @var string[] */
    private const CI_VARS = [
        'GITHUB_BASE_REF',
        'CI_MERGE_REQUEST_TARGET_BRANCH_NAME',
        'BITBUCKET_PR_DESTINATION_BRANCH',
    ];

    private ?string $detectMainBranchTempDir = null;

    private ?string $detectMainBranchCwd = null;

    protected function tearDown(): void
    {
        foreach (self::CI_VARS as $var) {
            putenv($var);
        }
        if ($this->detectMainBranchCwd !== null) {
            chdir($this->detectMainBranchCwd);
            $this->detectMainBranchCwd = null;
        }
        if ($this->detectMainBranchTempDir !== null && is_dir($this->detectMainBranchTempDir)) {
            $this->removeRecursive($this->detectMainBranchTempDir);
            $this->detectMainBranchTempDir = null;
        }
        parent::tearDown();
    }

    /**
     * @test
     * @dataProvider ciVarProvider
     */
    function detectMainBranch_returns_value_from_ci_variable(string $ciVar, string $value)
    {
        $this->clearCiVars();
        putenv("$ciVar=$value");

        $this->assertSame($value, (new FileUtils())->detectMainBranch());
    }

    public function ciVarProvider(): array
    {
        return [
            'GITHUB_BASE_REF' => ['GITHUB_BASE_REF', 'develop'],
            'CI_MERGE_REQUEST_TARGET_BRANCH_NAME' => ['CI_MERGE_REQUEST_TARGET_BRANCH_NAME', 'main'],
            'BITBUCKET_PR_DESTINATION_BRANCH' => ['BITBUCKET_PR_DESTINATION_BRANCH', 'master'],
        ];
    }

    /** @test */
    function detectMainBranch_skips_empty_ci_variable_and_uses_next()
    {
        $this->clearCiVars();
        putenv('GITHUB_BASE_REF=');
        putenv('CI_MERGE_REQUEST_TARGET_BRANCH_NAME=stable');

        $this->assertSame('stable', (new FileUtils())->detectMainBranch());
    }

    /** @test */
    function detectMainBranch_prefers_earlier_ci_variables_in_the_list()
    {
        $this->clearCiVars();
        putenv('GITHUB_BASE_REF=gh-base');
        putenv('CI_MERGE_REQUEST_TARGET_BRANCH_NAME=gl-base');

        $this->assertSame('gh-base', (new FileUtils())->detectMainBranch());
    }

    /** @test */
    function detectMainBranch_returns_branch_from_git_symbolic_ref()
    {
        $this->clearCiVars();
        $this->initGitRepoWithMainBranch('custom-main');
        shell_exec(sprintf(
            'git -C %s symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/custom-main 2>&1',
            escapeshellarg($this->detectMainBranchTempDir)
        ));

        $this->assertSame('custom-main', (new FileUtils())->detectMainBranch());
    }

    /** @test */
    function detectMainBranch_falls_back_to_master_when_no_ci_and_no_symbolic_ref()
    {
        $this->clearCiVars();
        $this->initGitRepoWithMainBranch('master');

        $this->assertSame('master', (new FileUtils())->detectMainBranch());
    }

    /** @test */
    function detectMainBranch_falls_back_to_main_when_master_not_present()
    {
        $this->clearCiVars();
        $this->initGitRepoWithMainBranch('main');

        $this->assertSame('main', (new FileUtils())->detectMainBranch());
    }

    /** @test */
    function detectMainBranch_returns_null_when_no_detection_succeeds()
    {
        $this->clearCiVars();
        $this->detectMainBranchTempDir = sys_get_temp_dir() . '/githooks_nobranch_' . uniqid();
        mkdir($this->detectMainBranchTempDir, 0755, true);
        $this->detectMainBranchCwd = getcwd() ?: sys_get_temp_dir();
        chdir($this->detectMainBranchTempDir);

        $this->assertNull((new FileUtils())->detectMainBranch());
    }

    private function clearCiVars(): void
    {
        foreach (self::CI_VARS as $var) {
            putenv($var);
        }
    }

    private function initGitRepoWithMainBranch(string $branchName): void
    {
        $this->detectMainBranchTempDir = sys_get_temp_dir() . '/githooks_branch_' . uniqid();
        mkdir($this->detectMainBranchTempDir, 0755, true);
        $dir = escapeshellarg($this->detectMainBranchTempDir);
        shell_exec("git -C $dir init --quiet -b $branchName 2>&1 || git -C $dir init --quiet 2>&1");
        shell_exec("git -C $dir checkout -b $branchName 2>&1 || true");
        shell_exec("git -C $dir config user.email test@example.com 2>&1");
        shell_exec("git -C $dir config user.name Test 2>&1");
        file_put_contents($this->detectMainBranchTempDir . '/README', 'x');
        shell_exec("git -C $dir add README 2>&1");
        shell_exec("git -C $dir commit --quiet -m initial 2>&1");
        $this->detectMainBranchCwd = getcwd() ?: sys_get_temp_dir();
        chdir($this->detectMainBranchTempDir);
    }

    private function removeRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
