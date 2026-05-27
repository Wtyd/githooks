<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;

class FlowsCommandTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
    }

    /**
     * Build a fixture with three normal flows and a meta-flow ci-pack.
     */
    private function buildMultiFlowFixture(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-commit' => ['qa']])
            ->setV3Flows([
                'qa'      => ['jobs' => ['job_a', 'job_b']],
                'lint'    => ['jobs' => ['job_b', 'job_c']],
                'tests'   => ['jobs' => ['job_d']],
                'ci-pack' => [
                    'flows'   => ['qa', 'lint'],
                    'options' => ['processes' => 4, 'fail-fast' => true],
                ],
            ])
            ->setV3Jobs([
                'job_a' => ['type' => 'custom', 'script' => 'true'],
                'job_b' => ['type' => 'custom', 'script' => 'true'],
                'job_c' => ['type' => 'custom', 'script' => 'true'],
                'job_d' => ['type' => 'custom', 'script' => 'true'],
            ])
            ->buildInFileSystem();
    }

    /**
     * Fixture with a normal flow `qa` that declares an `on` map (branch-driven
     * execution mode) and a second normal flow `lint` without `on`.
     */
    private function buildBranchDrivenFixture(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-commit' => ['qa']])
            ->setV3Flows([
                'qa' => [
                    'on' => [
                        'master' => ['execution' => 'full'],
                        '*'      => ['execution' => 'fast-branch'],
                    ],
                    'jobs' => ['job_a', 'job_b'],
                ],
                'lint' => ['jobs' => ['job_b', 'job_c']],
            ])
            ->setV3Jobs([
                'job_a' => ['type' => 'custom', 'script' => 'true'],
                'job_b' => ['type' => 'custom', 'script' => 'true'],
                'job_c' => ['type' => 'custom', 'script' => 'true'],
            ])
            ->buildInFileSystem();
    }

    /** @test */
    public function single_flow_degenerate_runs_as_a_normal_flow()
    {
        $this->buildMultiFlowFixture();

        $this->artisan("flows qa --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['passed'];
    }

    /** @test */
    public function single_flow_degenerate_omits_flows_field_in_json()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows qa --format=json --config=$this->configPath");
        $this->assertSame('qa', $output['flow']);
        $this->assertArrayNotHasKey('flows', $output);
    }

    /**
     * Canonical equivalence guard for `flow X` ≡ `flows X`.
     *
     * Uses a *maximal* (kitchen-sink) fixture that lights up every flow-entry
     * attribute at once — exclude-files, only-files, `needs`, and the
     * needs↔admission interaction — and compares the *whole normalized
     * payload*, not a hand-picked subset of fields. This is what makes the
     * test future-proof: any attribute dropped while a carrier object crosses
     * the `prepareMultiple → FlowPlan → FlowsCommand` boundary shows up as a
     * diff, including an attribute that does not exist yet when this test was
     * written. The previous version used an attribute-free fixture and a
     * three-field comparison, so it stayed green while the entry-attrs were
     * silently lost (the bug this guard now closes).
     *
     * @test
     */
    public function single_flow_degenerate_matches_flow_command_output()
    {
        $dir = $this->buildKitchenSinkFixture();

        $flowOut  = $this->runFlowJson("flow qa --files=$dir/Touched.php --format=json --config=$this->configPath");
        $flowsOut = $this->runJson("flows qa --files=$dir/Touched.php --format=json --config=$this->configPath");

        // Top-level envelope (volatile totalTime excluded).
        foreach (['flow', 'success', 'executionMode', 'passed', 'failed', 'skipped'] as $key) {
            $this->assertSame($flowOut[$key], $flowsOut[$key], "envelope field `$key` must match across flow/flows");
        }
        $this->assertSame(
            $flowOut['effectiveOptions'],
            $flowsOut['effectiveOptions'],
            'effectiveOptions must be identical across `flow X` and `flows X`'
        );

        // Whole jobs payload, normalized (runtime-volatile fields stripped).
        // A dropped attribute (needs / skipped / skipReason / command / paths …)
        // surfaces here without the test having to anticipate which one.
        $this->assertSame(
            $this->normalizeJobs($flowOut['jobs']),
            $this->normalizeJobs($flowsOut['jobs']),
            'the full per-job payload must be identical across `flow X` and `flows X`'
        );

        $this->assertArrayNotHasKey('flows', $flowOut);
        $this->assertArrayNotHasKey('flows', $flowsOut);
    }

    /**
     * Maximal "kitchen-sink" fixture: a single normal flow `qa` whose entries
     * exercise every flow-entry attribute (FEAT-1 + FEAT-3) plus their
     * interaction. `--files` (created on disk) drives FAST mode so the
     * admission rules are evaluated.
     *
     *  - `excluded`   → exclude-files drops the only change-set file ⇒ skipped.
     *  - `only_miss`  → only-files matches nothing ⇒ skipped.
     *  - `dependent`  → needs `excluded`; its dependency is skipped ⇒ propagated.
     *  - `plain_runner` → no attrs ⇒ runs (the minimal common path inside the
     *    same fixture).
     *
     * @return string Absolute directory holding the change-set file.
     */
    private function buildKitchenSinkFixture(): string
    {
        $dir = getcwd() . '/' . self::TESTS_PATH . '/src/foo';
        @mkdir($dir, 0777, true);
        file_put_contents("$dir/Touched.php", "<?php\n");

        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-commit' => ['qa']])
            ->setV3Flows([
                'options' => ['processes' => 1, 'fail-fast' => false],
                'qa' => [
                    'jobs' => [
                        ['job' => 'excluded', 'exclude-files' => ['**/Touched.php']],
                        ['job' => 'only_miss', 'only-files' => ['**/Other.php']],
                        ['job' => 'dependent', 'needs' => ['excluded']],
                        'plain_runner',
                    ],
                ],
            ])
            ->setV3Jobs([
                'excluded'     => ['type' => 'custom', 'script' => 'echo excluded', 'paths' => [$dir], 'accelerable' => true],
                'only_miss'    => ['type' => 'custom', 'script' => 'echo only', 'paths' => [$dir], 'accelerable' => true],
                'dependent'    => ['type' => 'custom', 'script' => 'echo dep'],
                'plain_runner' => ['type' => 'custom', 'script' => 'echo run'],
            ])
            ->buildInFileSystem();

        return $dir;
    }

    /**
     * Normalize a JSON v2 `jobs` array for equivalence comparison: drop the
     * runtime-volatile fields and index by job name (order-independent).
     *
     * @param array<int, array<string, mixed>> $jobs
     * @return array<string, array<string, mixed>>
     */
    private function normalizeJobs(array $jobs): array
    {
        $volatile = ['time', 'duration', 'memoryPeak', 'memoryReserved'];
        $byName = [];
        foreach ($jobs as $job) {
            foreach ($volatile as $key) {
                unset($job[$key]);
            }
            $byName[$job['name']] = $job;
        }
        ksort($byName);
        return $byName;
    }

    /**
     * @return array<string, mixed>
     */
    private function runFlowJson(string $command): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'flowjson_');
        try {
            $this->artisan(trim("$command --output=$tmp"));
            $payload = (string) file_get_contents($tmp);
            $decoded = json_decode($payload, true);
            $this->assertIsArray($decoded, "Expected JSON for flow at $tmp, got:\n$payload");
            return $decoded;
        } finally {
            @unlink($tmp);
        }
    }

    /** @test */
    public function ad_hoc_mode_concatenates_flow_names_in_identifier()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows qa lint --format=json --config=$this->configPath");
        $this->assertSame('qa+lint', $output['flow']);
        $this->assertSame(['qa', 'lint'], $output['flows']);
    }

    /** @test */
    public function ad_hoc_mode_dedups_shared_jobs()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows qa lint --format=json --config=$this->configPath");

        $names = array_column($output['jobs'], 'name');
        $this->assertSame(['job_a', 'job_b', 'job_c'], $names);
    }

    /** @test */
    public function declarative_mode_expands_meta_flow()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows ci-pack --format=json --config=$this->configPath");

        $this->assertSame('ci-pack', $output['flow']);
        $this->assertSame(['qa', 'lint'], $output['flows']);
        $this->assertSame(['job_a', 'job_b', 'job_c'], array_column($output['jobs'], 'name'));
    }

    /** @test */
    public function declarative_mode_applies_meta_flow_options_in_trace()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows ci-pack --format=json --config=$this->configPath");

        $this->assertSame(4, $output['effectiveOptions']['processes']['value']);
        $this->assertSame('flows.ci-pack.options', $output['effectiveOptions']['processes']['source']);
        $this->assertTrue($output['effectiveOptions']['failFast']['value']);
        $this->assertSame('flows.ci-pack.options', $output['effectiveOptions']['failFast']['source']);
    }

    /** @test */
    public function mixed_mode_ignores_meta_flow_options_and_emits_warning()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows ci-pack tests --format=json --config=$this->configPath");

        $this->assertSame('ci-pack+tests', $output['flow']);
        $this->assertSame(['qa', 'lint', 'tests'], $output['flows']);
        // meta-flow options ignored: source for processes/failFast must NOT be flows.ci-pack.options
        $this->assertNotSame('flows.ci-pack.options', $output['effectiveOptions']['processes']['source']);
    }

    /** @test */
    public function aborts_when_a_flow_name_is_unknown()
    {
        $this->buildMultiFlowFixture();

        $this->artisan("flows qa nope --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ["Flow 'nope' is not defined", 'Available flows'];
    }

    /** @test */
    public function cli_processes_overrides_everything_in_trace()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows ci-pack --processes=12 --format=json --config=$this->configPath");

        $this->assertSame(12, $output['effectiveOptions']['processes']['value']);
        $this->assertSame('cli', $output['effectiveOptions']['processes']['source']);
    }

    /** @test */
    public function exclude_jobs_filters_the_merged_union()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows qa lint --exclude-jobs=job_b --format=json --config=$this->configPath");

        $names = array_column($output['jobs'], 'name');
        $this->assertSame(['job_a', 'job_c'], $names);
    }

    // ─── Branch-driven execution mode (`on`) in single-flow degenerate ───
    // Regression guard: `flows qa` must resolve `on` exactly like `flow qa`.

    /** @test T1: literal branch match resolves to its mode */
    public function single_flow_on_resolves_full_for_literal_branch_match()
    {
        $this->buildBranchDrivenFixture();

        $output = $this->runJson("flows qa --branch=master --format=json --config=$this->configPath");

        $this->assertSame('full', $output['executionMode']);
        $this->assertSame('flows.qa.on', $output['effectiveOptions']['executionMode']['source']);
    }

    /** @test T2: catch-all match resolves to its mode */
    public function single_flow_on_resolves_fast_branch_for_catch_all()
    {
        $this->buildBranchDrivenFixture();

        $output = $this->runJson("flows qa --branch=feature/x --format=json --config=$this->configPath");

        $this->assertSame('fast-branch', $output['executionMode']);
        $this->assertSame('flows.qa.on', $output['effectiveOptions']['executionMode']['source']);
    }

    /** @test T3: CLI mode flag precedes `on` */
    public function single_flow_cli_mode_flag_wins_over_on()
    {
        $this->buildBranchDrivenFixture();

        $output = $this->runJson("flows qa --branch=master --fast --format=json --config=$this->configPath");

        $this->assertSame('fast', $output['executionMode']);
        $this->assertSame('cli', $output['effectiveOptions']['executionMode']['source']);
    }

    /** @test T4: --branch is accepted and inert when the flow declares no `on` */
    public function single_flow_without_on_accepts_branch_flag()
    {
        $this->buildBranchDrivenFixture();

        $this->artisan("flows lint --branch=whatever --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['passed'];
    }

    /** @test T5: `flows qa` resolves the same mode as `flow qa` (the documented equivalence) */
    public function single_flow_on_matches_flow_command_execution_mode()
    {
        $this->buildBranchDrivenFixture();

        $flowOut  = $this->runFlowJson("flow qa --branch=feature/x --format=json --config=$this->configPath");
        $flowsOut = $this->runJson("flows qa --branch=feature/x --format=json --config=$this->configPath");

        $this->assertSame($flowOut['executionMode'], $flowsOut['executionMode']);
        $this->assertSame(
            $flowOut['effectiveOptions']['executionMode'],
            $flowsOut['effectiveOptions']['executionMode'],
            'executionMode resolution must be identical across `flow X` and `flows X` when the flow declares `on`'
        );
    }

    /** @test T6: multi-flow runs intentionally ignore per-flow `on` */
    public function multi_flow_ignores_per_flow_on()
    {
        $this->buildBranchDrivenFixture();

        $output = $this->runJson("flows qa lint --branch=feature/x --format=json --config=$this->configPath");

        $this->assertNotSame('flows.qa.on', $output['effectiveOptions']['executionMode']['source']);
    }

    // ─── FEAT-1 / FEAT-3 entry-attrs must propagate through `flows` ───
    // Regression guard: before the fix, `flows` flattened the merged jobs to
    // plain strings and lost needs / only-files / exclude-files + the
    // dependency graph. `flows X` must behave like `flow X` for these.

    /**
     * Fixture with a `qa` flow that chains jobs via `needs`. `upstream` fails,
     * so `downstream` (needs upstream) must be skipped while `independent`
     * (no needs) still runs.
     */
    private function buildNeedsFixture(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-commit' => ['qa']])
            ->setV3Flows([
                'options' => ['processes' => 1, 'fail-fast' => false],
                'qa' => [
                    'jobs' => [
                        'upstream',
                        ['job' => 'downstream', 'needs' => ['upstream']],
                        'independent',
                    ],
                ],
            ])
            ->setV3Jobs([
                'upstream'    => ['type' => 'custom', 'script' => 'exit 1'],
                'downstream'  => ['type' => 'custom', 'script' => 'echo never'],
                'independent' => ['type' => 'custom', 'script' => 'echo ok'],
            ])
            ->buildInFileSystem();
    }

    /** @test C1: `flows qa` propagates `needs` skip when the upstream fails */
    public function single_flow_needs_skips_dependent_when_upstream_fails()
    {
        $this->buildNeedsFixture();

        $output = $this->runJson("flows qa --format=json --config=$this->configPath");

        $jobs = $this->indexJobs($output['jobs']);
        $this->assertFalse($jobs['upstream']['success'], 'upstream is expected to fail');
        $this->assertTrue($jobs['downstream']['skipped'], 'downstream must be skipped — its `needs` failed');
        $this->assertSame('needs upstream failed', $jobs['downstream']['skipReason']);
        $this->assertSame(['upstream'], $jobs['downstream']['needs'], '`needs` field must be present in JSON v2');
        $this->assertFalse($jobs['independent']['skipped'], 'independent job has no needs and must run');
    }

    /** @test C1: the documented equivalence — `flows qa` ≡ `flow qa` for `needs` */
    public function single_flow_needs_matches_flow_command()
    {
        $this->buildNeedsFixture();

        $flowOut  = $this->runFlowJson("flow qa --format=json --config=$this->configPath");
        $flowsOut = $this->runJson("flows qa --format=json --config=$this->configPath");

        $flow  = $this->indexJobs($flowOut['jobs']);
        $flows = $this->indexJobs($flowsOut['jobs']);

        foreach (['upstream', 'downstream', 'independent'] as $name) {
            $this->assertSame(
                $flow[$name]['skipped'],
                $flows[$name]['skipped'],
                "`$name` skipped state must match across flow/flows"
            );
            $this->assertSame(
                $flow[$name]['skipReason'],
                $flows[$name]['skipReason'],
                "`$name` skipReason must match across flow/flows"
            );
            $this->assertSame(
                $flow[$name]['needs'] ?? null,
                $flows[$name]['needs'] ?? null,
                "`$name` needs field must match across flow/flows"
            );
        }
    }

    /**
     * Fixture for admission rules. `lint_foo` declares `exclude-files` that
     * drops every file in the change set, so the job must be skipped. Files
     * are created on disk because `--files` ignores non-existent paths.
     *
     * @return string Absolute directory holding the change-set files.
     */
    private function buildAdmissionFixture(): string
    {
        $dir = getcwd() . '/' . self::TESTS_PATH . '/src/foo';
        @mkdir($dir, 0777, true);
        file_put_contents("$dir/Skip.php", "<?php\n");

        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-commit' => ['qa']])
            ->setV3Flows([
                'qa' => [
                    'jobs' => [
                        ['job' => 'lint_foo', 'exclude-files' => ['**/Skip.php']],
                    ],
                ],
            ])
            ->setV3Jobs([
                'lint_foo' => ['type' => 'custom', 'script' => 'true', 'paths' => [$dir], 'accelerable' => true],
            ])
            ->buildInFileSystem();

        return $dir;
    }

    /** @test C2: `flows qa` skips a job by `exclude-files` exactly like `flow qa` */
    public function single_flow_exclude_files_skips_job_like_flow_command()
    {
        $dir = $this->buildAdmissionFixture();

        $flowOut  = $this->runFlowJson("flow qa --files=$dir/Skip.php --format=json --config=$this->configPath");
        $flowsOut = $this->runJson("flows qa --files=$dir/Skip.php --format=json --config=$this->configPath");

        $flow  = $this->indexJobs($flowOut['jobs']);
        $flows = $this->indexJobs($flowsOut['jobs']);

        $this->assertTrue($flow['lint_foo']['skipped'], 'flow must skip lint_foo (every file matches exclude-files)');
        $this->assertSame(
            $flow['lint_foo']['skipped'],
            $flows['lint_foo']['skipped'],
            '`flows` must skip by exclude-files exactly like `flow`'
        );
        $this->assertSame(
            $flow['lint_foo']['skipReason'],
            $flows['lint_foo']['skipReason'],
            'admission skipReason must match across flow/flows'
        );
    }

    /**
     * Index a JSON v2 `jobs` array by job name.
     *
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

    /**
     * Run a flows command with --format=json --output=tmpfile so we can decode the
     * structured payload from disk regardless of how Laravel-Zero buffers stdout.
     *
     * @return array<string, mixed>
     */
    private function runJson(string $command): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'flowsjson_');
        try {
            $this->artisan(trim("$command --output=$tmp"));
            $payload = (string) file_get_contents($tmp);
            $decoded = json_decode($payload, true);
            $this->assertIsArray($decoded, "Expected JSON output at $tmp, got:\n$payload");
            return $decoded;
        } finally {
            @unlink($tmp);
        }
    }
}
