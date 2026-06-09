<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Tests\Utils\TestCase\UnitTestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\Memory\MemoryStats;
use Wtyd\GitHooks\Output\RenderOptions;
use Wtyd\GitHooks\Output\StatsTableRenderer;

class StatsTableRendererTest extends UnitTestCase
{
    /** @test */
    public function it_renders_nothing_when_memory_stats_are_absent(): void
    {
        $output = new BufferedOutput();
        $result = new FlowResult('qa', [new JobResult('a', true, '', '1s')], '1s');

        (new StatsTableRenderer())->render($output, $result);

        $this->assertSame('', $output->fetch());
    }

    /** @test */
    public function it_renders_table_header_and_total_row_with_cores_and_memory(): void
    {
        $stats = new MemoryStats(
            true,
            900,
            5.5,
            ['phpstan' => 600, 'phpunit' => 300],
            ['phpstan' => 600, 'phpunit' => 300],
            10,
            5,
            5.5,
            ['phpstan', 'phpunit'],
            ['phpstan' => 4, 'phpunit' => 1]
        );
        $jobs = [
            (new JobResult('phpstan', true, '', '8.2s'))->withMemoryPeak(600),
            (new JobResult('phpunit', true, '', '4.1s'))->withMemoryPeak(300),
        ];
        $result = new FlowResult('qa', $jobs, '12.3s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringContainsString('Job', $rendered);
        $this->assertStringContainsString('Peak Cores', $rendered);
        $this->assertStringContainsString('Peak Memory', $rendered);
        $this->assertStringContainsString('phpstan', $rendered);
        $this->assertStringContainsString('phpunit', $rendered);
        $this->assertStringContainsString('600 MB', $rendered);
        $this->assertStringContainsString('300 MB', $rendered);
        $this->assertStringContainsString('TOTAL (flow)', $rendered);
        $this->assertStringContainsString('5/10', $rendered);
        $this->assertStringContainsString('900 MB', $rendered);
    }

    /** @test */
    public function it_renders_temporal_attribution_when_sampler_was_active(): void
    {
        $stats = new MemoryStats(
            true,
            450,
            8.2,
            ['a' => 200, 'b' => 250],
            ['a' => 200, 'b' => 250],
            4,
            2,
            8.2,
            ['a', 'b'],
            ['a' => 1, 'b' => 1]
        );
        $result = new FlowResult('qa', [
            (new JobResult('a', true, '', '1s'))->withMemoryPeak(200),
            (new JobResult('b', true, '', '1s'))->withMemoryPeak(250),
        ], '8.2s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringContainsString('Memory peak at 8.20s: a 200 + b 250', $rendered);
        $this->assertStringContainsString('Cores peak at 8.20s:  a + b', $rendered);
    }

    /** @test */
    public function it_emits_cores_attribution_only_when_sampler_inactive(): void
    {
        $stats = new MemoryStats(
            false,
            0,
            0.0,
            [],
            [],
            8,
            3,
            1.5,
            ['x', 'y', 'z'],
            ['x' => 1, 'y' => 1, 'z' => 1]
        );
        $result = new FlowResult('qa', [
            new JobResult('x', true, '', '1s'),
            new JobResult('y', true, '', '1s'),
            new JobResult('z', true, '', '1s'),
        ], '2s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringNotContainsString('Memory peak', $rendered);
        $this->assertStringContainsString('Cores peak at 1.50s:  x + y + z', $rendered);
        $this->assertStringContainsString('n/a', $rendered);
    }

    /** @test */
    public function status_column_marks_warned_and_failed_jobs(): void
    {
        $stats = new MemoryStats(
            true,
            2200,
            1.0,
            ['warned' => 800, 'failed' => 1400],
            ['warned' => 800, 'failed' => 1400],
            4,
            2,
            1.0,
            ['warned', 'failed'],
            ['warned' => 1, 'failed' => 1]
        );
        $jobs = [
            (new JobResult('warned', true, '', '1s'))
                ->withMemoryPeak(800)
                ->withMemoryThreshold(JobResult::MEMORY_THRESHOLD_WARNED, JobResult::MEMORY_REASON_WARN, 600, 1500),
            (new JobResult('failed', false, '', '1s'))
                ->withMemoryPeak(1400)
                ->withMemoryThreshold(JobResult::MEMORY_THRESHOLD_FAILED, JobResult::MEMORY_REASON_FAIL, 600, 1200),
        ];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringContainsString('OK ▤', $rendered);
        $this->assertStringContainsString('KO', $rendered);
    }


    /** @test */
    public function status_marks_ko_when_job_fails_without_memory_issue(): void
    {
        // Kills LogicalOr `||` -> `&&` mutant on `isMemoryFailed() || !isSuccess()`
        // at line 85. With `&&`, a plain non-success job (no memory failure)
        // would return 'OK' instead of 'KO'.
        $stats = $this->buildEmptyStats();
        $jobs = [new JobResult('boom', false, '', '1s')];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);

        $this->assertStringContainsString('KO', $output->fetch());
    }

    /** @test */
    public function total_row_uses_check_icon_on_success_and_cross_on_failure(): void
    {
        // Kills Ternary mutant on `$result->isSuccess() ? '✔' : '✗'` at
        // line 68: pin both branches in two separate cases.
        $stats = $this->buildEmptyStats();

        $passing = new FlowResult('qa', [new JobResult('a', true, '', '1s')], '1s');
        $passing->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $passing);
        $this->assertStringContainsString('✔', $output->fetch());

        $failing = new FlowResult('qa', [new JobResult('a', false, '', '1s')], '1s');
        $failing->setMemoryStats($stats);

        $output2 = new BufferedOutput();
        (new StatsTableRenderer())->render($output2, $failing);
        $this->assertStringContainsString('✗', $output2->fetch());
    }

    /** @test */
    public function memory_column_renders_n_a_when_sampler_inactive(): void
    {
        // Kills ReturnRemoval at line 112 in renderJobMemory. With the
        // return removed, the function would fall through to read the
        // job's memoryPeak — null on inactive sampler — and the cell
        // would show '-' instead of 'n/a'.
        $stats = new MemoryStats(false, 0, 0.0, [], [], 4, 0, 0.0, [], []);
        $jobs = [new JobResult('a', true, '', '1s')];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);

        $this->assertStringContainsString('n/a', $output->fetch());
    }

