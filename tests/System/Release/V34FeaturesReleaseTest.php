<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for v3.4 epic flow-entry-attrs:
 * - FEAT-1: only-files / exclude-files admission rules per flow entry.
 * - FEAT-2: branch-driven execution mode via flows.<X>.on.
 * - FEAT-3 + BUG-19: needs-per-entry propagation when an upstream is
 *   admission-skipped (the entire reason this file exists alongside the
 *   broader integration coverage).
 *
 * @group release
 * @group git
 */
class V34FeaturesReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /** @test */
    public function phar_runs_flow_with_only_files_admission_rule(): void
    {
        // CWD-relative paths so the `only-files` glob matches the same string
        // the InputFilesResolver shapes back to the user
        // (InputFilesResolver::shapeForUser keeps relative entries relative).
        $tests = self::TESTS_PATH;
        $srcFoo = "$tests/src/foo";
        $srcOther = "$tests/src/other";
        @mkdir($srcFoo, 0777, true);
        @mkdir($srcOther, 0777, true);
        file_put_contents("$srcFoo/Match.php", "<?php\n");
        file_put_contents("$srcOther/Other.php", "<?php\n");

        $this->configurationFileBuilder
            ->setV3Flows([
                'qa' => [
                    'jobs' => [
                        ['job' => 'lint_foo', 'only-files' => ["$tests/src/foo/**"]],
                        ['job' => 'lint_other', 'only-files' => ["$tests/src/other/**"]],
                    ],
                ],
            ])
            ->setV3Jobs([
                'lint_foo'   => ['type' => 'custom', 'script' => 'true', 'paths' => [$srcFoo], 'accelerable' => true],
                'lint_other' => ['type' => 'custom', 'script' => 'true', 'paths' => [$srcOther], 'accelerable' => true],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $cmd = sprintf(
            '%s flow qa --files=%s/Match.php --format=json --config=%s 2>/dev/null',
            $this->githooks,
            $srcFoo,
            $this->configPath
        );

        passthru($cmd, $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $byName = $this->indexJobs($decoded['jobs']);
        $this->assertFalse($byName['lint_foo']['skipped'], 'lint_foo must run when src/foo file is in the set');
        $this->assertTrue($byName['lint_other']['skipped'], 'lint_other must skip when no src/other file is in the set');
        $this->assertSame(
            'no files in the change set match its only-files rule',
            $byName['lint_other']['skipReason']
        );
    }

    /** @test */
    public function phar_runs_flow_with_branch_driven_execution_mode(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows([
                'qa' => [
                    'on' => [
                        'main'   => ['execution' => 'full'],
                        'master' => ['execution' => 'full'],
                        '*'      => ['execution' => 'fast-branch'],
                    ],
                    'jobs' => ['ok_job'],
                ],
            ])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => 'true']]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $cmdFor = function (string $branch): string {
            return sprintf(
                '%s flow qa --branch=%s --format=json --config=%s 2>/dev/null',
                $this->githooks,
                escapeshellarg($branch),
                $this->configPath
            );
        };

        $onMaster = json_decode((string) shell_exec($cmdFor('master')), true);
        $onFeature = json_decode((string) shell_exec($cmdFor('feature/x')), true);

        $this->assertSame('full', $onMaster['executionMode']);
        $this->assertSame('flows.qa.on', $onMaster['effectiveOptions']['executionMode']['source']);

        $this->assertSame('fast-branch', $onFeature['executionMode']);
        $this->assertSame('flows.qa.on', $onFeature['effectiveOptions']['executionMode']['source']);
    }

    /**
     * BUG-19 — when an upstream is admission-skipped, the dependent with
     * `needs: [<upstream>]` must propagate the skip instead of running.
     *
     * This is the contract that the integration test
     * `FlowAdmissionPropagationTest` exercises in-process; here we verify the
     * same contract end-to-end against the compiled `.phar`. Without the fix
     * this test fails (B runs) — that is its purpose: it would not have
     * caught the bug if it had been written before the fix landed.
     *
     * @test
     */
    public function phar_propagates_skip_via_needs_when_upstream_is_admission_skipped(): void
    {
        $tests = self::TESTS_PATH;
        $srcOther = "$tests/src/other";
        @mkdir($srcOther, 0777, true);
        file_put_contents("$srcOther/Other.php", "<?php\n");

        $this->configurationFileBuilder
            ->setV3Flows([
                'qa' => [
                    'jobs' => [
                        ['job' => 'upstream', 'only-files' => ["$tests/src/foo/**"]],
                        ['job' => 'downstream', 'needs' => ['upstream']],
                    ],
                ],
            ])
            ->setV3Jobs([
                'upstream'   => ['type' => 'custom', 'script' => 'true', 'paths' => [$srcOther], 'accelerable' => true],
                'downstream' => ['type' => 'custom', 'script' => 'true', 'paths' => [$srcOther], 'accelerable' => true],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // src/other/Other.php is in the change set; `upstream` only matches
        // src/foo/** → admission-skip; `downstream` needs `upstream` → must
        // propagate as "needs upstream was skipped".
        $cmd = sprintf(
            '%s flow qa --files=%s/Other.php --format=json --config=%s 2>/dev/null',
            $this->githooks,
            $srcOther,
            $this->configPath
        );

        passthru($cmd, $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $byName = $this->indexJobs($decoded['jobs']);
        $this->assertArrayHasKey('upstream', $byName);
        $this->assertArrayHasKey('downstream', $byName);

        $this->assertTrue($byName['upstream']['skipped'], 'upstream must be admission-skipped');
        $this->assertSame(
            'no files in the change set match its only-files rule',
            $byName['upstream']['skipReason']
        );

        $this->assertTrue(
            $byName['downstream']['skipped'],
            'downstream must propagate the skip via needs (BUG-19)'
        );
        $this->assertSame(
            'needs upstream was skipped',
            $byName['downstream']['skipReason'],
            'downstream skipReason must follow the "needs X was skipped" wording'
        );
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

            // ReleaseTestCase copies the binary into self::TESTS_PATH (parent of sandbox).
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
     * BUG-20 — `executable-prefix`, `fast-branch-fallback` and `reports`
     * cascade per-key from `flows.options` when a flow (or meta-flow) declares
     * its own `options:` block to override an unrelated key. End-to-end check
     * against the compiled `.phar` so the fix is verified inside the embedded
     * code, not just in the source tree.
     *
     * Setup: global `executable-prefix` set to `echo PREFIX_HIT`. The flow
     * declares `options: { fail-fast: true }` — no prefix override. Without
     * the fix, the prefix is lost; with it, the generated command for the
     * job is wrapped with the prefix.
     *
     * `--dry-run --format=json` exposes the resolved `command` in the JSON
     * envelope without executing the shell side-effect.
     *
     * @test
     */
    public function phar_cascades_executable_prefix_per_key_when_flow_declares_options(): void
    {
        $configFile = self::TESTS_PATH . '/githooks.php';
        $config = [
            'flows' => [
                'options' => ['executable-prefix' => 'echo PREFIX_HIT'],
                'qa' => [
                    'options' => ['fail-fast' => true],
                    'jobs' => ['noop_job'],
                ],
            ],
            'jobs' => [
                'noop_job' => [
                    'type' => 'custom',
                    'executable-path' => 'true',
                    'paths' => ['.'],
                ],
            ],
        ];
        file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");

        passthru(
            sprintf(
                '%s flow qa --dry-run --format=json --config=%s 2>/dev/null',
                $this->githooks,
                $configFile
            ),
            $exitCode
        );

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $byName = $this->indexJobs($decoded['jobs']);
        $this->assertArrayHasKey('noop_job', $byName);
        $this->assertStringStartsWith(
            'echo PREFIX_HIT ',
            (string) $byName['noop_job']['command'],
            'Global executable-prefix must cascade per-key when flow declares an options block'
        );
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
