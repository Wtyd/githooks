<?php

declare(strict_types=1);

namespace Tests\System\CiFeatures;

use Tests\Utils\TestCase\CiFeatureTestCase;

/**
 * End-to-end verification of the time-budget feature on real GHA runners
 * across Linux/Windows/macOS. Spec: spec-design-time-budget-thresholds.md.
 *
 * The tests invoke the real `php githooks` binary as a subprocess so the
 * job durations measured by FlowExecutor reflect actual sleep() calls
 * in child processes, not mocked timestamps.
 *
 * @group ci-features
 */
class TimeBudgetTest extends CiFeatureTestCase
{
    private string $sleepScript;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sleepScript = 'tests/Fixtures/scripts/sleep.php';
    }

    /** @test */
    public function job_threshold_warned_is_true_when_warn_after_is_crossed(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['slow_job']]])
            ->setV3Jobs([
                'slow_job' => [
                    'type'       => 'custom',
                    'script'     => "php $this->sleepScript 2",
                    'warn-after' => 1,
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --format=json --config=$this->configPath");

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);
        $job = $this->findJob($decoded, 'slow_job');

        $this->assertIsArray($job['threshold'] ?? null, 'threshold block missing');
        $this->assertTrue($job['threshold']['warned'], 'expected warned=true after 2s with warn-after=1');
        $this->assertFalse($job['threshold']['failed'], 'expected failed=false (no fail-after configured)');
    }

    /** @test */
    public function job_threshold_failed_is_true_and_exit_code_is_one_when_fail_after_is_crossed(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['slow_job']]])
            ->setV3Jobs([
                'slow_job' => [
                    'type'       => 'custom',
                    'script'     => "php $this->sleepScript 2",
                    'fail-after' => 1,
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --format=json --config=$this->configPath");

        $this->assertSame(1, $result['exitCode'], "expected exit 1 when fail-after crossed; stderr:\n{$result['stderr']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);
        $job = $this->findJob($decoded, 'slow_job');

        $this->assertIsArray($job['threshold'] ?? null);
        $this->assertTrue($job['threshold']['failed']);
    }

    /** @test */
    public function flow_time_budget_warned_is_true_when_total_duration_crosses_warn_after(): void
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions([
                'fail-fast'   => false,
                'processes'   => 1,
                'time-budget' => ['warn-after' => 1],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['j1', 'j2']]])
            ->setV3Jobs([
                'j1' => [
                    'type'   => 'custom',
                    'script' => "php $this->sleepScript 1",
                ],
                'j2' => [
                    'type'   => 'custom',
                    'script' => "php $this->sleepScript 1",
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --format=json --config=$this->configPath");

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertIsArray($decoded['timeBudget'] ?? null, 'timeBudget block missing at root');
        $this->assertTrue(
            $decoded['timeBudget']['warned'],
            'expected flow timeBudget.warned=true; totalJobDuration='
                . ($decoded['timeBudget']['totalJobDuration'] ?? 'n/a')
        );
        $this->assertFalse($decoded['timeBudget']['failed']);
    }

    /** @test */
    public function threshold_and_time_budget_are_null_when_not_configured(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['j1']]])
            ->setV3Jobs([
                'j1' => [
                    'type'   => 'custom',
                    'script' => "php $this->sleepScript 1",
                ],
            ]);
        $this->writeConfig();

        $result = $this->runGithooks("flow qa --format=json --config=$this->configPath");

        $this->assertSame(0, $result['exitCode'], "stderr:\n{$result['stderr']}");
        $decoded = $this->decodeJsonOutput($result['stdout']);

        $this->assertArrayHasKey('timeBudget', $decoded);
        $this->assertNull($decoded['timeBudget'], 'expected timeBudget=null when no time-budget configured');
        $job = $this->findJob($decoded, 'j1');
        $this->assertArrayHasKey('threshold', $job);
        $this->assertNull($job['threshold'], 'expected threshold=null when no warn/fail-after configured');
    }
}
