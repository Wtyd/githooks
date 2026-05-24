<?php

declare(strict_types=1);

namespace Tests\Unit\Output\CI;

use Closure;
use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Output\CI\GitLabCIDecorator;
use Wtyd\GitHooks\Output\DashboardOutputHandler;
use Wtyd\GitHooks\Output\OutputHandler;

/**
 * The decorator buffers each job's body and emits the collapsible section
 * atomically on close, so parallel jobs cannot interleave their section
 * boundaries (which GitLab does not support).
 *
 * Design factors covered:
 *  - close state: OK / KO / SKIPPED
 *  - collapsed flag: true for OK & SKIPPED, false for KO and Errors flush
 *  - inner.flush() suppression so framed errors don't leak outside sections
 *  - parallel interleaving (start A → start B → end B → end A): no overlap
 */
class GitLabCIDecoratorTest extends TestCase
{
    /** @test */
    public function ok_section_is_emitted_atomically_with_collapsed_true_on_success()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobStart')->with('phpstan_src');
        $inner->expects($this->once())->method('onJobSuccess')->with('phpstan_src', '1.23s');

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('phpstan_src');
        $duringJob = ob_get_clean();

        ob_start();
        $decorator->onJobSuccess('phpstan_src', '1.23s');
        $output = ob_get_clean();

        // Nothing must reach stdout while the job is active — only on close.
        $this->assertSame('', $duringJob, 'No output is expected before the job closes');

