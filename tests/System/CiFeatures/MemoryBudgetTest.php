<?php

declare(strict_types=1);

namespace Tests\System\CiFeatures;

use Tests\Utils\TestCase\CiFeatureTestCase;

/**
 * End-to-end verification of the memory-budget feature on real GHA
 * runners across Linux/Windows/macOS. Spec: spec-design-memory-budget.md.
 *
 * The tests invoke a child PHP process that allocates a controlled
 * amount of memory and holds the allocation for several seconds so
 * the RSS sampler picks up a stable peak.
 *
 * Linux and macOS have native samplers; Windows falls back to the
 * NullRssSampler (no Windows sampler shipped in v3.3) — the Windows
 * branch asserts gracefully degraded behaviour: stats.memory is absent
 * and no spurious warnings are emitted, but the rest of the JSON shape
 * remains valid.
 *
 * @group ci-features
 */
class MemoryBudgetTest extends CiFeatureTestCase
{
    private string $burnerScript;

    private bool $samplerExpected;

    protected function setUp(): void
    {
        parent::setUp();
        $this->burnerScript = 'tests/Fixtures/scripts/memory-burner.php';
        // Windows ships no native sampler in v3.3 — see MemorySamplerFactory.
        $this->samplerExpected = PHP_OS_FAMILY !== 'Windows';
    }

    /** @test */
    public function memory_budget_block_is_null_when_not_configured(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['j1']]])
            ->setV3Jobs([
                'j1' => [
                    'type'   => 'custom',
                    'script' => "php $this->burnerScript 16 1",
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --format=json --config=$this->configPath");

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertArrayHasKey('memoryBudget', $decoded);
        $this->assertNull($decoded['memoryBudget'], 'memoryBudget should be null when no flow-level memory-budget configured');

        $job = $this->findJob($decoded, 'j1');
        $this->assertArrayHasKey('memoryThreshold', $job);
        $this->assertNull($job['memoryThreshold'], 'per-job memoryThreshold should be null when not configured');
    }

    /** @test */
    public function stats_memory_flow_peak_is_emitted_when_sampler_is_active(): void
    {
        if (!$this->samplerExpected) {
            $this->markTestSkipped('Windows ships no RSS sampler in v3.3; covered by null-sampler test below.');
        }

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['burner']]])
            ->setV3Jobs([
                'burner' => [
                    'type'   => 'custom',
                    'script' => "php $this->burnerScript 64 2",
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --stats --no-memory-budget --format=json --config=$this->configPath");

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertArrayHasKey('stats', $decoded);
        $this->assertIsArray($decoded['stats']);
        $this->assertArrayHasKey('memory', $decoded['stats'], 'expected stats.memory present on Linux/macOS');
        $peak = $decoded['stats']['memory']['flowPeak']['value'] ?? 0;
        $this->assertGreaterThan(
            5,
            (int) $peak,
            "expected sampler to observe > 5 MB peak with a 64 MB allocator (got {$peak})"
        );
    }

    /** @test */
    public function windows_falls_back_to_null_sampler_without_spurious_warnings(): void
    {
        if ($this->samplerExpected) {
            $this->markTestSkipped('Linux/macOS have native samplers; this verifies the Windows degradation branch.');
        }

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['burner']]])
            ->setV3Jobs([
                'burner' => [
                    'type'   => 'custom',
                    'script' => "php $this->burnerScript 32 1",
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --stats --no-memory-budget --format=json --config=$this->configPath");

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        // stats.cores is always emitted; stats.memory is gated on sampler activity.
        $this->assertArrayHasKey('stats', $decoded);
        $this->assertIsArray($decoded['stats']);
        $this->assertArrayNotHasKey(
            'memory',
            $decoded['stats'],
            'expected stats.memory absent on Windows (NullRssSampler)'
        );
    }

    /** @test */
    public function memory_budget_warned_is_true_when_peak_exceeds_warn_above(): void
    {
        if (!$this->samplerExpected) {
            $this->markTestSkipped('Memory-budget gating requires an active sampler; Windows skipped.');
        }

        $this->configurationFileBuilder
            ->setV3GlobalOptions([
                'memory-budget' => ['warn-above' => 20],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['burner']]])
            ->setV3Jobs([
                'burner' => [
                    'type'   => 'custom',
                    'script' => "php $this->burnerScript 64 2",
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --format=json --config=$this->configPath");

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertIsArray($decoded['memoryBudget'] ?? null, 'memoryBudget block missing');
        $this->assertTrue(
            $decoded['memoryBudget']['warned'],
            'expected memoryBudget.warned=true with 64 MB allocator and warn-above=20; peak='
                . ($decoded['memoryBudget']['peakObserved'] ?? 'n/a')
        );
        $this->assertFalse($decoded['memoryBudget']['failed']);
    }

    /**
     * @test
     *
     * AC-002 (memory hard-fail): when the observed peak crosses the flow-level
     * `fail-above`, the runtime flips the flow to failed and exits 1 (killing
     * jobs in flight). Complements the warn-above test above.
     */
    public function memory_budget_failed_is_true_and_exit_code_is_one_when_peak_exceeds_fail_above(): void
    {
        if (!$this->samplerExpected) {
            $this->markTestSkipped('Memory-budget gating requires an active sampler; Windows skipped.');
        }

        $this->configurationFileBuilder
            ->setV3GlobalOptions([
                'memory-budget' => ['fail-above' => 20],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['burner']]])
            ->setV3Jobs([
                'burner' => [
                    'type'   => 'custom',
                    'script' => "php $this->burnerScript 64 3",
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --format=json --config=$this->configPath");

        $this->assertSame(1, $result['exitCode'], "expected exit 1 when fail-above crossed; stderr:\n{$result['stderr']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertIsArray($decoded['memoryBudget'] ?? null, 'memoryBudget block missing');
        $this->assertTrue(
            $decoded['memoryBudget']['failed'],
            'expected memoryBudget.failed=true with 64 MB allocator and fail-above=20; peak='
                . ($decoded['memoryBudget']['peakObserved'] ?? 'n/a')
        );
        $this->assertFalse($decoded['success'], 'flow success must be false when memory-budget fails');
    }

    /** @test */
    public function per_job_memory_threshold_warned_is_true_when_job_peak_exceeds_warn_above(): void
    {
        if (!$this->samplerExpected) {
            $this->markTestSkipped('Per-job memory threshold requires an active sampler; Windows skipped.');
        }

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['burner']]])
            ->setV3Jobs([
                'burner' => [
                    'type'   => 'custom',
                    'script' => "php $this->burnerScript 64 2",
                    'memory' => ['warn-above' => 20],
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --format=json --config=$this->configPath");

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $job = $this->findJob($decoded, 'burner');
        $this->assertIsArray($job['memoryThreshold'] ?? null, 'memoryThreshold block missing');
        $this->assertTrue(
            $job['memoryThreshold']['warned'],
            'expected per-job memoryThreshold.warned=true with 64 MB allocator and warn-above=20'
        );
        $this->assertFalse($job['memoryThreshold']['failed']);
    }
}
