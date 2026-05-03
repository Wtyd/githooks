<?php

declare(strict_types=1);

namespace Tests\System\CiFeatures;

use Tests\Utils\TestCase\CiFeatureTestCase;

/**
 * End-to-end regression tests for the two memory-budget bugs detected during
 * v3.3 RC QA and closed in commits b9f5ff8 (BUG-001) and d614e4c (BUG-002).
 *
 * Sits in the CiFeatures group so the binary is invoked from the source
 * checkout (no need for a freshly-built .phar) — the same path the matrix
 * CI cell uses for memory-budget feature tests. The unit and integration
 * tests on FlowExecutor / FlowMemoryHandler already cover the contracts at
 * the coordinator level; these tests are the last-line safety net against
 * a refactor that bypasses the unit tests yet ships a broken end-to-end
 * behaviour to users.
 *
 * @group ci-features
 */
class MemoryBudgetRegressionTest extends CiFeatureTestCase
{
    private string $burnerScript;

    protected function setUp(): void
    {
        parent::setUp();
        $this->burnerScript = 'tests/Fixtures/scripts/memory-burner.php';
    }

    /**
     * Regression for BUG-001 (closed in b9f5ff8 — fix(memory): propagate per-job
     * fail-above to job.success).
     *
     * Symptom before the fix: a job whose observed peak crossed its declared
     * `memory.fail-above` produced `threshold.failed: true` on the JobResult,
     * but `job.success` stayed true, `flow.success` stayed true, and the exit
     * code was 0. The contract said a job that crossed fail-above must fail
     * the flow (mirror of the time-budget per-job `fail-after` contract).
     *
     * Setup: a 64 MB allocator (memory-burner.php) with warn-above=10 and
     * fail-above=20 — the observed peak (~64 MB) crosses both.
     *
     * @test
     */
    public function bug001_per_job_fail_above_propagates_to_exit_code(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Per-job memory threshold requires an active sampler; Windows skipped.');
        }

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['mem_heavy']]])
            ->setV3Jobs([
                'mem_heavy' => [
                    'type'   => 'custom',
                    'script' => "php $this->burnerScript 64 2",
                    'memory' => ['warn-above' => 10, 'fail-above' => 20],
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --format=json --config=$this->configPath");

        $this->assertNotSame(
            0,
            $result['exitCode'],
            "BUG-001 regression: job that crossed fail-above must produce a non-zero exit code. stderr:\n{$result['stderr']}"
        );

        $decoded = $this->decodeJsonOutput($result['stdout']);
        $this->assertFalse(
            $decoded['success'],
            'BUG-001 regression: flow.success must be false when a per-job fail-above triggers'
        );

        $job = $this->findJob($decoded, 'mem_heavy');
        $this->assertFalse(
            $job['success'],
            'BUG-001 regression: job.success must be false when peak crossed fail-above (was true before b9f5ff8)'
        );
        $this->assertTrue(
            $job['memoryThreshold']['failed'] ?? false,
            'memoryThreshold.failed must be true so the threshold reason is observable in the structured output'
        );
    }

    /**
     * Regression for BUG-002 (closed in d614e4c — fix(memory): clamp per-job
     * memory reserve to bin-packing reference in admission).
     *
     * Symptom before the fix: invoking `flow qa --memory-warn-above=N` with
     * N < max(jobs.memory) caused the executeParallel loop to spin at 100% CPU
     * forever. The bin-packing reference (warn-above preferred, fail-above
     * otherwise) was used as a hard admission ceiling, so a single job whose
     * declared `memory:` exceeded that ceiling never satisfied
     * AdmissionContext::fits() and FifoAdmission span the loop indefinitely.
     *
     * Wrapping the binary in `timeout 30` is critical: without it, a regression
     * of this fix would hang the test runner for PHPUnit's full wall-clock
     * budget. With it, an unfinished binary surfaces as exit code 124 — the
     * sentinel we assert against.
     *
     * @test
     */
    public function bug002_memory_warn_above_below_reserve_does_not_deadlock(): void
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['fail-fast' => false, 'processes' => 1])
            ->setV3Flows(['qa' => ['jobs' => ['heavy_reservation']]])
            ->setV3Jobs([
                'heavy_reservation' => [
                    'type'   => 'custom',
                    'script' => 'true',
                    'memory' => 300,
                ],
            ]);
        $this->writeConfig();

        $entrypoint = $this->projectRoot . DIRECTORY_SEPARATOR . 'githooks';
        $cmd = sprintf(
            'timeout 30 %s %s flow qa --memory-warn-above=200 --format=json --config=%s 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($entrypoint),
            escapeshellarg($this->configPath)
        );

        $start = microtime(true);
        $output = shell_exec($cmd . '; echo "EXIT_CODE=$?"');
        $elapsed = microtime(true) - $start;

        // shell_exec returns the combined stdout (with our marker on the last line).
        if (!is_string($output) || preg_match('/EXIT_CODE=(\d+)/', $output, $m) !== 1) {
            $this->fail('Could not extract exit code from binary output: ' . var_export($output, true));
        }
        $exitCode = (int) $m[1];
        $jsonOutput = preg_replace('/EXIT_CODE=\d+\s*$/', '', $output);

        $this->assertNotSame(
            124,
            $exitCode,
            'BUG-002 regression: the executeParallel loop deadlocked — `timeout 30` killed the binary at the 30s sentinel'
        );
        $this->assertLessThan(
            10.0,
            $elapsed,
            'BUG-002 regression: flow took ' . number_format($elapsed, 2) . 's. '
                . 'After the fix the binary terminates in <2s; >10s indicates the clamp is missing or partially regressed'
        );

        $decoded = json_decode($jsonOutput, true);
        $this->assertNotNull($decoded, 'Output is not valid JSON: ' . $jsonOutput);
        $this->assertSame('qa', $decoded['flow']);
    }
}