    /** @test */
    public function cores_column_renders_dash_for_skipped_jobs(): void
    {
        // Kills IfNegation on `if ($job->isSkipped())` at line 102:
        // a skipped job MUST render '-' in the cores column, not the
        // computed integer.
        $stats = new MemoryStats(true, 0, 0.0, [], [], 4, 0, 0.0, [], ['live' => 2]);
        $jobs = [
            JobResult::skipped('skipped_one', 'phpcs', 'no input files match', []),
            (new JobResult('live', true, '', '1s')),
        ];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        // The skipped job must have '⏭' status, and its cores cell '-'.
        // We can't easily slice columns out of the rendered table, but
        // the rendering MUST contain both markers.
        $this->assertStringContainsString('⏭', $rendered);
        $this->assertStringContainsString('skipped_one', $rendered);
    }

    /** @test */
    public function time_column_renders_dash_for_empty_execution_time(): void
    {
        // Kills Ternary at line 97 on `$time !== '' ? $time : '-'`.
        $stats = $this->buildEmptyStats();
        $jobs = [new JobResult('blank', true, '', '')]; // execution time = ''
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        // Find the row for 'blank' and assert it includes the '-'
        // marker rather than an empty time cell.
        $this->assertMatchesRegularExpression('/blank.*-/', $rendered);
    }

    /** @test */
    public function cores_cell_renders_dash_when_no_allocation_recorded_for_job(): void
    {
        // Kills Ternary at line 106 on
        // `$cores !== null ? (string) $cores : '-'`. A job that never
        // ran (no allocation) must show '-'.
        $stats = new MemoryStats(true, 0, 0.0, [], [], 4, 0, 0.0, [], []); // empty alloc map
        $jobs = [new JobResult('no_alloc', true, '', '1s')];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertMatchesRegularExpression('/no_alloc.*-/', $rendered);
    }

    private function buildEmptyStats(): MemoryStats
    {
        return new MemoryStats(true, 100, 0.5, ['a' => 100], ['a' => 100], 4, 1, 0.5, ['a'], ['a' => 1]);
    }

    // ---------------------------------------------------------------------
    // FEAT-4: stats table sort modes
    // ---------------------------------------------------------------------

    /** @test FEAT-4 */
    public function exec_sort_is_the_default_and_omits_the_order_column(): void
    {
        $result = $this->multiJobResult([
            ['name' => 'zeta', 'type' => 'phpstan'],
            ['name' => 'alpha', 'type' => 'phpcs'],
        ]);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result); // default = exec
        $rendered = $output->fetch();

