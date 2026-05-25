<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for the four execution modes (`full`, `fast`, `fast-branch`,
 * `fast-dirty`) and their mutual-exclusion contract with the file-listing
 * flags. Covers:
 *  - FEAT-13 `--fast-dirty` end-to-end against the compiled `.phar`.
 *  - FEAT-13 mutual exclusion of set-defining flags
 *    (`--fast`, `--fast-branch`, `--fast-dirty`, `--files`, `--files-from`).
 *  - FEAT-13 clean-worktree semantics (skip with literal `no changes to
 *    validate`, no fallback to `full`).
 *  - BUG-20 (3.4) per-key cascade of `fast-branch-fallback` when a flow
 *    declares its own `options:` block.
 *
 * @group release
 */
class ExecutionModesReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /**
     * FEAT-13 — `--fast-dirty` end-to-end against the compiled `.phar`.
     *
     * Materialises a sandbox git repo inside TESTS_PATH, chdirs into it,
     * then invokes the `.phar` so my own uncommitted changes in html1 never
     * leak into the assertion.
     *
     * @test
     */
    public function phar_runs_flow_with_fast_dirty_mode(): void
    {
        $tests = self::TESTS_PATH;
        $sandbox = "$tests/sandbox-fast-dirty";
        $cwd = (string) getcwd();
        @mkdir($sandbox, 0777, true);
        @mkdir("$sandbox/src/AppA", 0777, true);
        @mkdir("$sandbox/src/AppB", 0777, true);

        chdir($sandbox);
        try {
            shell_exec('git init --quiet');
            shell_exec('git symbolic-ref HEAD refs/heads/main');
            shell_exec('git config user.email "v34-release@example.com"');
            shell_exec('git config user.name "V34 Release Test"');
            shell_exec('git config commit.gpgsign false');
            file_put_contents('src/AppA/Foo.php', "<?php\n");
            file_put_contents('src/AppB/Bar.php', "<?php\n");
            shell_exec('git add -A');
            shell_exec('git commit --quiet -m baseline');

            // Dirty only AppB → an entry restricted to AppA must skip,
            // confirming admission composes with --fast-dirty.
            file_put_contents('src/AppB/Bar.php', "<?php\n// dirty\n");

            $configFile = 'githooks.php';
            $config = [
                'flows' => [
                    'qa' => [
                        'jobs' => [
                            ['job' => 'lint_appA', 'only-files' => ['src/AppA/**']],
                            ['job' => 'lint_appB', 'only-files' => ['src/AppB/**']],
                        ],
                    ],
                ],
                'jobs' => [
                    'lint_appA' => ['type' => 'custom', 'script' => 'true', 'paths' => ['src/AppA'], 'accelerable' => true],
                    'lint_appB' => ['type' => 'custom', 'script' => 'true', 'paths' => ['src/AppB'], 'accelerable' => true],
                ],
            ];
            file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");

            $binary = $cwd . DIRECTORY_SEPARATOR . $this->githooks;

            passthru(
                sprintf(
                    '%s flow qa --fast-dirty --format=json --config=%s 2>/dev/null',
                    $binary,
                    $configFile
                ),
                $exitCode
            );

            $this->assertSame(0, $exitCode);
            $decoded = json_decode($this->getActualOutput(), true);
            $this->assertIsArray($decoded);
            $this->assertSame('fast-dirty', $decoded['executionMode']);
            $this->assertSame('cli', $decoded['effectiveOptions']['executionMode']['source']);

            $byName = $this->indexJobs($decoded['jobs']);
            $this->assertTrue($byName['lint_appA']['skipped'], 'AppA must skip — no dirty files match');
            $this->assertSame(
                'no files in the change set match its only-files rule',
                $byName['lint_appA']['skipReason']
            );
            $this->assertFalse($byName['lint_appB']['skipped'], 'AppB must run — Bar.php is dirty');
        } finally {
            chdir($cwd);
        }
    }

    /**
     * FEAT-13 — `--fast-dirty` and `--fast` together must be rejected with
     * the deterministic message emitted by AssertsExecutionModeFlagsExclusive.
     *
     * @test
     */
    public function phar_rejects_fast_dirty_combined_with_fast(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['noop']]])
            ->setV3Jobs(['noop' => ['type' => 'custom', 'script' => 'true', 'paths' => ['.']]]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $cmd = sprintf(
            '%s flow qa --fast --fast-dirty --config=%s 2>&1',
            $this->githooks,
            $this->configPath
        );

        passthru($cmd, $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            '--fast-dirty and --fast are mutually exclusive',
            $this->getActualOutput()
        );
    }

    /**
     * FEAT-13 — `--fast-dirty` and `--files` together must be rejected with
     * the same mutual-exclusion message (the trait surfaces the first conflict
     * encountered, naming `--fast-dirty` first).
     *
     * @test
     */
    public function phar_rejects_fast_dirty_combined_with_files(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['noop']]])
            ->setV3Jobs(['noop' => ['type' => 'custom', 'script' => 'true', 'paths' => ['.']]]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $cmd = sprintf(
            '%s flow qa --fast-dirty --files=foo.php --config=%s 2>&1',
            $this->githooks,
            $this->configPath
        );

        passthru($cmd, $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            '--fast-dirty and --files are mutually exclusive',
            $this->getActualOutput()
        );
    }

    /**
     * FEAT-13 — on a clean worktree `--fast-dirty` must NOT fall back to full.
     * Every accelerable job skips with the literal reason `no changes to
     * validate` and the flow exits 0. The sandbox commits the config too,
     * otherwise it appears as untracked and the worktree diff is non-empty.
     *
     * @test
     */
    public function phar_fast_dirty_on_clean_worktree_skips_with_no_changes_to_validate(): void
    {
        $tests = self::TESTS_PATH;
        $sandbox = "$tests/sandbox-fast-dirty-clean";
        $cwd = (string) getcwd();
        @mkdir("$sandbox/src", 0777, true);

        chdir($sandbox);
        try {
            shell_exec('git init --quiet');
            shell_exec('git symbolic-ref HEAD refs/heads/main');
            shell_exec('git config user.email "v34-release@example.com"');
            shell_exec('git config user.name "V34 Release Test"');
            shell_exec('git config commit.gpgsign false');
            file_put_contents('src/Clean.php', "<?php\n");
            $configFile = 'githooks.php';
            $config = [
                'flows' => [
                    'qa' => [
                        'jobs' => ['lint_src'],
                    ],
                ],
                'jobs' => [
                    'lint_src' => [
                        'type'        => 'custom',
                        'script'      => 'true',
                        'paths'       => ['src'],
                        'accelerable' => true,
                    ],
                ],
            ];
            file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");
            shell_exec('git add -A');
            shell_exec('git commit --quiet -m baseline');

            $binary = $cwd . DIRECTORY_SEPARATOR . $this->githooks;

            passthru(
                sprintf('%s flow qa --fast-dirty --format=json --config=%s 2>/dev/null', $binary, $configFile),
                $exitCode
            );

            $this->assertSame(0, $exitCode, 'clean worktree + fast-dirty must exit 0 (no fallback to full)');
            $decoded = json_decode($this->getActualOutput(), true);
            $this->assertIsArray($decoded);
            $this->assertSame('fast-dirty', $decoded['executionMode']);

            $byName = $this->indexJobs($decoded['jobs']);
            $this->assertTrue($byName['lint_src']['skipped'], 'accelerable job must skip on clean worktree');
            $this->assertSame('no changes to validate', $byName['lint_src']['skipReason']);
        } finally {
            chdir($cwd);
        }
    }

    /**
     * BUG-20 (3.4) — `fast-branch-fallback` declared at `flows.options` must
     * cascade per-key when the flow declares its own `options:` block. With
     * `main-branch` pointing at a nonexistent ref, the `--fast-branch` diff
     * fails and the fallback kicks in. Cascade broken → default `full` →
     * lint_src would run on declared paths; cascade OK → `fast` → lint_src
     * skips because there are no staged files in the sandbox.
     *
     * @test
     */
    public function phar_cascades_fast_branch_fallback_per_key_when_flow_declares_options(): void
    {
        $tests = self::TESTS_PATH;
        $sandbox = "$tests/sandbox-fbfallback";
        $cwd = (string) getcwd();
        @mkdir("$sandbox/src", 0777, true);

        chdir($sandbox);
        try {
            shell_exec('git init --quiet');
            shell_exec('git symbolic-ref HEAD refs/heads/feature-x');
            shell_exec('git config user.email "v34-release@example.com"');
            shell_exec('git config user.name "V34 Release Test"');
            shell_exec('git config commit.gpgsign false');
            file_put_contents('src/Clean.php', "<?php\n");
            shell_exec('git add -A');
            shell_exec('git commit --quiet -m baseline');

            $configFile = 'githooks.php';
            $config = [
                'flows' => [
                    'options' => [
                        'main-branch'          => 'no-such-branch-xyz',
                        'fast-branch-fallback' => 'fast',
                    ],
                    'qa' => [
                        'options' => ['fail-fast' => true],
                        'jobs'    => ['lint_src'],
                    ],
                ],
                'jobs' => [
                    'lint_src' => [
                        'type'        => 'custom',
                        'script'      => 'true',
                        'paths'       => ['src'],
                        'accelerable' => true,
                    ],
                ],
            ];
            file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");

            $binary = $cwd . DIRECTORY_SEPARATOR . $this->githooks;

            passthru(
                sprintf('%s flow qa --fast-branch --format=json --config=%s 2>/dev/null', $binary, $configFile),
                $exitCode
            );

            $this->assertSame(0, $exitCode);
            $decoded = json_decode($this->getActualOutput(), true);
            $this->assertIsArray($decoded);

            $byName = $this->indexJobs($decoded['jobs']);
            // Cascade OK → fallback to FAST kicks in → no staged files in the
            // sandbox → job skipped. Cascade broken (BUG-20 regression) →
            // default fallback "full" is used → job RUNS on its declared
            // paths (no skip).
            $this->assertTrue(
                $byName['lint_src']['skipped'],
                'fast-branch-fallback=fast must cascade per-key: with no staged files lint_src must skip'
            );
            $this->assertSame('no staged files match its paths', $byName['lint_src']['skipReason']);
        } finally {
            chdir($cwd);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     * @return array<string, array<string, mixed>>
     */
    private function indexJobs(array $jobs): array
    {
        $byName = [];
        foreach ($jobs as $job) {
            $byName[$job['name']] = $job;
        }
        return $byName;
    }
}
