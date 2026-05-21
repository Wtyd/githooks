<?php

declare(strict_types=1);

namespace Tests\System\CiFeatures;

use Tests\Utils\TestCase\CiFeatureTestCase;

/**
 * FEAT-13 end-to-end verification of `--fast-dirty` against a real git
 * sandbox. Mirrors {@see FastBranchTest}: each test materialises a real
 * repository in a temp directory, sets up its own dirty/clean state, and
 * invokes the source `githooks` entrypoint with `--fast-dirty`.
 *
 * Covers rows from the plan:
 *  - C1: dirty matches `only-files` → job admitted with filtered paths.
 *  - C2: dirty does not match `only-files` → job skipped.
 *  - D1: clean working tree → accelerable job skipped, exit 0.
 *  - A4 (smoke): untracked + staged file appear in the set.
 *
 * @group ci-features
 */
class FastDirtyTest extends CiFeatureTestCase
{
    private string $repoDir;

    private string $repoConfigPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'gh-fast-dirty-' . uniqid();
        mkdir($this->repoDir, 0777, true);
        mkdir($this->repoDir . DIRECTORY_SEPARATOR . 'src', 0777, true);
        mkdir($this->repoDir . DIRECTORY_SEPARATOR . 'qa', 0777, true);

        $this->repoConfigPath = $this->repoDir . DIRECTORY_SEPARATOR . 'githooks.php';

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
    public function fast_dirty_collects_staged_unstaged_and_untracked_in_the_set(): void
    {
        $this->initRepoOnMain();
        $this->writeFile('src/Committed.php', "<?php\n// committed\n");
        $this->writeRepoConfig(['paths' => ['src']]);
        $this->commitAll('initial');

        // Mix: tracked file modified + staged, plus an untracked file.
        $this->writeFile('src/Committed.php', "<?php\n// modified\n");
        $this->runGitCommand('add src/Committed.php');
        $this->writeFile('src/Untracked.php', "<?php\n// untracked\n");

        $result = $this->runGithooks(
            "flow qa --fast-dirty --format=json --config=$this->repoConfigPath",
            $this->repoDir
        );

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}\nstdout:\n{$result['stdout']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertSame('fast-dirty', $decoded['executionMode'] ?? null);

        $job = $this->findJob($decoded, 'lint_src');
        $paths = $job['paths'] ?? [];
        $this->assertContains('src/Committed.php', $paths, 'staged tracked file in set');
        $this->assertContains('src/Untracked.php', $paths, 'untracked non-ignored in set');
    }

    /** @test */
    public function fast_dirty_with_clean_tree_skips_accelerable_jobs_and_exits_zero(): void
    {
        $this->initRepoOnMain();
        $this->writeFile('src/Foo.php', "<?php\n// foo\n");
        $this->writeRepoConfig(['paths' => ['src']]);
        $this->commitAll('initial');

        // Clean working tree: nothing staged, nothing unstaged, nothing untracked.

        $result = $this->runGithooks(
            "flow qa --fast-dirty --format=json --config=$this->repoConfigPath",
            $this->repoDir
        );

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}\nstdout:\n{$result['stdout']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertSame('fast-dirty', $decoded['executionMode'] ?? null);

        $job = $this->findJob($decoded, 'lint_src');
        $this->assertTrue($job['skipped'] ?? false, 'expected job skipped when tree is clean');
    }

    /** @test */
    public function fast_dirty_composes_with_only_files_admission_skip(): void
    {
        $this->initRepoOnMain();
        mkdir($this->repoDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'AppA', 0777, true);
        mkdir($this->repoDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'AppB', 0777, true);
        $this->writeFile('src/AppA/Foo.php', "<?php\n// AppA baseline\n");
        $this->writeFile('src/AppB/Bar.php', "<?php\n// AppB baseline\n");

        // Config: flow entry only-files restricted to AppA; the job paths cover both.
        $this->writeRepoConfig([
            'paths'       => ['src'],
            'flowEntries' => [
                ['job' => 'lint_src', 'only-files' => ['src/AppA/**']],
            ],
        ]);
        $this->commitAll('initial');

        // Dirty only in AppB → admission must reject the entry.
        $this->writeFile('src/AppB/Bar.php', "<?php\n// modified in AppB\n");

        $result = $this->runGithooks(
            "flow qa --fast-dirty --format=json --config=$this->repoConfigPath",
            $this->repoDir
        );

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}\nstdout:\n{$result['stdout']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $job = $this->findJob($decoded, 'lint_src');
        $this->assertTrue($job['skipped'] ?? false, 'expected skip — set does not match only-files');
        $this->assertSame(
            'no files in the change set match its only-files rule',
            $job['skipReason'] ?? null
        );
    }

    // ---------------------------------------------------------------------
    // Helpers (clone of FastBranchTest)
    // ---------------------------------------------------------------------

    private function initRepoOnMain(): void
    {
        $this->runGitCommand('init --quiet');
        $this->runGitCommand('symbolic-ref HEAD refs/heads/main');
        $this->runGitCommand('config user.email "ci-features@example.com"');
        $this->runGitCommand('config user.name "CI Features Test"');
        $this->runGitCommand('config commit.gpgsign false');
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

    /**
     * @param array{paths?: string[], flowEntries?: array<int, mixed>} $opts
     */
    private function writeRepoConfig(array $opts): void
    {
        $paths = $opts['paths'] ?? ['src'];
        $flowEntries = $opts['flowEntries'] ?? ['lint_src'];

        $config = [
            'flows' => [
                'qa' => ['jobs' => $flowEntries],
            ],
            'jobs' => [
                'lint_src' => [
                    'type'        => 'custom',
                    'script'      => PHP_BINARY . ' qa/noop.php',
                    'paths'       => $paths,
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
