<?php

declare(strict_types=1);

namespace Tests\Integration;

use Wtyd\GitHooks\Utils\FileUtils;
use Wtyd\GitHooks\Utils\FileUtilsInterface;
use Tests\Utils\TestCase\SystemTestCase;
use Tests\Utils\Traits\GitSandboxTrait;

/**
 * FEAT-13: `FileUtils::getWorktreeDiffFiles()` covers the unified working-tree set —
 *
 *   { git diff --name-only --diff-filter=ACMR HEAD
 *     git ls-files --others --exclude-standard
 *   } | sort -u
 *
 * That is: tracked files with staged or unstaged changes (excluding Deleted) ∪
 * untracked files that are not gitignored.
 *
 * Decision table from the plan (filas A1–A12). Each test asserts one row.
 *
 * @group git
 */
class FileUtilsWorktreeDiffTest extends SystemTestCase
{
    use GitSandboxTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGitSandbox();
        $this->app->bind(FileUtilsInterface::class, FileUtils::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownGitSandbox();
        parent::tearDown();
    }

    private function fileUtils(): FileUtils
    {
        return $this->app->make(FileUtils::class);
    }

    /**
     * Commit a file at the given relative path so subsequent diffs see it as
     * a tracked baseline. Returns the path used.
     */
    private function commitBaseline(string $relativePath, string $content = "<?php\n"): string
    {
        $dir = dirname($relativePath);
        if ($dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($relativePath, $content);
        shell_exec('git add ' . escapeshellarg($relativePath));
        shell_exec('git commit --quiet -m "baseline ' . $relativePath . '"');
        return $relativePath;
    }

    // ------------------------------------------------------------------
    // A1: tracked clean → excluido
    // ------------------------------------------------------------------

    /** @test */
    public function A1_excludes_tracked_clean_files(): void
    {
        $this->commitBaseline('src/Clean.php');

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        $this->assertNotNull($set, 'set must be computed (repo has HEAD)');
        $this->assertNotContains('src/Clean.php', $set);
    }

    // ------------------------------------------------------------------
    // A2: tracked staged (A/M/C/R) → incluido
    // ------------------------------------------------------------------

    /** @test */
    public function A2_includes_tracked_staged_modification(): void
    {
        $this->commitBaseline('src/Modified.php');
        file_put_contents('src/Modified.php', "<?php\n// staged change\n");
        shell_exec('git add src/Modified.php');

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        $this->assertContains('src/Modified.php', $set);
    }

    /** @test */
    public function A2_includes_tracked_staged_addition(): void
    {
        mkdir('src', 0755, true);
        file_put_contents('src/NewlyAdded.php', "<?php\n");
        shell_exec('git add src/NewlyAdded.php');

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        $this->assertContains('src/NewlyAdded.php', $set);
    }

    // ------------------------------------------------------------------
    // A3: tracked unstaged modificado → incluido
    // ------------------------------------------------------------------

    /** @test */
    public function A3_includes_tracked_unstaged_modification(): void
    {
        $this->commitBaseline('src/Unstaged.php');
        file_put_contents('src/Unstaged.php', "<?php\n// unstaged change\n");

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        $this->assertContains('src/Unstaged.php', $set);
    }

    // ------------------------------------------------------------------
    // A4: tracked staged + extra unstaged → incluido SOLO una vez
    // ------------------------------------------------------------------

    /** @test */
    public function A4_includes_file_once_when_staged_and_then_modified_again(): void
    {
        $this->commitBaseline('src/Both.php');
        file_put_contents('src/Both.php', "<?php\n// first round\n");
        shell_exec('git add src/Both.php');
        file_put_contents('src/Both.php', "<?php\n// first round\n// then more unstaged\n");

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        $this->assertContains('src/Both.php', $set);
        $this->assertSame(
            1,
            count(array_filter($set, fn(string $p): bool => $p === 'src/Both.php')),
            'file appears exactly once (dedup)'
        );
    }

    // ------------------------------------------------------------------
    // A5: untracked no-ignored → incluido
    // ------------------------------------------------------------------

    /** @test */
    public function A5_includes_untracked_non_ignored_files(): void
    {
        mkdir('src', 0755, true);
        file_put_contents('src/Untracked.php', "<?php\n");

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        $this->assertContains('src/Untracked.php', $set);
    }

    // ------------------------------------------------------------------
    // A6: untracked .gitignored → excluido
    // ------------------------------------------------------------------

    /** @test */
    public function A6_excludes_untracked_gitignored_files(): void
    {
        file_put_contents('.gitignore', "src/Ignored.php\n");
        shell_exec('git add .gitignore && git commit --quiet -m "add gitignore"');

        mkdir('src', 0755, true);
        file_put_contents('src/Ignored.php', "<?php\n");

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        $this->assertNotContains('src/Ignored.php', $set);
    }

    // ------------------------------------------------------------------
    // A7: tracked deleted (git rm) → excluido (filter ACMR descarta D)
    // ------------------------------------------------------------------

    /** @test */
    public function A7_excludes_files_deleted_via_git_rm(): void
    {
        $this->commitBaseline('src/ToDelete.php');
        shell_exec('git rm --quiet src/ToDelete.php');

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        $this->assertNotContains('src/ToDelete.php', $set);
    }

    // ------------------------------------------------------------------
    // A8: tracked rename → incluido (path destino)
    // ------------------------------------------------------------------

    /** @test */
    public function A8_includes_renamed_file_under_destination_path(): void
    {
        $this->commitBaseline('src/Old.php', "<?php\n// renamed source content\n");
        shell_exec('git mv src/Old.php src/New.php');

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        // Either path may appear depending on git's rename detection threshold;
        // the *destination* must always be present, and the file must exist on disk.
        $this->assertContains('src/New.php', $set, 'destination path must be in the set');
        $this->assertFileExists('src/New.php');
    }

    // ------------------------------------------------------------------
    // A9: git rm --cached + fichero sigue en wt → incluido como untracked
    // ------------------------------------------------------------------

    /** @test */
    public function A9_includes_file_after_git_rm_cached_when_still_in_worktree(): void
    {
        $this->commitBaseline('src/Detached.php');
        shell_exec('git rm --cached --quiet src/Detached.php');

        // Sanity: file is gone from index but still present on disk.
        $this->assertFileExists('src/Detached.php');

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        $this->assertContains('src/Detached.php', $set, 'must appear as untracked');
    }

    // ------------------------------------------------------------------
    // A10: tracked M en stage + deleted en wt → excluido (D neto vs HEAD)
    // ------------------------------------------------------------------

    /** @test */
    public function A10_excludes_file_modified_then_unlinked_in_worktree(): void
    {
        $this->commitBaseline('src/StagedThenGone.php');
        file_put_contents('src/StagedThenGone.php', "<?php\n// staged change\n");
        shell_exec('git add src/StagedThenGone.php');
        unlink('src/StagedThenGone.php'); // raw filesystem delete; not `git rm`

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        // `git diff HEAD` reports this as D (net delete vs HEAD), filter ACMR drops it.
        // `git ls-files --others` doesn't list it either (no file on disk).
        $this->assertNotContains('src/StagedThenGone.php', $set);
    }

    // ------------------------------------------------------------------
    // A11: repo recién inicializado sin HEAD → set vacío o null (fallback)
    // ------------------------------------------------------------------

    /** @test */
    public function A11_returns_empty_or_null_in_a_repo_without_HEAD(): void
    {
        // Re-initialise a clean repo *without* the empty initial commit that
        // GitSandboxTrait creates by default.
        shell_exec('rm -rf .git');
        shell_exec('git init --quiet 2>/dev/null');

        // Even before any commit, ls-files --others lists untracked files.
        mkdir('src', 0755, true);
        file_put_contents('src/Orphan.php', "<?php\n");

        $set = $this->fileUtils()->getWorktreeDiffFiles();

        // Either:
        //  - null (computation failed because HEAD does not resolve), OR
        //  - the untracked file only (diff vs HEAD silently empty).
        // Both are acceptable fallbacks; what we forbid is a fatal error.
        if ($set !== null) {
            $this->assertSame(['src/Orphan.php'], $set);
        }
        // null is also valid — assertion above is conditional.
        $this->assertTrue($set === null || is_array($set));
    }

    // ------------------------------------------------------------------
    // A12: no es un repo git → null, isEffectiveSetEmpty=true
    // ------------------------------------------------------------------

    /** @test */
    public function A12_returns_null_when_not_inside_a_git_repository(): void
    {
        // Move into a sibling directory of the sandbox (still inside /tmp but not a git repo).
        $nonRepo = $this->sandboxDir . '/../githooks-nonrepo-' . bin2hex(random_bytes(4));
        mkdir($nonRepo, 0755);
        chdir($nonRepo);

        try {
            $set = $this->fileUtils()->getWorktreeDiffFiles();
            $this->assertNull($set, 'must signal failure with null when not in a repo');
        } finally {
            chdir($this->sandboxDir);
            @rmdir($nonRepo);
        }
    }
}