        $this->assertStringContainsString('section_start:', $output);
        $this->assertStringContainsString('[collapsed=true]', $output);
        $this->assertStringContainsString('section_end:', $output);
        $this->assertStringContainsString('phpstan_src', $output);
    }

    /** @test */
    public function ko_section_is_collapsed_false_and_includes_tool_output()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobStart');
        $inner->expects($this->once())->method('onJobError')
            ->with('phpstan_src', '500ms', "Line 42: undefined method foo()\n");

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('phpstan_src');
        $decorator->onJobError('phpstan_src', '500ms', "Line 42: undefined method foo()\n");
        $output = ob_get_clean();

        $this->assertStringContainsString('[collapsed=false]', $output);
        $this->assertStringContainsString('Line 42: undefined method foo()', $output);
        $this->assertStringContainsString('section_start:', $output);
        $this->assertStringContainsString('section_end:', $output);
    }

    /** @test */
    public function ko_section_omits_error_block_when_tool_output_is_blank()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->method('onJobError');

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobError('phpstan_src', '500ms', "   \n  ");
        $output = ob_get_clean();

        $this->assertStringContainsString('[collapsed=false]', $output);
        // Body has just the section header line + whatever inner produced.
        $this->assertStringNotContainsString('   ', substr($output, strpos($output, "phpstan_src\n") + 12));
    }

    /** @test */
    public function skipped_section_uses_collapsed_true()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobSkipped')->with('phpcs', 'no staged files');

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobSkipped('phpcs', 'no staged files');
        $output = ob_get_clean();

        $this->assertStringContainsString('[collapsed=true]', $output);
        $this->assertStringContainsString('phpcs', $output);
    }

    /** @test */
    public function inner_flush_output_is_suppressed_to_avoid_duplicate_error_blocks()
    {
        $inner = new class implements OutputHandler {
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function onJobWaiting(string $jobName, array $waitingFor): void
            {
            }
            public function flush(): void
            {
                echo "framed error block (should be suppressed)\n";
            }
        };

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->flush();
        $output = ob_get_clean();

        $this->assertSame('', $output, 'inner.flush() output must not leak outside any section');
    }

    /** @test */
    public function parallel_job_interleaving_still_emits_non_overlapping_sections()
    {
        // Simulates parallel execution: A and B start interleaved, then close
        // out-of-order. Each section must open AND close in one shot, never
        // straddling another section.
        $inner = $this->createMock(OutputHandler::class);

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('A');
        $decorator->onJobStart('B');
        $decorator->onJobSuccess('B', '1s');
        $decorator->onJobSuccess('A', '2s');
        $output = ob_get_clean();

        // Two complete sections: B's first (closed first), then A's.
        $this->assertSame(
            2,
            substr_count($output, 'section_start:'),
            'Exactly two section_start markers'
        );
        $this->assertSame(
            2,
            substr_count($output, 'section_end:'),
            'Exactly two section_end markers'
        );

        // Critical invariant: no nesting. Each section_start is followed by
        // its matching section_end before the next section_start.
        $tokens = preg_split('/\R/', $output) ?: [];
        $stack = 0;
        foreach ($tokens as $line) {
            if (strpos($line, 'section_start:') !== false) {
                $stack++;
                $this->assertSame(1, $stack, "Sections must not overlap (line: $line)");
            }
            if (strpos($line, 'section_end:') !== false) {
                $stack--;
            }
        }
        $this->assertSame(0, $stack, 'All sections must be closed');
    }

    /** @test */
    public function each_section_uses_a_unique_id()
    {
        $inner = $this->createMock(OutputHandler::class);
        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobSuccess('job1', '1s');
        $decorator->onJobSuccess('job2', '1s');
        $output = ob_get_clean();

        $this->assertStringContainsString('githooks_job_1', $output);
        $this->assertStringContainsString('githooks_job_2', $output);
    }

    /** @test */
    public function it_delegates_pass_through_methods()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onFlowStart')->with(3);
        $inner->expects($this->once())->method('onJobDryRun')->with('phpcs', 'php phpcs.phar');

        $decorator = new GitLabCIDecorator($inner);
        $decorator->onFlowStart(3);

        ob_start();
        $decorator->onJobDryRun('phpcs', 'php phpcs.phar');
        ob_end_clean();
    }

    /** @test */
    public function inner_streamed_output_lands_inside_the_section_body()
    {
        // Simulates an inner handler that streams via echo (e.g. StreamingTextOutputHandler).
        $inner = new class implements OutputHandler {
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
                echo "  --- $jobName ---\n";
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
                echo $chunk;
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
                echo "  $jobName OK $time\n";
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function onJobWaiting(string $jobName, array $waitingFor): void
            {
            }
            public function flush(): void
            {
            }
        };

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('phpstan');
        $decorator->onJobOutput('phpstan', "running...\n", false);
        $decorator->onJobSuccess('phpstan', '0.5s');
        $output = ob_get_clean();

        // Section body must include all streamed content from the inner handler.
        $startIdx = strpos($output, 'phpstan');
        $endIdx = strpos($output, 'section_end:');
        $body = substr($output, (int) $startIdx, (int) $endIdx - (int) $startIdx);

        $this->assertStringContainsString('--- phpstan ---', $body);
        $this->assertStringContainsString('running...', $body);
        $this->assertStringContainsString('phpstan OK 0.5s', $body);
    }

    /**
     * Targets the trailing-newline guard at GitLabCIDecorator::emitSection (L113):
     *
     *   if ($body !== '' && substr($body, -1) !== "\n") { echo "\n"; }
     *
     * Mutants killed:
     *
     * Strategy: drive the decorator with an inner that emits a body whose
     * last char is NOT `\n`. The decorator must add EXACTLY ONE `\n` between
     * the body and `section_end`, so the output cannot contain `\n\n` between
     * them. Likewise, with a body that DOES end in `\n` no extra newline can
     * appear. With an empty body (skipped via inner that emits nothing
     * before close), the guard must NOT add a `\n` either (kills LogicalAnd).
     *
     * @test
     */
    public function emit_section_normalises_trailing_newline(): void
    {
        // Case A — body without trailing \n.
        $innerNoNewline = new class implements OutputHandler {
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
                echo "no-newline-content"; // no trailing \n
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function onJobWaiting(string $jobName, array $waitingFor): void
            {
            }
            public function flush(): void
            {
            }
        };

        $decorator = new GitLabCIDecorator($innerNoNewline);
        ob_start();
        $decorator->onJobStart('jobA');
        $decorator->onJobSuccess('jobA', '1s');
        $output = ob_get_clean();

        // Body content present
        $this->assertStringContainsString('no-newline-content', $output);
        // Exactly one '\n' between content and section_end (the guard added it).
        $this->assertMatchesRegularExpression(
            '/no-newline-content\nsection_end:/',
            preg_replace('/\033\[0K/', '', $output) ?? '',
            'Body without trailing newline must get exactly one \n added before section_end'
        );
        // No double newline (kills NotIdentical-inversion which would always add \n).
        $this->assertStringNotContainsString(
            "no-newline-content\n\n",
            preg_replace('/\033\[0K/', '', $output) ?? ''
        );

        // Case B — body already terminated by \n (NO extra newline added).
        $innerWithNewline = new class implements OutputHandler {
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
                echo "with-newline-content\n";
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function onJobWaiting(string $jobName, array $waitingFor): void
            {
            }
            public function flush(): void
            {
            }
        };
        $decorator = new GitLabCIDecorator($innerWithNewline);
        ob_start();
        $decorator->onJobStart('jobB');
        $decorator->onJobSuccess('jobB', '1s');
        $output = ob_get_clean();
        // Body ends with single \n, decorator must NOT add a second one.
        $this->assertStringNotContainsString(
            "with-newline-content\n\n",
            preg_replace('/\033\[0K/', '', $output) ?? '',
            'Body already ending in newline must not have an extra newline appended'
        );

        // Case C — empty body (inner emits nothing): guard `$body !== ''`
        // must short-circuit, no spurious \n inserted between header and end.
        $innerEmpty = new class implements OutputHandler {
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function onJobWaiting(string $jobName, array $waitingFor): void
            {
            }
            public function flush(): void
            {
            }
        };
        $decorator = new GitLabCIDecorator($innerEmpty);
        ob_start();
        $decorator->onJobSuccess('jobC', '1s');
        $output = ob_get_clean();
        $stripped = preg_replace('/\033\[0K/', '', $output) ?? '';
        // After "jobC\n" must come immediately "section_end:" — no extra blank line.
        $this->assertMatchesRegularExpression(
            '/jobC\nsection_end:/',
            $stripped,
            'Empty body must not have a blank line inserted between header and section_end'
        );
    }

    /**
     * BUG-16 regression: `section_start` must use the timestamp captured at
     * `onJobStart`, not a `time()` reading taken inside `emitSection`. The
     * previous implementation calculated both `$start` and `$end` inside
     * `emitSection`, separated only by an `echo $body`, so GitLab always
     * rendered grouped sections as `00:00`.
     *
     * Drives the fix via an injected clock that yields 100 on `onJobStart`
     * and 105 on the close — the resulting section markers must reflect
     * those exact values.
     *
     * @test
     */
    public function section_start_uses_onJobStart_timestamp_not_emit_time(): void
    {
        $clock = $this->fixedClock([100, 105]);
        $inner = $this->createMock(OutputHandler::class);
        $decorator = new GitLabCIDecorator($inner, $clock);

        ob_start();
        $decorator->onJobStart('phpstan');
        $decorator->onJobSuccess('phpstan', '5s');
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression('/section_start:100:/', $output);
        $this->assertMatchesRegularExpression('/section_end:105:/', $output);
    }

    /**
     * Decision-table coverage for BUG-16.
     *
     * Factors:
     *   - close method: onJobSuccess / onJobError / onJobSkipped
     *   - prior onJobStart: yes (rows 1-3) / no (rows 4-5)
     *   - Δtime between start and close: 0s, 3s, 5s
     *
     * Rows 1-3 cover the main bug regression (with the buggy implementation
     * all three would render as 0). Rows 4-5 cover the fallback path where
     * the close method is invoked without a prior `onJobStart`.
     *
     * @test
     * @dataProvider sectionTimestampScenarios
     *
     * @param int[]  $clockValues clock returns these values in order
     * @param string $closeMethod onJobSuccess|onJobError|onJobSkipped
     * @param bool   $callStart   whether to call onJobStart before close
     * @param int    $expectedStart
     * @param int    $expectedEnd
     */
    public function emit_section_timestamps_follow_decision_table(
        array $clockValues,
        string $closeMethod,
        bool $callStart,
        int $expectedStart,
        int $expectedEnd
    ): void {
        $clock = $this->fixedClock($clockValues);
        $inner = $this->createMock(OutputHandler::class);
        $decorator = new GitLabCIDecorator($inner, $clock);

        ob_start();
        if ($callStart) {
            $decorator->onJobStart('job');
        }
        if ($closeMethod === 'onJobSuccess') {
            $decorator->onJobSuccess('job', '1s');
        } elseif ($closeMethod === 'onJobError') {
            $decorator->onJobError('job', '1s', '');
        } else {
            $decorator->onJobSkipped('job', 'no staged files');
        }
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression(
            '/section_start:' . $expectedStart . ':/',
            $output,
            "section_start expected to be $expectedStart in scenario $closeMethod (start=$callStart)"
        );
        $this->assertMatchesRegularExpression(
            '/section_end:' . $expectedEnd . ':/',
            $output,
            "section_end expected to be $expectedEnd in scenario $closeMethod (start=$callStart)"
        );
    }

    public function sectionTimestampScenarios(): array
    {
        return [
            // # | onJobStart | close         | Δtime | start | end
            'success after 5s with prior start' => [
                [100, 105], 'onJobSuccess', true, 100, 105,
            ],
            'error after 3s with prior start' => [
                [200, 203], 'onJobError', true, 200, 203,
            ],
            'skipped at the same instant as start' => [
                [300, 300], 'onJobSkipped', true, 300, 300,
            ],
            // Fallback: no onJobStart was ever called for this job. The
            // decorator falls back to the clock reading at emit time, so
            // start == end (preserves the previous behaviour for this path).
            'error without prior start falls back to emit-time' => [
                [400], 'onJobError', false, 400, 400,
            ],
            'skipped without prior start falls back to emit-time' => [
                [500], 'onJobSkipped', false, 500, 500,
            ],
        ];
    }

    /**
     * BUG-18 regression: when `structuredFormat = true` and a job tool fails,
     * the KO section must NOT contain the tool's raw JSON output. The
     * `FlowExecutor` is expected to humanise the output upstream via the
     * `HumanIssueFormatter` before invoking `onJobError`, so by the time the
     * decorator buffers the body, the JSON blob is already gone.
     *
     * This is a regression test of the full path (FlowExecutor → decorator →
     * dashboard inner) — it would catch any future refactor that bypasses
     * the humanisation step (e.g. emitting raw `$output` from a new code path).
     *
     * @test
     */
    public function ko_section_with_structured_format_does_not_leak_raw_json(): void
    {
        $rawJson = '{"totals":{"errors":1,"file_errors":1},"files":{"src/Foo.php":'
            . '{"errors":1,"messages":[{"message":"Class Foo not found.","line":42,'
            . '"identifier":"class.notFound"}]}},"errors":[]}';
        $payload = base64_encode($rawJson);

        $job = new class (new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']])) extends PhpstanJob {
            public string $payload = '';
            public function buildCommand(): string
            {
                return "sh -c 'echo {$this->payload} | base64 -d; exit 1'";
            }
        };
        $job->payload = $payload;

        $dashboard = new DashboardOutputHandler(false);
        $decorator = new GitLabCIDecorator($dashboard);
        $executor = new FlowExecutor($decorator);
        $executor->setStructuredFormat(true);

        ob_start();
        $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration(false, 1)));
        $output = ob_get_clean();

        // Strip GitLab \033[0K control sequences to ease assertion authoring.
        $body = preg_replace('/\033\[0K/', '', $output) ?? '';

        // The raw JSON markers must NOT appear anywhere in the section body.
        $this->assertStringNotContainsString('"totals":', $body, 'Raw JSON "totals" must not leak into the KO section');
        $this->assertStringNotContainsString('"files":', $body, 'Raw JSON "files" must not leak into the KO section');
        $this->assertStringNotContainsString('"messages":', $body, 'Raw JSON "messages" must not leak into the KO section');

        // The humanised content must appear instead.
        $this->assertStringContainsString('src/Foo.php', $body, 'Humanised path must be present');
        $this->assertStringContainsString('line 42', $body, 'Humanised line must be present');
        $this->assertStringContainsString('Class Foo not found', $body, 'Humanised message must be present');
        $this->assertStringContainsString('[class.notFound]', $body, 'Humanised rule id must be present');
        $this->assertStringContainsString('section_start:', $body);
        $this->assertStringContainsString('[collapsed=false]', $body);
    }

    /**
     * @test
     *
     * Strategy: drive the decorator with an inner that emits a different
     * marker on every method call (start-1, output-A, output-B, success).
     * Without the buffer accumulation (or with the swapped coalesce, which
     * always picks `''` when the buffer already exists) every prior marker
     * would be lost from the body emitted by emitSection. We assert ALL
     * markers appear in the section body and in the declared order.
     */
    public function buffered_body_accumulates_all_inner_emissions_before_close(): void
    {
        $inner = new class implements OutputHandler {
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
                echo "marker:start\n";
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
                echo "marker:output($chunk)\n";
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
                echo "marker:success\n";
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
                echo "marker:error\n";
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function onJobWaiting(string $jobName, array $waitingFor): void
            {
            }
            public function flush(): void
            {
            }
        };

        $decorator = new GitLabCIDecorator($inner);

        // OK path: start + 2 outputs + success — buffer must accumulate.
        ob_start();
        $decorator->onJobStart('j1');
        $decorator->onJobOutput('j1', 'A', false);
        $decorator->onJobOutput('j1', 'B', false);
        $decorator->onJobSuccess('j1', '1s');
        $okOut = preg_replace('/\033\[0K/', '', ob_get_clean() ?: '') ?? '';

        $this->assertStringContainsString('marker:start', $okOut);
        $this->assertStringContainsString('marker:output(A)', $okOut);
        $this->assertStringContainsString('marker:output(B)', $okOut);
        $this->assertStringContainsString('marker:success', $okOut);

        // Their order must be preserved.
        $idxStart = strpos($okOut, 'marker:start');
        $idxA = strpos($okOut, 'marker:output(A)');
        $idxB = strpos($okOut, 'marker:output(B)');
        $idxSuccess = strpos($okOut, 'marker:success');
        $this->assertNotFalse($idxStart);
        $this->assertNotFalse($idxA);
        $this->assertNotFalse($idxB);
        $this->assertNotFalse($idxSuccess);
        $this->assertLessThan($idxA, $idxStart, 'start marker precedes output(A)');
        $this->assertLessThan($idxB, $idxA, 'output(A) precedes output(B)');
        $this->assertLessThan($idxSuccess, $idxB, 'output(B) precedes success');

        // KO path: start + output + error — onJobError must concatenate on
        // top of the previous buffer, not reset it. (Kills L86 mutants.)
        $decorator2 = new GitLabCIDecorator($inner);
        ob_start();
        $decorator2->onJobStart('j2');
        $decorator2->onJobOutput('j2', 'X', false);
        $decorator2->onJobError('j2', '1s', '');
        $koOut = preg_replace('/\033\[0K/', '', ob_get_clean() ?: '') ?? '';

        $this->assertStringContainsString('marker:start', $koOut);
        $this->assertStringContainsString('marker:output(X)', $koOut);
        $this->assertStringContainsString('marker:error', $koOut);
    }

    /**
     * @test
     *
     * Output is pure whitespace (`"  \t  \n  "`): the body must contain ONLY
     * the inner's emissions, never the raw whitespace. The previous
     * `ko_section_omits_error_block_when_tool_output_is_blank` test used a
     * loose `assertStringNotContainsString('   ', substr(...))` whose substring
     * boundary was fragile against ANSI sequences; we anchor exactly on the
     * absence of the rtrimmed whitespace.
     */
    public function ko_pure_whitespace_output_is_filtered_out_by_trim_guard(): void
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->method('onJobError');

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobError('phpstan', '1s', "   \t   \n   ");
        $output = preg_replace('/\033\[0K/', '', ob_get_clean() ?: '') ?? '';

        // If the trim guard is unwrapped, the body contains "rtrim(...) . \n"
        // — for "   \t   \n   " that's "   \t   \n". Assert the body
        // between job name and section_end does not contain leading triple
        // whitespace or the explicit tab.
        $bodyStart = strpos($output, "phpstan\n");
        $this->assertNotFalse($bodyStart);
        $body = substr($output, $bodyStart + strlen("phpstan\n"));
        $sectionEnd = strpos($body, 'section_end:');
        $this->assertNotFalse($sectionEnd);
        $bodyContent = substr($body, 0, $sectionEnd);

        $this->assertSame(
            '',
            $bodyContent,
            'Pure whitespace output must yield an empty body, not the raw whitespace'
        );
    }

    /**
     * @test
     *
     * Drive a KO with a previously-buffered marker AND an `$output` that
     * already ends with `\n`. The body composition must:
     *   1. preserve the prior buffer (kills Assignment),
     *   2. trim the trailing newline before appending (kills UnwrapRtrim
     *      — without rtrim we'd get `…\n\n` between content and section_end),
     *   3. place the error content BEFORE the appended `\n` (kills Concat
     *      order swap — the swap would emit `\nERROR` and the trailing-
     *      newline guard on L143 would then add yet another `\n`).
     */
    public function ko_body_composition_pins_assignment_rtrim_and_concat_order(): void
    {
        $innerPrior = new class implements OutputHandler {
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
                echo "PRIOR\n";
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function onJobWaiting(string $jobName, array $waitingFor): void
            {
            }
            public function flush(): void
            {
            }
        };

        $decorator = new GitLabCIDecorator($innerPrior);

        ob_start();
        $decorator->onJobStart('j');
        // $output already ends with \n — rtrim must strip exactly one newline
        // before the explicit `. "\n"` appends.
        $decorator->onJobError('j', '1s', "ERROR\n");
        $raw = ob_get_clean() ?: '';
        $output = preg_replace('/\033\[0K/', '', $raw) ?? '';

        // 1) PRIOR marker survives — kills Assignment.
        $this->assertStringContainsString('PRIOR', $output);

        // 2) Error content + exactly one \n — kills UnwrapRtrim. Without
        //    rtrim, body would contain "ERROR\n\n" before section_end.
        $this->assertStringNotContainsString("ERROR\n\nsection_end:", $output);

        // 3) ERROR appears before the appended newline, not after. The
        //    swapped concat (`"\n" . rtrim($output)`) would produce
        //    "PRIOR\n\nERROR" (the appended \n is now the prefix) and the
        //    trailing-newline guard at L143 would add another \n →
        //    "PRIOR\n\nERROR\n". The "ERROR\n" still appears, so we anchor
        //    on the absence of the spurious leading-\n pattern between
        //    PRIOR and ERROR.
        $this->assertMatchesRegularExpression(
            '/PRIOR\nERROR\nsection_end:/',
            $output,
            'ERROR must follow PRIOR with exactly one \n, then \n then section_end'
        );
    }

    /**
     * @test
     *
     * The pre-existing `inner_flush_output_is_suppressed_…` test asserted
     * that the decorator emits `''` after a `flush()` — but that assertion
     * is also satisfied when the closure is never invoked at all. Here we
     * use an inner that records the invocation count of `flush()` in a
     * public property. The decorator must call `inner->flush()` exactly
     * once even though it captures (and discards) its output.
     */
    public function decorator_flush_invokes_inner_flush_exactly_once(): void
    {
        $inner = new class implements OutputHandler {
            public int $flushCalls = 0;
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function onJobWaiting(string $jobName, array $waitingFor): void
            {
                $this->flushCalls += 0; // touch to keep interface satisfied
            }
            public function flush(): void
            {
                $this->flushCalls++;
                echo "framed error block\n"; // must be suppressed by captureInner
            }
        };

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->flush();
        $captured = ob_get_clean() ?: '';

        $this->assertSame(1, $inner->flushCalls, 'inner->flush() must be called exactly once');
        $this->assertSame('', $captured, 'inner.flush() output must be discarded by captureInner');
    }

    /**
     * @test
     *
     *     → `$action(); $captured = ob_get_clean();`
     *
     * Without the finally block, an exception thrown inside the captured
     * closure would leave the output buffer open, corrupting any
     * subsequent output that the test (or production code) writes to
     * stdout. We invoke a decorator method whose inner closure throws,
     * verify the exception propagates, and then assert that the
     * `ob_get_level()` is back to the level we observed before invoking
     * the decorator — proving the buffer was popped by the finally block.
     */
    public function captureInner_finally_pops_output_buffer_when_inner_throws(): void
    {
        $inner = new class implements OutputHandler {
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
                throw new \RuntimeException('inner explodes during onJobStart');
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function onJobWaiting(string $jobName, array $waitingFor): void
            {
            }
            public function flush(): void
            {
            }
        };

        $decorator = new GitLabCIDecorator($inner);

        $levelBefore = ob_get_level();

        try {
            $decorator->onJobStart('exploder');
            $this->fail('expected RuntimeException to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('inner explodes during onJobStart', $e->getMessage());
        }

        $this->assertSame(
            $levelBefore,
            ob_get_level(),
            'finally must pop the output buffer started by captureInner even on exception'
        );
    }

    /**
     * Build a clock closure that returns `$values` in order, then sticks at
     * the final value to keep tests stable if the implementation incidentally
     * makes one extra call.
     *
     * @param int[] $values
     */
    private function fixedClock(array $values): Closure
    {
        $remaining = $values;
        $last = end($values);
        return function () use (&$remaining, $last): int {
            return array_shift($remaining) ?? $last;
        };
    }
}
