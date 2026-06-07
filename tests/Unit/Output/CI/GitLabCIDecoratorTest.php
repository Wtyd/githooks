<?php

declare(strict_types=1);

namespace Tests\Unit\Output\CI;

use Closure;
use Tests\Utils\TestCase\UnitTestCase;
use Tests\Concerns\AssertsOutputBody;
use Tests\Concerns\CapturesStdout;
use Tests\Doubles\CountingFlushOutputHandler;
use Tests\Doubles\EmittingOutputHandler;
use Tests\Doubles\NoOpOutputHandler;
use Tests\Doubles\StreamingMarkerOutputHandler;
use Tests\Doubles\ThrowsOnJobStartOutputHandler;
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
class GitLabCIDecoratorTest extends UnitTestCase
{
    use CapturesStdout;
    use AssertsOutputBody;

    /** @test */
    public function ok_section_is_emitted_atomically_with_collapsed_true_on_success()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobStart')->with('phpstan_src');
        $inner->expects($this->once())->method('onJobSuccess')->with('phpstan_src', '1.23s');

        $decorator = new GitLabCIDecorator($inner);

        $duringJob = $this->captureStdoutRaw(function () use ($decorator) {
            $decorator->onJobStart('phpstan_src');
        });
        $output = $this->captureStdoutRaw(function () use ($decorator) {
            $decorator->onJobSuccess('phpstan_src', '1.23s');
        });

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

        $output = $this->captureStdoutRaw(function () use ($decorator) {
            $decorator->onJobStart('phpstan_src');
            $decorator->onJobError('phpstan_src', '500ms', "Line 42: undefined method foo()\n");
        });

