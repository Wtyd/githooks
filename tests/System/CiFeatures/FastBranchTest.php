<?php

declare(strict_types=1);

namespace Tests\System\CiFeatures;

use Tests\Utils\TestCase\CiFeatureTestCase;

/**
 * End-to-end verification of fast-branch execution mode on real GHA
 * runners across Linux/Windows/macOS. Spec: spec-design-files-flag.md
 * (companion mode) and the FAST_BRANCH constant in ExecutionMode.
 *
 * Each test materialises a real git repository in a temp directory,
 * commits two files on `main`, branches off, modifies one of them, and
 * invokes the binary against that repo with `--fast-branch`. The JSON
 * output must report `executionMode = "fast-branch"` and the
 * accelerable job's paths must be filtered to the diff with `main`.
 *
 * @group ci-features
 */
class FastBranchTest extends CiFeatureTestCase
{
    private string $repoDir;

    private string $repoConfigPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'gh-fast-branch-' . uniqid();
        mkdir($this->repoDir, 0777, true);
        mkdir($this->repoDir . DIRECTORY_SEPARATOR . 'src', 0777, true);
        mkdir($this->repoDir . DIRECTORY_SEPARATOR . 'qa', 0777, true);

        $this->repoConfigPath = $this->repoDir . DIRECTORY_SEPARATOR . 'githooks.php';

        // Cross-OS quiet no-op invoked by the accelerable job.
        file_put_contents(
            $this->repoDir . DIRECTORY_SEPARATOR . 'qa' . DIRECTORY_SEPARATOR . 'noop.php',
            "<?php\nexit(0);\n"
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repoDir)) {
            $this->removeDirRecursive($this->repoDir);
        }
        parent::tearDown();
    }

    /** @test */
    public function fast_branch_filters_paths_to_diff_against_main(): void
    {
        $this->initRepoOnMain();
        $this->writeFile('src/Foo.php', "<?php\n// foo v1\n");
        $this->writeFile('src/Bar.php', "<?php\n// bar v1\n");
        $this->writeRepoConfig();
        $this->commitAll('initial');

        $this->checkoutBranch('feature');
        $this->writeFile('src/Foo.php', "<?php\n// foo v2 — modified on feature\n");
        $this->commitAll('feature change');

        $result = $this->runGithooks(
            "flow qa --fast-branch --format=json --config=$this->repoConfigPath",
            $this->repoDir
        );

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}\nstdout:\n{$result['stdout']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertSame('fast-branch', $decoded['executionMode'] ?? null, 'expected executionMode=fast-branch');

        $job = $this->findJob($decoded, 'lint_src');
        $paths = $job['paths'] ?? [];
        $this->assertContains('src/Foo.php', $paths, 'expected modified file in effective paths');
        $this->assertNotContains('src/Bar.php', $paths, 'expected unchanged file filtered out by fast-branch');
    }

    /** @test */
    public function fast_branch_with_no_diff_skips_accelerable_job(): void
    {
        $this->initRepoOnMain();
        $this->writeFile('src/Foo.php', "<?php\n// foo\n");
        $this->writeRepoConfig();
        $this->commitAll('initial');

        // Branch from main without any change: diff is empty.
        $this->checkoutBranch('feature');

        $result = $this->runGithooks(
            "flow qa --fast-branch --format=json --config=$this->repoConfigPath",
            $this->repoDir
        );

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}\nstdout:\n{$result['stdout']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertSame('fast-branch', $decoded['executionMode'] ?? null);

        $job = $this->findJob($decoded, 'lint_src');
        // With no diff, fast-branch leaves accelerable jobs without files to run on.
        // The contract is "skipped with reason" — the `skipped` field is the canonical
        // signal regardless of whether `paths` ends up empty or absent.
        $this->assertTrue($job['skipped'] ?? false, 'expected job skipped when fast-branch diff is empty');
    }

    /**
     * @test
     *
     * AC-001 (rebase scenario): after `main` advances independently and the
     * feature branch is rebased onto it, fast-branch must diff against the new
     * merge-base — i.e. report ONLY the feature's own change, not the commit
     * that now belongs to `main`. A naive base detection would wrongly include
     * main's file.
     */
    public function fast_branch_after_rebase_diffs_against_updated_main(): void
    {
        $this->initRepoOnMain();
        $this->writeFile('src/Foo.php', "<?php\n// foo v1\n");
        $this->writeFile('src/Bar.php', "<?php\n// bar v1\n");
        $this->writeRepoConfig();
        $this->commitAll('initial');

        // Feature branches off and changes Foo.
        $this->checkoutBranch('feature');
        $this->writeFile('src/Foo.php', "<?php\n// foo v2 — feature change\n");
        $this->commitAll('feature change');

        // main advances independently, changing Bar.
        $this->runGitCommand('checkout main');
        $this->writeFile('src/Bar.php', "<?php\n// bar v2 — main change\n");
        $this->commitAll('main advance');

        // Rebase feature onto the updated main: Bar is now part of main, so the
        // feature's diff vs main is only Foo.
        $this->runGitCommand('checkout feature');
        $this->runGitCommand('rebase main');

        $result = $this->runGithooks(
            "flow qa --fast-branch --format=json --config=$this->repoConfigPath",
            $this->repoDir
        );

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}\nstdout:\n{$result['stdout']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertSame('fast-branch', $decoded['executionMode'] ?? null);

        $job = $this->findJob($decoded, 'lint_src');
        $paths = $job['paths'] ?? [];
        $this->assertContains('src/Foo.php', $paths, 'expected feature change in effective paths after rebase');
        $this->assertNotContains('src/Bar.php', $paths, "expected main's file excluded — it is part of the rebase base");
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function initRepoOnMain(): void
    {
        // git ≥ 2.28 supports --initial-branch; older versions need a rename.
        $this->runGitCommand('init --quiet');
        $this->runGitCommand('symbolic-ref HEAD refs/heads/main');
        $this->runGitCommand('config user.email "ci-features@example.com"');
        $this->runGitCommand('config user.name "CI Features Test"');
        // Disable signing for runners with global signing config.
        $this->runGitCommand('config commit.gpgsign false');
    }

    private function checkoutBranch(string $name): void
    {
        $this->runGitCommand("checkout -b $name");
    }

    private function commitAll(string $message): void
    {
        $this->runGitCommand('add -A');
        $this->runGitCommand("commit -m " . escapeshellarg($message) . " --quiet");
    }

    private function runGitCommand(string $args): void
    {
        $cwd = getcwd();
        chdir($this->repoDir);
        try {
            exec("git $args 2>&1", $out, $exit);
            if ($exit !== 0) {
                $this->fail("git $args failed (exit $exit): " . implode("\n", $out));
            }
        } finally {
            if ($cwd !== false) {
                chdir($cwd);
            }
        }
    }

    private function writeFile(string $relPath, string $content): void
    {
        $abs = $this->repoDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($abs, $content);
    }

    private function writeRepoConfig(): void
    {
        $config = [
            'flows' => [
                'options' => ['main-branch' => 'main'],
                'qa'      => ['jobs' => ['lint_src']],
            ],
            'jobs' => [
                'lint_src' => [
                    'type'        => 'custom',
                    'script'      => PHP_BINARY . ' qa/noop.php',
                    'paths'       => ['src'],
                    'accelerable' => true,
                ],
            ],
        ];
        file_put_contents(
            $this->repoConfigPath,
            "<?php\nreturn " . var_export($config, true) . ";\n"
        );
    }

    private function removeDirRecursive(string $dir): void
    {
        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($dir);
    }
}
