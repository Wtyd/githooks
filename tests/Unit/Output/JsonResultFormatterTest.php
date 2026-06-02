<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\Deprecation;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\Diagnostics;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolution;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\RuntimeBlock;
use Wtyd\GitHooks\Output\JsonResultFormatter;

class JsonResultFormatterTest extends UnitTestCase
{
    /** @test */
    function it_formats_a_successful_flow_as_json()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1.23s'),
            new JobResult('phpcs_all', true, '', '500ms'),
        ], '1.73s');

        $formatter = new JsonResultFormatter();
        $json = $formatter->format($result);
        $data = json_decode($json, true);

        $this->assertSame(2, $data['version']);
        $this->assertSame('qa', $data['flow']);
        $this->assertTrue($data['success']);
        $this->assertSame('full', $data['executionMode']);
        $this->assertSame(2, $data['passed']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame(0, $data['skipped']);
        $this->assertCount(2, $data['jobs']);
        $this->assertSame('phpstan_src', $data['jobs'][0]['name']);
        $this->assertTrue($data['jobs'][0]['success']);
    }

    /** @test */
    function it_formats_a_failed_flow_with_output()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1.23s'),
            new JobResult('phpmd_src', false, 'VIOLATION in Foo.php', '500ms'),
        ], '1.73s');

        $formatter = new JsonResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertFalse($data['success']);
        $this->assertSame(1, $data['passed']);
        $this->assertSame(1, $data['failed']);
        $this->assertFalse($data['jobs'][1]['success']);
        $this->assertSame('VIOLATION in Foo.php', $data['jobs'][1]['output']);
    }

    /** @test */
    function it_strips_ansi_escape_sequences_from_output()
    {
        $ansiOutput = "\e[1G\e[2K 5/5 [\e[32m▓▓▓▓▓\e[0m] 100%\r\nSome error";

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, $ansiOutput, '1s'),
        ], '1s');

        $formatter = new JsonResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $output = $data['jobs'][0]['output'];
        $this->assertStringNotContainsString("\e[", $output);
        $this->assertStringNotContainsString("\r", $output);
        $this->assertStringContainsString('Some error', $output);
    }

    /** @test */
    function it_produces_valid_json()
    {
        $result = new FlowResult('qa', [
            new JobResult('test', true, "line with \"quotes\" and\nnewlines", '100ms'),
        ], '100ms');

        $formatter = new JsonResultFormatter();
        $json = $formatter->format($result);

        $this->assertNotNull(json_decode($json), 'Output must be valid JSON');
    }

    /** @test */
    function it_includes_v2_fields_per_job()
    {
        $result = new FlowResult('qa', [
            new JobResult(
                'phpstan_src',
                true,
                '',
                '1.23s',
                false,
                'vendor/bin/phpstan analyse src',
                'phpstan',
                0,
                ['src']
            ),
        ], '1.23s', 0, 0, 'fast');

        $formatter = new JsonResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertSame(2, $data['version']);
        $this->assertSame('fast', $data['executionMode']);

        $job = $data['jobs'][0];
        $this->assertSame('phpstan', $job['type']);
        $this->assertSame(0, $job['exitCode']);
        $this->assertSame(['src'], $job['paths']);
        $this->assertSame('vendor/bin/phpstan analyse src', $job['command']);
        $this->assertFalse($job['skipped']);
        $this->assertNull($job['skipReason']);
    }

    /** @test */
    function it_includes_skipped_jobs_in_json()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
            JobResult::skipped('phpcs_src', 'phpcs', 'no staged files match its paths', ['src']),
        ], '1s');

        $formatter = new JsonResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertSame(1, $data['passed']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame(1, $data['skipped']);

        $skippedJob = $data['jobs'][1];
        $this->assertSame('phpcs_src', $skippedJob['name']);
        $this->assertSame('phpcs', $skippedJob['type']);
        $this->assertTrue($skippedJob['skipped']);
        $this->assertSame('no staged files match its paths', $skippedJob['skipReason']);
        $this->assertSame(['src'], $skippedJob['paths']);
    }

    /** @test */
    function it_always_includes_command_field()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
        ], '1s');

        $formatter = new JsonResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertArrayHasKey('command', $data['jobs'][0]);
    }

    /** @test */
    function flows_field_is_omitted_when_no_expanded_flows()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
        ], '1s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertArrayNotHasKey('flows', $data);
    }

    /** @test */
    function flows_field_lists_expanded_normal_flows_in_multi_flow_run()
    {
        $result = new FlowResult(
            'qa+lint',
            [new JobResult('phpcs_src', true, '', '1s')],
            '1s',
            0,
            0,
            'full',
            null,
            ['qa', 'lint']
        );

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertSame('qa+lint', $data['flow']);
        $this->assertSame(['qa', 'lint'], $data['flows']);
    }

    /** @test */
    function effective_options_block_is_emitted_when_present()
    {
        $resolution = new EffectiveOptionsResolution(
            new OptionsConfiguration(),
            ExecutionMode::FULL,
            [
                'processes'     => ['value' => 4, 'source' => 'cli'],
                'failFast'      => ['value' => true, 'source' => 'flows.ci-pack.options'],
                'executionMode' => ['value' => 'full', 'source' => 'default'],
            ]
        );

        $result = new FlowResult(
            'ci-pack',
            [new JobResult('phpcs_src', true, '', '1s')],
            '1s',
            0,
            0,
            'full',
            null,
            ['qa', 'lint'],
            $resolution
        );

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertArrayHasKey('effectiveOptions', $data);
        $this->assertSame(4, $data['effectiveOptions']['processes']['value']);
        $this->assertSame('cli', $data['effectiveOptions']['processes']['source']);
        $this->assertSame('flows.ci-pack.options', $data['effectiveOptions']['failFast']['source']);
        $this->assertSame('default', $data['effectiveOptions']['executionMode']['source']);
    }

    /** @test */
    function effective_options_block_is_absent_when_resolution_not_provided()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
        ], '1s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertArrayNotHasKey('effectiveOptions', $data);
    }

    /** @test */
    function time_budget_root_field_is_null_when_state_absent(): void
    {
        $result = new FlowResult('qa', [new JobResult('a', true, '', '1s')], '1s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertArrayHasKey('timeBudget', $data);
        $this->assertNull($data['timeBudget']);
    }

    /** @test */
    function time_budget_root_field_is_object_when_state_present(): void
    {
        $state = new \Wtyd\GitHooks\Execution\TimeBudgetState(120, 300, 125.4, true, false);
        $result = new FlowResult('qa', [new JobResult('a', true, '', '125s')], '125s', 0, 0, 'full', null, null, null, $state);

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertSame([
            'warnAfter' => 120,
            'failAfter' => 300,
            'totalJobDuration' => 125.4,
            'warned' => true,
            'failed' => false,
        ], $data['timeBudget']);
    }

    /** @test */
    function per_job_threshold_field_is_null_when_unconfigured(): void
    {
        $result = new FlowResult('qa', [new JobResult('a', true, '', '1s')], '1s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertArrayHasKey('threshold', $data['jobs'][0]);
        $this->assertNull($data['jobs'][0]['threshold']);
    }

    /** @test */
    function per_job_threshold_field_is_object_when_configured(): void
    {
        $jobResult = new JobResult(
            'phpunit',
            true,
            '',
            '95s',
            false,
            null,
            'phpunit',
            0,
            [],
            false,
            null,
            null,
            null,
            95.4,
            JobResult::THRESHOLD_WARNED,
            JobResult::THRESHOLD_REASON_WARN,
            60,
            180
        );
        $result = new FlowResult('qa', [$jobResult], '95s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertSame([
            'warnAfter' => 60,
            'failAfter' => 180,
            'warned' => true,
            'failed' => false,
            'reason' => 'exceeded warn-after',
        ], $data['jobs'][0]['threshold']);
    }

    /** @test */
    function threshold_warnAfter_is_null_when_only_failAfter_configured(): void
    {
        $jobResult = new JobResult(
            'phpcs',
            false,
            '',
            '8s',
            false,
            null,
            'phpcs',
            0,
            [],
            false,
            null,
            null,
            null,
            8.2,
            JobResult::THRESHOLD_FAILED,
            JobResult::THRESHOLD_REASON_FAIL,
            null,
            5
        );
        $result = new FlowResult('qa', [$jobResult], '8s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertNull($data['jobs'][0]['threshold']['warnAfter']);
        $this->assertSame(5, $data['jobs'][0]['threshold']['failAfter']);
    }

    /** @test */
    function job_duration_seconds_is_emitted_in_top_level_field(): void
    {
        $jobResult = new JobResult(
            'phpunit',
            true,
            '',
            '95.4s',
            false,
            null,
            'phpunit',
            0,
            [],
            false,
            null,
            null,
            null,
            95.4
        );
        $result = new FlowResult('qa', [$jobResult], '95s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertSame(95.4, $data['jobs'][0]['duration']);
    }

    /** @test */
    function memory_blocks_are_null_when_no_budget_or_stats(): void
    {
        $result = new FlowResult('qa', [
            new JobResult('phpcs', true, '', '1s'),
        ], '1s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertArrayHasKey('memoryBudget', $data);
        $this->assertNull($data['memoryBudget']);
        $this->assertArrayHasKey('stats', $data);
        $this->assertNull($data['stats']);
        $this->assertNull($data['jobs'][0]['memoryReserved']);
        $this->assertNull($data['jobs'][0]['memoryPeak']);
        $this->assertNull($data['jobs'][0]['memoryThreshold']);
        $this->assertNull($data['jobs'][0]['killedReason']);
    }

    /** @test */
    function memory_budget_block_serializes_state_when_attached(): void
    {
        $state = new \Wtyd\GitHooks\Execution\MemoryBudgetState(
            3500,
            3900,
            3460,
            8.2,
            ['phpstan' => 1825, 'phpunit' => 1240],
            false,
            false
        );
        $result = new FlowResult('qa', [
            new JobResult('phpcs', true, '', '1s'),
        ], '1s');
        $result->setMemoryBudgetState($state);

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertSame(3500, $data['memoryBudget']['warnAbove']);
        $this->assertSame(3900, $data['memoryBudget']['failAbove']);
        $this->assertSame(3460, $data['memoryBudget']['peakObserved']);
        $this->assertSame(8.2, $data['memoryBudget']['peakAtSecond']);
        $this->assertSame(
            [
                ['name' => 'phpstan', 'value' => 1825],
                ['name' => 'phpunit', 'value' => 1240],
            ],
            $data['memoryBudget']['peakAttribution']
        );
        $this->assertFalse($data['memoryBudget']['warned']);
        $this->assertFalse($data['memoryBudget']['failed']);
    }

    /** @test */
    function stats_block_emits_cores_always_and_memory_only_when_sampler_active(): void
    {
        $stats = new \Wtyd\GitHooks\Execution\Memory\MemoryStats(
            true,
            3460,
            8.2,
            ['phpstan' => 1825, 'phpunit' => 1240],
            ['phpstan' => 1825, 'phpunit' => 1240, 'phpcs' => 245],
            10,
            8,
            4.5,
            ['phpstan', 'phpunit', 'phpcs', 'phpmd-src']
        );
        $result = new FlowResult('qa', [
            new JobResult('phpcs', true, '', '1s'),
        ], '1s');
        $result->setMemoryStats($stats);

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertSame(10, $data['stats']['cores']['limit']);
        $this->assertSame(8, $data['stats']['cores']['flowPeak']['value']);
        $this->assertSame(4.5, $data['stats']['cores']['flowPeak']['atSecond']);
        $this->assertSame(['phpstan', 'phpunit', 'phpcs', 'phpmd-src'], $data['stats']['cores']['flowPeak']['jobsInFlight']);

        $this->assertSame(3460, $data['stats']['memory']['flowPeak']['value']);
        $this->assertSame(8.2, $data['stats']['memory']['flowPeak']['atSecond']);
        $this->assertSame(
            [
                ['name' => 'phpstan', 'value' => 1825],
                ['name' => 'phpunit', 'value' => 1240],
            ],
            $data['stats']['memory']['flowPeak']['jobsInFlight']
        );
    }

    /** @test */
    function stats_block_omits_memory_subblock_when_sampler_inactive(): void
    {
        $stats = new \Wtyd\GitHooks\Execution\Memory\MemoryStats(
            false,
            0,
            0.0,
            [],
            [],
            6,
            3,
            1.0,
            ['a', 'b', 'c']
        );
        $result = new FlowResult('qa', [
            new JobResult('phpcs', true, '', '1s'),
        ], '1s');
        $result->setMemoryStats($stats);

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertArrayHasKey('cores', $data['stats']);
        $this->assertArrayNotHasKey('memory', $data['stats']);
    }

    /** @test */
    function per_job_memory_threshold_is_emitted_under_explicit_null_pattern(): void
    {
        $job = (new JobResult('phpunit', true, '', '4.1s'))
            ->withMemoryPeak(1240)
            ->withMemoryReserved(2000)
            ->withMemoryThreshold(JobResult::MEMORY_THRESHOLD_WARNED, JobResult::MEMORY_REASON_WARN, 1500, 2000);

        $result = new FlowResult('qa', [$job], '4.1s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertSame(1240, $data['jobs'][0]['memoryPeak']);
        $this->assertSame(2000, $data['jobs'][0]['memoryReserved']);
        $this->assertSame(1500, $data['jobs'][0]['memoryThreshold']['warnAbove']);
        $this->assertSame(2000, $data['jobs'][0]['memoryThreshold']['failAbove']);
        $this->assertTrue($data['jobs'][0]['memoryThreshold']['warned']);
        $this->assertFalse($data['jobs'][0]['memoryThreshold']['failed']);
        $this->assertSame('exceeded warn-above', $data['jobs'][0]['memoryThreshold']['reason']);
    }

    /** @test */
    function killed_reason_is_emitted_when_job_was_terminated_by_budget(): void
    {
        $job = (new JobResult('phpunit', true, '', '2.5s'))
            ->withKilled('flow memory-budget exceeded');

        $result = new FlowResult('qa', [$job], '2.5s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertFalse($data['jobs'][0]['success']);
        $this->assertSame('flow memory-budget exceeded', $data['jobs'][0]['killedReason']);
    }

    /** @test */
    function warnings_and_deprecations_are_always_present_as_arrays(): void
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
        ], '1s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertArrayHasKey('warnings', $data);
        $this->assertArrayHasKey('deprecations', $data);
        $this->assertSame([], $data['warnings']);
        $this->assertSame([], $data['deprecations']);
    }

    /** @test */
    function deprecations_block_serializes_records_when_validation_attached(): void
    {
        $validation = new ValidationResult();
        $validation->addDeprecation(new Deprecation('phpstan-src', 'executablePath', 'executable-path'));
        $validation->addDeprecation(new Deprecation('phpcs', 'failFast', 'fail-fast'));
        $validation->addWarning('Some non-deprecation parsing warning.');

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
        ], '1s');
        $result->setConfigValidation($validation);

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertCount(2, $data['deprecations']);
        $this->assertSame('phpstan-src', $data['deprecations'][0]['job']);
        $this->assertSame('executablePath', $data['deprecations'][0]['oldKey']);
        $this->assertSame('executable-path', $data['deprecations'][0]['newKey']);
        $this->assertSame('4.0', $data['deprecations'][0]['removalVersion']);
        $this->assertSame('config-key-rename', $data['deprecations'][0]['kind']);

        $this->assertCount(3, $data['warnings']);
        $this->assertContains(
            "Deprecated: 'executablePath' is renamed to 'executable-path'. Will be removed in v4.0.",
            $data['warnings']
        );
        $this->assertContains('Some non-deprecation parsing warning.', $data['warnings']);
    }

    /** @test */
    function warnings_block_filters_skipped_lines(): void
    {
        $validation = new ValidationResult();
        $validation->addWarning('Job foo skipped because no staged files match.');
        $validation->addWarning('Real parsing warning.');

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
        ], '1s');
        $result->setConfigValidation($validation);

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertSame(['Real parsing warning.'], $data['warnings']);
    }

    /**
     * @test
     * Kills JsonResultFormatter:84 UnwrapArrayValues (`array_values($jobs)` →
     * `$jobs`). When `getJobResults()` returns an array with non-contiguous
     * keys (e.g. tail jobs filtered out earlier), `array_map` preserves those
     * keys and `json_encode` would emit `"jobs": {"5": {...}}` (object),
     * breaking every JSON consumer that expects a plain list. Existing tests
     * use densely-packed arrays where the mutation is invisible because PHP's
     * `[0=>x, 1=>y]` already encodes as a list.
     */
    function jobs_block_is_a_plain_list_even_when_underlying_array_has_gaps(): void
    {
        $job0 = new JobResult('phpstan_src', true, '', '1s');
        $job1 = new JobResult('phpcs_all', true, '', '500ms');
        // FlowResult stores the array verbatim; non-contiguous keys are what
        // caller code can produce after array_filter / unset operations.
        $jobsWithGaps = [5 => $job0, 7 => $job1];

        $result = new FlowResult('qa', $jobsWithGaps, '1.5s');
        $json = (new JsonResultFormatter())->format($result);
        $data = json_decode($json, true);

        $this->assertSame(
            [0, 1],
            array_keys($data['jobs']),
            "data['jobs'] must be a plain list with sequential keys, not an object with the original gapped indices"
        );
        $this->assertSame('phpstan_src', $data['jobs'][0]['name']);
        $this->assertSame('phpcs_all', $data['jobs'][1]['name']);
    }

    /**
     * @test
     * I1 — when the job entry declares `needs`, JSON v2 surfaces them.
     */
    function emits_needs_when_declared(): void
    {
        $job = (new JobResult('eslint', true, '', '500ms'))
            ->withNeeds(['yarn-install']);

        $json = (new JsonResultFormatter())->format(new FlowResult('qa', [$job], '500ms'));
        $data = json_decode($json, true);

        $this->assertSame(['yarn-install'], $data['jobs'][0]['needs']);
    }

    /**
     * @test
     * I2 — entries without `needs` declared omit the field entirely (no `[]`).
     */
    function omits_needs_when_empty(): void
    {
        $job = new JobResult('phpstan', true, '', '1s');

        $json = (new JsonResultFormatter())->format(new FlowResult('qa', [$job], '1s'));
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey('needs', $data['jobs'][0]);
    }

    /**
     * @test
     * I3 — multiple needs preserve declaration order.
     */
    function emits_multiple_needs_in_order(): void
    {
        $job = (new JobResult('app', true, '', '2s'))
            ->withNeeds(['compile', 'lint']);

        $json = (new JsonResultFormatter())->format(new FlowResult('qa', [$job], '2s'));
        $data = json_decode($json, true);

        $this->assertSame(['compile', 'lint'], $data['jobs'][0]['needs']);
    }

    /**
     * @test
     * I4 — when a propagation skip happens, `needs` coexists with `skipReason`.
     */
    function emits_needs_and_skipReason_for_propagated_skips(): void
    {
        $job = JobResult::skipped('eslint', 'parallel-lint', 'needs yarn-install failed', [])
            ->withNeeds(['yarn-install']);

        $json = (new JsonResultFormatter())->format(new FlowResult('qa', [$job], '0s'));
        $data = json_decode($json, true);

        $this->assertSame(['yarn-install'], $data['jobs'][0]['needs']);
        $this->assertTrue($data['jobs'][0]['skipped']);
        $this->assertSame('needs yarn-install failed', $data['jobs'][0]['skipReason']);
    }

    /** @test FEAT-14: the `runtime` node + per-job startedAt/endedAt are present and well-formed. */
    function it_emits_the_runtime_node_and_per_job_timestamps(): void
    {
        $job = new JobResult(
            'phpstan_src',
            true,
            '',
            '1.23s',
            false,
            null,
            'phpstan',
            0,
            [],
            false,
            null,
            null,
            null,
            1.23,
            JobResult::THRESHOLD_NONE,
            null,
            null,
            null,
            '2026-05-13T14:23:09.123+00:00',
            '2026-05-13T14:23:10.353+00:00'
        );
        $result = new FlowResult('qa', [$job], '1.23s');
        $diagnostics = new Diagnostics('3.5.0', 'linux', 'gitlab-ci', 32, null, 1240, 65536, 28.5, 24.1, 21.0);
        $result->setRuntime(new RuntimeBlock($diagnostics, '2026-05-13T14:23:08+00:00', '2026-05-13T14:24:20+00:00'));

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        // Per-job absolute timestamps alongside the existing relative ones.
        $this->assertSame('2026-05-13T14:23:09.123+00:00', $data['jobs'][0]['startedAt']);
        $this->assertSame('2026-05-13T14:23:10.353+00:00', $data['jobs'][0]['endedAt']);
        $this->assertSame('1.23s', $data['jobs'][0]['time']);     // AC-005: existing fields unchanged
        $this->assertSame(1.23, $data['jobs'][0]['duration']);

        // Root runtime node (AC-002). assertEquals (not Same): JSON numbers do not
        // preserve the int/float distinction (21.0 round-trips as 21), same as the
        // existing `duration` field.
        $this->assertEquals([
            'githooksVersion' => '3.5.0',
            'platform'        => 'linux',
            'ci'              => 'gitlab-ci',
            'startedAt'       => '2026-05-13T14:23:08+00:00',
            'endedAt'         => '2026-05-13T14:24:20+00:00',
            'cpu'             => ['detected' => 32, 'cgroupLimit' => null],
            'memory'          => ['availableMb' => 1240, 'totalMb' => 65536],
            'load'            => ['avg1' => 28.5, 'avg5' => 24.1, 'avg15' => 21.0],
        ], $data['runtime']);
    }

    /** @test FEAT-14: unavailable platform fields serialise as null without breaking (AC-004). */
    function it_nulls_unavailable_runtime_fields(): void
    {
        $result = new FlowResult('qa', [new JobResult('phpstan_src', true, '', '1s')], '1s');
        $windows = new Diagnostics('3.5.0', 'windows', null, 4, null, null, null, null, null, null);
        $result->setRuntime(new RuntimeBlock($windows, '2026-05-13T14:23:08+00:00', '2026-05-13T14:23:09+00:00'));

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertNull($data['runtime']['ci']);
        $this->assertNull($data['runtime']['memory']['availableMb']);
        $this->assertNull($data['runtime']['load']['avg1']);
        // A skipped/never-run job (no timestamps) emits null, not a missing key.
        $this->assertNull($data['jobs'][0]['startedAt']);
        $this->assertNull($data['jobs'][0]['endedAt']);
    }

    /** @test The `runtime` key is always present (null when the runner did not set it). */
    function runtime_key_is_always_present(): void
    {
        $result = new FlowResult('qa', [new JobResult('x', true, '', '1s')], '1s');

        $data = json_decode((new JsonResultFormatter())->format($result), true);

        $this->assertArrayHasKey('runtime', $data);
        $this->assertNull($data['runtime']);
    }
}