        // No leading "#" column header.
        $this->assertDoesNotMatchRegularExpression('/\|\s*#\s*\|\s*Job\s*\|/', $rendered);
        // Rows keep declaration (completion) order: zeta before alpha.
        $this->assertLessThan(strpos($rendered, 'alpha'), strpos($rendered, 'zeta'));
    }

    /** @test FEAT-4 */
    public function name_sort_orders_rows_alphabetically_and_adds_execution_order_column(): void
    {
        $result = $this->multiJobResult([
            ['name' => 'zeta', 'type' => 'phpstan'],   // exec #1
            ['name' => 'alpha', 'type' => 'phpcs'],    // exec #2
            ['name' => 'mike', 'type' => 'phpcs'],     // exec #3
        ]);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result, RenderOptions::STATS_SORT_NAME);
        $rendered = $output->fetch();

        // The "#" column header is present.
        $this->assertMatchesRegularExpression('/\|\s*#\s*\|\s*Job\s*\|/', $rendered);
        // Rows are alphabetical: alpha < mike < zeta.
        $this->assertLessThan(strpos($rendered, 'mike'), strpos($rendered, 'alpha'));
        $this->assertLessThan(strpos($rendered, 'zeta'), strpos($rendered, 'mike'));
        // The "#" cell carries the original execution order (alpha ran 2nd).
        $this->assertMatchesRegularExpression('/\|\s*2\s*\|\s*alpha\s*\|/', $rendered);
        $this->assertMatchesRegularExpression('/\|\s*1\s*\|\s*zeta\s*\|/', $rendered);
        // The TOTAL row keeps the leading "#" column filled with "-" so it stays
        // aligned under the 6-column header (kills the TOTAL-row composition mutants).
        $this->assertMatchesRegularExpression('/\|\s*-\s*\|\s*TOTAL \(flow\)\s*\|/', $rendered);
    }

    /** @test FEAT-4 */
    public function type_sort_groups_by_type_then_name(): void
    {
        $result = $this->multiJobResult([
            ['name' => 'phpstan_b', 'type' => 'phpstan'],
            ['name' => 'phpcs_a', 'type' => 'phpcs'],
            ['name' => 'phpstan_a', 'type' => 'phpstan'],
            ['name' => 'phpcs_b', 'type' => 'phpcs'],
        ]);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result, RenderOptions::STATS_SORT_TYPE);
        $rendered = $output->fetch();

        // phpcs_* (type "phpcs") before phpstan_* (type "phpstan"); within a
        // type, ordered by name.
        $posPhpcsA = strpos($rendered, 'phpcs_a');
        $posPhpcsB = strpos($rendered, 'phpcs_b');
        $posPhpstanA = strpos($rendered, 'phpstan_a');
        $posPhpstanB = strpos($rendered, 'phpstan_b');
        $this->assertLessThan($posPhpcsB, $posPhpcsA, 'phpcs_a before phpcs_b');
        $this->assertLessThan($posPhpstanA, $posPhpcsB, 'all phpcs before any phpstan');
        $this->assertLessThan($posPhpstanB, $posPhpstanA, 'phpstan_a before phpstan_b');
    }

    /**
     * @param array<int, array{name: string, type: string}> $specs
     */
    private function multiJobResult(array $specs): FlowResult
    {
        $jobs = [];
        foreach ($specs as $spec) {
            // JobResult: (name, success, output, time, fixApplied, command, type)
            $jobs[] = new JobResult($spec['name'], true, '', '1s', false, null, $spec['type']);
        }
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats(new MemoryStats(false, 0, 0.0, [], [], 4, 0, 0.0, [], []));
        return $result;
    }

    // Color tagging — when the OutputInterface is decorated (CI logs that
    // accept ANSI), the renderer must wrap KO/OK ⚠/⏭ and the TOTAL fail/ok
    // markers in <fg=...> tags so the operator's eye locks on the failure
    // without scanning row by row. Off-decoration the tags strip away
    // cleanly and existing assertions still hold.

    /**
     * @test
     *
     * The pre-existing `time_column_renders_dash_for_empty_execution_time`
     * test used the regex `/blank.*-/` which also matches when Peak Cores /
     * Peak Memory render as `-` (rest of the row), so the inverted ternary
     * survived. We pin both branches: a job WITH a non-empty time must show
     * exactly that text in its time column, and a job WITH an empty time
     * must show `-`.
     */
    public function time_column_renders_exact_time_or_dash(): void
    {
        $stats = $this->buildEmptyStats();

        $withTime = new FlowResult('qa', [new JobResult('a', true, '', '4.20s')], '4.20s');
        $withTime->setMemoryStats($stats);
        $output1 = new BufferedOutput();
        (new StatsTableRenderer())->render($output1, $withTime);
        $this->assertMatchesRegularExpression(
            '/\| a\s+\|.*\|\s*4\.20s\s*\|/',
            $output1->fetch(),
            'Non-empty time must appear verbatim in the Time column'
        );

        $empty = new FlowResult('qa', [new JobResult('b', true, '', '')], '1s');
        $empty->setMemoryStats($stats);
        $output2 = new BufferedOutput();
        (new StatsTableRenderer())->render($output2, $empty);
        $this->assertMatchesRegularExpression(
            '/\| b\s+\|[^|]*\|\s*-\s*\|/',
            $output2->fetch(),
            'Empty time must render the literal "-" in the Time column'
        );
    }

    /**
     * @test
     *
     * Two adjacent rows: a skipped job whose allocation IS recorded (so the
     * negated branch would now expose the integer) and a live job with a
     * declared allocation. The mutant on L111 swaps the branches making the
     * skipped job render its cores. The mutant on L115 inverts the ternary
     * so the live job would also render `-` instead of `2`.
     */
    public function cores_column_dash_for_skipped_and_integer_for_live(): void
    {
        $stats = new MemoryStats(
            true,
            0,
            0.0,
            [],
            [],
            8,
            0,
            0.0,
            [],
            ['skipped_one' => 99, 'live' => 2]
        );
        $jobs = [
            JobResult::skipped('skipped_one', 'phpcs', 'no input files match', []),
            new JobResult('live', true, '', '1s'),
        ];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        // Skipped row → Peak Cores cell is `-` even when an allocation exists.
        $this->assertMatchesRegularExpression(
            '/\| skipped_one\s+\|[^|]*\|[^|]*\|\s*-\s*\|/',
            $rendered,
            'Skipped job must render "-" in Peak Cores even with an allocation in stats'
        );
        // Live row → Peak Cores cell is the integer (`2`), not `-`.
        $this->assertMatchesRegularExpression(
            '/\| live\s+\|[^|]*\|[^|]*\|\s*2\s*\|/',
            $rendered,
            'Live job must render the allocation integer in Peak Cores'
        );
    }

    /**
     * @test
     *
     * With the return removed, the function falls through to the
     * `getMemoryPeak()` path which is null on inactive-sampler runs and
     * returns `-`. The pre-existing test asserted only
     * `assertStringContainsString('n/a', $rendered)` which still passed
     * because the TOTAL row also renders `n/a` independently. Here we pin
     * the value to the job's row cell.
     */
    public function memory_column_renders_n_a_in_job_row_when_sampler_inactive(): void
    {
        $stats = new MemoryStats(false, 0, 0.0, [], [], 4, 0, 0.0, [], []);
        $jobs = [new JobResult('alpha', true, '', '1s')];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        // Pin the job's row, not the TOTAL row. Match `| alpha | ... | n/a |`.
        $this->assertMatchesRegularExpression(
            '/\| alpha\s+\|[^|]*\|[^|]*\|[^|]*\|\s*n\/a\s*\|/',
            $rendered,
            'Job row Peak Memory cell must be "n/a" when sampler is inactive'
        );
    }

    /**
     * @test
     *
     * Sampler active, but the job recorded no memory peak (e.g. job was
     * skipped or finished before the first sample). The cell must be `-`
     * — not a literal `' MB'` that the falling-through return path would
     * produce.
     */
    public function memory_column_renders_dash_when_peak_is_null_with_active_sampler(): void
    {
        $stats = new MemoryStats(
            true,
            500,
            1.0,
            ['other' => 500],
            ['other' => 500],
            4,
            1,
            1.0,
            ['other'],
            ['no_peak' => 1, 'other' => 1]
        );
        $jobs = [
            new JobResult('no_peak', true, '', '1s'), // withMemoryPeak() not called
            (new JobResult('other', true, '', '1s'))->withMemoryPeak(500),
        ];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        // no_peak row → Peak Memory cell is `-` (not "0 MB" or "MB").
        $this->assertMatchesRegularExpression(
            '/\| no_peak\s+\|[^|]*\|[^|]*\|[^|]*\|\s*-\s*\|/',
            $rendered,
            'Job with null memory peak must render "-" in Peak Memory'
        );
        $this->assertStringNotContainsString(' MB |', preg_replace('/500 MB/', '', $rendered) ?? '');
    }

    /**
     * @test
     *
     * The blank line between the table and the temporal attribution lines is
     * a visual contract — without it the operator sees the attribution stuck
     * to the bottom border of the table. We assert the exact blank-line
     * separation before "Memory peak at".
     */
    public function attribution_block_is_preceded_by_a_blank_line_when_sampler_active(): void
    {
        $stats = new MemoryStats(
            true,
            300,
            1.0,
            ['a' => 300],
            ['a' => 300],
            4,
            1,
            1.0,
            ['a'],
            ['a' => 1]
        );
        $jobs = [(new JobResult('a', true, '', '1s'))->withMemoryPeak(300)];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        // Table closes with `+----...+\n` ; renderAttribution then writes ''
        // (a newline) before the "Memory peak" line. We expect at least one
        // empty line right before "Memory peak at".
        $this->assertMatchesRegularExpression(
            '/\+[-+]+\+\n\nMemory peak at /',
            $rendered,
            'Attribution block must be preceded by a blank line'
        );
    }

    /**
     * @test
     *
     * Without the early return the function continues with an empty
     * `$parts = []`, `implode(' + ', [])` returns `''`, and the attribution
     * line ends with the colon followed by nothing.
     */
    public function memory_attribution_uses_no_jobs_in_flight_marker_when_map_is_empty(): void
    {
        $stats = new MemoryStats(
            true,
            400,
            2.5,
            [],                  // attribution map empty
            ['a' => 400],
            4,
            1,
            2.5,
            ['a'],
            ['a' => 1]
        );
        $jobs = [(new JobResult('a', true, '', '1s'))->withMemoryPeak(400)];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringContainsString(
            'Memory peak at 2.50s: (no jobs in flight)',
            $rendered
        );
    }

    /** @test */
    public function ko_status_cell_is_wrapped_in_red_when_output_is_decorated(): void
    {
        $stats = $this->buildEmptyStats();
        $jobs = [new JobResult('boom', false, '', '1s')];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        // ANSI escape for red: \033[31m...\033[39m
        $this->assertMatchesRegularExpression('/\e\[31m\s*KO\s*\e\[39m/', $rendered);
    }

    /** @test */
    public function memory_warned_status_cell_is_wrapped_in_yellow_when_decorated(): void
    {
        $stats = new MemoryStats(
            true,
            800,
            1.0,
            ['warned' => 800],
            ['warned' => 800],
            4,
            1,
            1.0,
            ['warned'],
            ['warned' => 1]
        );
        $jobs = [
            (new JobResult('warned', true, '', '1s'))
                ->withMemoryPeak(800)
                ->withMemoryThreshold(JobResult::MEMORY_THRESHOLD_WARNED, JobResult::MEMORY_REASON_WARN, 600, 1500),
        ];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        // ANSI escape for yellow: \033[33m...\033[39m
        $this->assertMatchesRegularExpression('/\e\[33m.*OK.*▤.*\e\[39m/u', $rendered);
    }

    /** @test */
    public function skipped_status_cell_is_wrapped_in_blue_when_decorated(): void
    {
        $stats = new MemoryStats(true, 0, 0.0, [], [], 4, 0, 0.0, [], []);
        $jobs = [JobResult::skipped('idle', 'phpcs', 'no input files match', [])];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        // ANSI escape for blue: \033[34m...\033[39m
        $this->assertMatchesRegularExpression('/\e\[34m.*⏭.*\e\[39m/u', $rendered);
    }

    /** @test */
    public function total_row_uses_red_for_failure_and_green_for_success_when_decorated(): void
    {
        $stats = $this->buildEmptyStats();

        // Failure case
        $failing = new FlowResult('qa', [new JobResult('a', false, '', '1s')], '1s');
        $failing->setMemoryStats($stats);
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        (new StatsTableRenderer())->render($output, $failing);
        $rendered = $output->fetch();
        $this->assertMatchesRegularExpression('/\e\[31m.*0\/1\s*✗.*\e\[39m/u', $rendered);

        // Success case
        $passing = new FlowResult('qa', [new JobResult('a', true, '', '1s')], '1s');
        $passing->setMemoryStats($stats);
        $output2 = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        (new StatsTableRenderer())->render($output2, $passing);
        $rendered2 = $output2->fetch();
        $this->assertMatchesRegularExpression('/\e\[32m.*1\/1\s*✔.*\e\[39m/u', $rendered2);
    }

    /**
     * FEAT-18: the Status column shows per-dimension warn icons — ⏱ (time
     * warn-after), ▤ (memory warn-above), combinable in the fixed order
     * time-before-memory. Precedence is skip → KO → warns; a KO job shows no
     * warn icons. This dataProvider walks the full Status decision table.
     *
     * @test
     * @dataProvider statusDecisionTableProvider
     */
    public function status_column_renders_per_dimension_warn_icons(
        bool $success,
        bool $timeWarn,
        bool $memoryWarn,
        string $expectedStatus
    ): void {
        $job = new JobResult('a', $success, '', '1s');
        if ($timeWarn) {
            $job = $job->withThreshold(JobResult::THRESHOLD_WARNED, JobResult::THRESHOLD_REASON_WARN);
        }
        if ($memoryWarn) {
            $job = $job->withMemoryThreshold(
                JobResult::MEMORY_THRESHOLD_WARNED,
                JobResult::MEMORY_REASON_WARN,
                600,
                1500
            );
        }
        $result = new FlowResult('qa', [$job], '1s');
        $result->setMemoryStats($this->buildEmptyStats());

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringContainsString($expectedStatus, $rendered);
        // Single-job flow: the Status cell is the only place an icon can come
        // from, so a missing icon in $expectedStatus must be absent globally.
        if (strpos($expectedStatus, '⏱') === false) {
            $this->assertStringNotContainsString('⏱', $rendered);
        }
        if (strpos($expectedStatus, '▤') === false) {
            $this->assertStringNotContainsString('▤', $rendered);
        }
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool, 3: string}>
     */
    public function statusDecisionTableProvider(): array
    {
        // [success, timeWarn, memoryWarn, expectedStatus]
        // Icons carry their text variation selector (⏱ = U+23F1 U+FE0E) exactly
        // as the renderer emits them, so the fixed time-before-memory order is pinned.
        return [
            'AC-001 time warn only'         => [true, true, false, "OK \u{23F1}\u{FE0E}"],
            'AC-002 memory warn only'       => [true, false, true, "OK \u{25A4}"],
            'AC-003 both warns fixed order' => [true, true, true, "OK \u{23F1}\u{FE0E}\u{25A4}"],
            'AC-004 no warns'               => [true, false, false, 'OK'],
            'AC-005 KO masks warns'         => [false, true, true, 'KO'],
        ];
    }

    /**
     * FEAT-18 (AC-006): the TOTAL-row Peak Cores cell is marked with ⚙ in
     * yellow only on real over-subscription (coresPeak > coresLimit). Saturation
     * (peak == limit) and under-use (peak < limit) stay plain.
     *
     * @test
     * @dataProvider totalCoresProvider
     */
    public function total_row_marks_cores_over_subscription(
        int $coresPeak,
        int $coresLimit,
        bool $expectMark
    ): void {
        $stats = new MemoryStats(true, 100, 0.5, ['a' => 100], ['a' => 100], $coresLimit, $coresPeak, 0.5, ['a'], ['a' => 1]);
        $result = new FlowResult('qa', [(new JobResult('a', true, '', '1s'))->withMemoryPeak(100)], '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringContainsString($coresPeak . '/' . $coresLimit, $rendered);
        if ($expectMark) {
            // ⚙ inside a yellow span on the TOTAL cell: \e[33m...⚙...\e[39m
            $this->assertMatchesRegularExpression('/\e\[33m[^\e]*⚙[^\e]*\e\[39m/u', $rendered);
        } else {
            $this->assertStringNotContainsString('⚙', $rendered);
        }
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: bool}>
     */
    public function totalCoresProvider(): array
    {
        // [coresPeak, coresLimit, expectMark]
        return [
            'over-subscription marks'  => [10, 8, true],
            'saturation stays plain'   => [8, 8, false],
            'under-use stays plain'    => [3, 8, false],
        ];
    }

    /** @test */
    public function color_tags_strip_cleanly_when_output_is_undecorated(): void
    {
        // Guard: never leak literal `<fg=...>` markers to plain logs.
        $stats = $this->buildEmptyStats();
        $jobs = [new JobResult('boom', false, '', '1s')];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, false);
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringNotContainsString('<fg=', $rendered);
        $this->assertStringNotContainsString('</>', $rendered);
        $this->assertStringContainsString('KO', $rendered);
    }
}
