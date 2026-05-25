<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for the v3.4 epic `flow-entry-attrs`: per-flow-entry
 * attributes that compose with the execution mode.
 *  - FEAT-1: declarative `only-files` / `exclude-files` admission rules.
 *  - FEAT-2: branch-driven execution mode (`flows.<X>.on`).
 *  - FEAT-3 + BUG-19: `needs` propagation when an upstream is admission-skipped.
 *
 * @group release
 */
class FlowEntryAttrsReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    // ─── FEAT-1 · only-files / exclude-files ─────────────────────────

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

    /**
     * FEAT-1 — `exclude-files` filters specific files from the admitted set.
     * The job still runs when at least one file survives both `only-files`
     * and `exclude-files`.
     *
     * @test
     */
    public function phar_runs_flow_with_exclude_files_admission_rule(): void
    {
        $tests = self::TESTS_PATH;
        $srcFoo = "$tests/src/foo";
        @mkdir($srcFoo, 0777, true);
        file_put_contents("$srcFoo/Keep.php", "<?php\n");
        file_put_contents("$srcFoo/Skip.php", "<?php\n");

        $this->configurationFileBuilder
            ->setV3Flows([
                'qa' => [
                    'jobs' => [
                        [
                            'job'           => 'lint_foo',
                            'only-files'    => ["$tests/src/foo/**"],
                            'exclude-files' => ['**/Skip.php'],
                        ],
                    ],
                ],
            ])
            ->setV3Jobs([
                'lint_foo' => ['type' => 'custom', 'script' => 'true', 'paths' => [$srcFoo], 'accelerable' => true],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $cmd = sprintf(
            '%s flow qa --files=%s/Keep.php,%s/Skip.php --format=json --config=%s 2>/dev/null',
            $this->githooks,
            $srcFoo,
            $srcFoo,
            $this->configPath
        );

        passthru($cmd, $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $byName = $this->indexJobs($decoded['jobs']);
        $this->assertFalse(
            $byName['lint_foo']['skipped'],
            'lint_foo must run because Keep.php survives the exclude-files filter'
        );
    }

    /**
     * FEAT-1 — when only `exclude-files` is declared and every change-set file
     * matches it, the job skips with the literal reason emitted by
     * FlowPreparer::admissionSkipMessage() for the exclude-only branch.
     *
     * @test
     */
    public function phar_skips_job_when_exclude_files_drops_every_match(): void
    {
        $tests = self::TESTS_PATH;
        $srcFoo = "$tests/src/foo";
        @mkdir($srcFoo, 0777, true);
        file_put_contents("$srcFoo/Skip.php", "<?php\n");

        $this->configurationFileBuilder
            ->setV3Flows([
                'qa' => [
                    'jobs' => [
                        [
                            'job'           => 'lint_foo',
                            'exclude-files' => ['**/Skip.php'],
                        ],
                    ],
                ],
            ])
            ->setV3Jobs([
                'lint_foo' => ['type' => 'custom', 'script' => 'true', 'paths' => [$srcFoo], 'accelerable' => true],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $cmd = sprintf(
            '%s flow qa --files=%s/Skip.php --format=json --config=%s 2>/dev/null',
            $this->githooks,
            $srcFoo,
            $this->configPath
        );

        passthru($cmd, $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $byName = $this->indexJobs($decoded['jobs']);
        $this->assertTrue(
            $byName['lint_foo']['skipped'],
            'lint_foo must skip when every change-set file is filtered by exclude-files'
        );
        $this->assertSame(
            'every file in the change set is filtered by its exclude-files rule',
            $byName['lint_foo']['skipReason']
        );
    }

    // ─── FEAT-2 · branch-driven execution mode ───────────────────────

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

    // ─── FEAT-3 + BUG-19 · needs propagation ──────────────────────────

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