        $this->assertStringContainsString('[collapsed=false]', $output);
        $this->assertStringContainsString('Line 42: undefined method foo()', $output);
        $this->assertStringContainsString('section_start:', $output);
        $this->assertStringContainsString('section_end:', $output);
    }

    /** @test */
    public function ko_section_body_is_empty_when_tool_output_is_pure_whitespace()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->method('onJobError');

        $decorator = new GitLabCIDecorator($inner);

        $output = $this->captureStdout(function () use ($decorator) {
            $decorator->onJobError('phpstan_src', '500ms', "   \t   \n   ");
        });

        $this->assertStringContainsString('[collapsed=false]', $output);
        $this->assertSame(
            '',
            $this->extractSectionBody('phpstan_src', $output),
            'Pure whitespace tool output must produce an empty body — the trim guard filters it out'
        );
    }

    /** @test */
    public function skipped_section_uses_collapsed_true()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobSkipped')->with('phpcs', 'no staged files');

        $decorator = new GitLabCIDecorator($inner);

        $output = $this->captureStdoutRaw(function () use ($decorator) {
            $decorator->onJobSkipped('phpcs', 'no staged files');
        });

        $this->assertStringContainsString('[collapsed=true]', $output);
        $this->assertStringContainsString('phpcs', $output);
    }

    /** @test */
    public function inner_flush_output_is_suppressed_to_avoid_duplicate_error_blocks()
    {
        $inner = new CountingFlushOutputHandler("framed error block (should be suppressed)\n");
        $decorator = new GitLabCIDecorator($inner);

        $output = $this->captureStdoutRaw(function () use ($decorator) {
            $decorator->flush();
        });

        $this->assertSame(1, $inner->flushCalls, 'inner->flush() must be called exactly once');
        $this->assertSame('', $output, 'inner.flush() output must not leak outside any section');
    }

    /** @test */
    public function parallel_job_interleaving_still_emits_non_overlapping_sections()
    {
        // Simulates parallel execution: A and B start interleaved, then close
        // out-of-order. Each section must open AND close in one shot, never
        // straddling another section.
        $decorator = new GitLabCIDecorator($this->createMock(OutputHandler::class));

        $output = $this->captureStdoutRaw(function () use ($decorator) {
            $decorator->onJobStart('A');
            $decorator->onJobStart('B');
            $decorator->onJobSuccess('B', '1s');
            $decorator->onJobSuccess('A', '2s');
        });

        $this->assertSame(2, substr_count($output, 'section_start:'), 'Exactly two section_start markers');
        $this->assertSame(2, substr_count($output, 'section_end:'), 'Exactly two section_end markers');

        // Critical invariant: no nesting. Each section_start is followed by
        // its matching section_end before the next section_start.
        $stack = 0;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
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
        $decorator = new GitLabCIDecorator($this->createMock(OutputHandler::class));

        $output = $this->captureStdoutRaw(function () use ($decorator) {
            $decorator->onJobSuccess('job1', '1s');
            $decorator->onJobSuccess('job2', '1s');
        });

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

        $this->captureStdoutRaw(function () use ($decorator) {
            $decorator->onJobDryRun('phpcs', 'php phpcs.phar');
        });
    }

    /** @test */
    public function inner_streamed_output_lands_inside_the_section_body()
    {
        // The streaming double mimics a real inner (e.g. StreamingTextOutputHandler):
        // banner on start, raw chunk passthrough, close summary.
        $decorator = new GitLabCIDecorator(new StreamingMarkerOutputHandler());

        $output = $this->captureStdout(function () use ($decorator) {
            $decorator->onJobStart('phpstan');
            $decorator->onJobOutput('phpstan', "running...\n", false);
            $decorator->onJobSuccess('phpstan', '0.5s');
        });

        $body = $this->extractSectionBody('phpstan', $output);
        $this->assertStringContainsString('--- phpstan ---', $body);
        $this->assertStringContainsString('running...', $body);
        $this->assertStringContainsString('phpstan OK 0.5s', $body);
    }

    /**
     * @test
     *
     * The decorator's emitSection ends with:
     *
     *   if ($body !== '' && substr($body, -1) !== "\n") { echo "\n"; }
     *
     * Three boundaries to pin: body without trailing \n (exactly one \n
     * appended), body already terminated by \n (no extra \n), empty body
     * (no spurious blank line inserted before section_end).
     */
    public function emit_section_normalises_trailing_newline(): void
    {
        // Case A — body without trailing \n: the guard adds exactly one.
        $decorator = new GitLabCIDecorator(new class extends NoOpOutputHandler {
            public function onJobStart(string $jobName): void
            {
                echo "no-newline-content";
            }
        });
        $output = $this->captureStdout(function () use ($decorator) {
            $decorator->onJobStart('jobA');
            $decorator->onJobSuccess('jobA', '1s');
        });
        $this->assertStringContainsString('no-newline-content', $output);
        $this->assertMatchesRegularExpression('/no-newline-content\nsection_end:/', $output);
        $this->assertStringNotContainsString("no-newline-content\n\n", $output);

        // Case B — body already ending in \n: no extra \n appended.
        $decorator = new GitLabCIDecorator(new class extends NoOpOutputHandler {
            public function onJobStart(string $jobName): void
            {
                echo "with-newline-content\n";
            }
        });
        $output = $this->captureStdout(function () use ($decorator) {
            $decorator->onJobStart('jobB');
            $decorator->onJobSuccess('jobB', '1s');
        });
        $this->assertStringNotContainsString("with-newline-content\n\n", $output);

        // Case C — empty body: the `$body !== ''` short-circuit must prevent
        // inserting a blank line between header and section_end.
        $decorator = new GitLabCIDecorator(new class extends NoOpOutputHandler {
        });
        $output = $this->captureStdout(function () use ($decorator) {
            $decorator->onJobSuccess('jobC', '1s');
        });
        $this->assertMatchesRegularExpression('/jobC\nsection_end:/', $output);
    }

    /**
     * BUG-16 regression: `section_start` must use the timestamp captured at
     * `onJobStart`, not a `time()` reading taken inside `emitSection`. The
     * previous implementation calculated both `$start` and `$end` inside
     * `emitSection`, separated only by an `echo $body`, so GitLab always
     * rendered grouped sections as `00:00`.
     *
     * @test
     */
    public function section_start_uses_onJobStart_timestamp_not_emit_time(): void
    {
        $decorator = new GitLabCIDecorator(
            $this->createMock(OutputHandler::class),
            $this->fixedClock([100, 105])
        );

        $output = $this->captureStdoutRaw(function () use ($decorator) {
            $decorator->onJobStart('phpstan');
            $decorator->onJobSuccess('phpstan', '5s');
        });

        $this->assertMatchesRegularExpression('/section_start:100:/', $output);
        $this->assertMatchesRegularExpression('/section_end:105:/', $output);
    }

    /**
     * Decision-table for BUG-16.
     *
     * Factors: close method (success/error/skipped) × prior onJobStart (yes/no)
     * × Δtime (0s, 3s, 5s). Rows 1-3 cover the bug regression (all three
     * would render as 0 with the old code). Rows 4-5 cover the fallback
     * where the close method is invoked without a prior onJobStart.
     *
     * @test
     * @dataProvider sectionTimestampScenarios
     *
     * @param int[]  $clockValues clock returns these values in order
     * @param string $closeMethod onJobSuccess|onJobError|onJobSkipped
     * @param bool   $callStart   whether to call onJobStart before close
     */
    public function emit_section_timestamps_follow_decision_table(
        array $clockValues,
        string $closeMethod,
        bool $callStart,
        int $expectedStart,
        int $expectedEnd
    ): void {
        $decorator = new GitLabCIDecorator(
            $this->createMock(OutputHandler::class),
            $this->fixedClock($clockValues)
        );

        $output = $this->captureStdoutRaw(function () use ($decorator, $callStart, $closeMethod) {
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
        });

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
            'success after 5s with prior start'              => [[100, 105], 'onJobSuccess', true,  100, 105],
            'error after 3s with prior start'                => [[200, 203], 'onJobError',   true,  200, 203],
            'skipped at the same instant as start'           => [[300, 300], 'onJobSkipped', true,  300, 300],
            'error without prior start falls back to emit'   => [[400],      'onJobError',   false, 400, 400],
            'skipped without prior start falls back to emit' => [[500],      'onJobSkipped', false, 500, 500],
        ];
    }

    /**
     * BUG-18 regression: when `structuredFormat = true` and a job tool fails,
     * the KO section must NOT contain the tool's raw JSON output. The
     * FlowExecutor humanises the output upstream via HumanIssueFormatter
     * before invoking onJobError; this test pins the full integration so any
     * future refactor that bypasses the humanisation step would fail here.
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

        $decorator = new GitLabCIDecorator(new DashboardOutputHandler(false));
        $executor = new FlowExecutor($decorator);
        $executor->setStructuredFormat(true);

        $body = $this->captureStdout(function () use ($executor, $job) {
            $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration(false, 1)));
        });

        $this->assertStringNotContainsString('"totals":', $body, 'Raw JSON "totals" must not leak into the KO section');
        $this->assertStringNotContainsString('"files":', $body, 'Raw JSON "files" must not leak into the KO section');
        $this->assertStringNotContainsString('"messages":', $body, 'Raw JSON "messages" must not leak into the KO section');

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
     * On every hook call, the decorator must concatenate (not overwrite) the
     * inner's output into the per-job buffer. Without that, only the last
     * emission survives into the section body. We drive the OK path
     * (start + 2 outputs + success) and the KO path (start + output + error)
     * and assert every marker reaches the body in declaration order.
     */
    public function buffered_body_accumulates_all_inner_emissions_before_close(): void
    {
        $inner = new EmittingOutputHandler();
        $decorator = new GitLabCIDecorator($inner);

        $okOut = $this->captureStdout(function () use ($decorator) {
            $decorator->onJobStart('j1');
            $decorator->onJobOutput('j1', 'A', false);
            $decorator->onJobOutput('j1', 'B', false);
            $decorator->onJobSuccess('j1', '1s');
        });
        $this->assertMarkersInOrder(
            ['marker:start', 'marker:output(A)', 'marker:output(B)', 'marker:success'],
            $okOut
        );

        $decorator = new GitLabCIDecorator($inner);
        $koOut = $this->captureStdout(function () use ($decorator) {
            $decorator->onJobStart('j2');
            $decorator->onJobOutput('j2', 'X', false);
            $decorator->onJobError('j2', '1s', '');
        });
        $this->assertMarkersInOrder(
            ['marker:start', 'marker:output(X)', 'marker:error'],
            $koOut
        );
    }

    /**
     * @test
     *
     * KO body composition contract: the inner's pre-existing buffer survives
     * the onJobError call (no overwrite), the appended error content has
     * exactly one trailing \n (rtrim + explicit \n), and the error follows
     * the prior content in order.
     */
    public function ko_body_preserves_prior_buffer_and_normalises_trailing_newline(): void
    {
        $decorator = new GitLabCIDecorator(new class extends NoOpOutputHandler {
            public function onJobStart(string $jobName): void
            {
                echo "PRIOR\n";
            }
        });

        $output = $this->captureStdout(function () use ($decorator) {
            $decorator->onJobStart('j');
            $decorator->onJobError('j', '1s', "ERROR\n");
        });

        $this->assertStringContainsString('PRIOR', $output);
        $this->assertStringNotContainsString("ERROR\n\nsection_end:", $output);
        $this->assertMatchesRegularExpression('/PRIOR\nERROR\nsection_end:/', $output);
    }

    /**
     * @test
     *
     * Exception safety: even if the inner throws inside captureInner, the
     * finally block must pop the output buffer so the test runner's stdout
     * level is restored.
     */
    public function capture_inner_finally_pops_output_buffer_when_inner_throws(): void
    {
        $inner = new ThrowsOnJobStartOutputHandler();
        $decorator = new GitLabCIDecorator($inner);
        $levelBefore = ob_get_level();

        try {
            $decorator->onJobStart('exploder');
            $this->fail('expected RuntimeException to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame($inner->getExpectedMessage(), $e->getMessage());
        }

        $this->assertSame(
            $levelBefore,
            ob_get_level(),
            'finally must pop the output buffer started by captureInner even on exception'
        );
    }

    /**
     * Clock closure that returns $values in order, sticking at the last
     * value once the list is exhausted (keeps tests stable if the
     * implementation incidentally makes one extra call).
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
