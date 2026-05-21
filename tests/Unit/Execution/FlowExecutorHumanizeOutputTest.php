<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\OutputHandlerSpy;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Jobs\PhpstanJob;

/**
 * BUG-18: covers the decision table of the humanisation step in
 * `FlowExecutor::buildResult`.
 *
 * Factors:
 *   - structuredFormat: true / false
 *   - Parser registered for the job type: yes (phpstan, …) / no (local-script)
 *
 * Invariants under test for every row:
 *   - `JobResult.output` is ALWAYS the raw process output (never humanised).
 *     The file-based formatters (JsonResultFormatter / JunitResultFormatter /
 *     SarifResultFormatter / CodeClimateResultFormatter) all consume that
 *     field and must keep getting the JSON they parse.
 *   - The OutputHandler sees the humanised version ONLY when both
 *     structuredFormat = true AND a parser is registered for the job type;
 *     otherwise it sees the raw output (unchanged from pre-BUG-18 behaviour).
 */
class FlowExecutorHumanizeOutputTest extends TestCase
{
    /**
     * Row A — structuredFormat=true, parser registered.
     * onJobError receives the human listing; JobResult.output stays JSON.
     *
     * @test
     */
    public function row_a_structured_with_parser_humanises_for_handler_but_keeps_raw_in_jobresult(): void
    {
        $rawJson = '{"totals":{"errors":1},"files":{"src/Foo.php":'
            . '{"errors":1,"messages":[{"message":"Class Foo not found.","line":42,'
            . '"identifier":"class.notFound"}]}}}';

        $job = $this->phpstanJobEmitting($rawJson);

        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);
        $executor->setStructuredFormat(true);

        $result = $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration(false, 1)));

        $this->assertCount(1, $spy->errorJobs);
        $handlerOutput = $spy->errorJobs[0]['output'];

        // Humanised content reached the handler.
        $this->assertStringContainsString('src/Foo.php', $handlerOutput);
        $this->assertStringContainsString('line 42', $handlerOutput);
        $this->assertStringContainsString('[class.notFound]', $handlerOutput);
        $this->assertStringNotContainsString('"totals":', $handlerOutput);

        // JobResult preserves the raw JSON for file-based formatters.
        $jobResult = $result->getJobResults()[0];
        $this->assertStringContainsString('"totals":', $jobResult->getOutput());
        $this->assertStringContainsString('"files":', $jobResult->getOutput());
    }

    /**
     * Row B — structuredFormat=true, NO parser for jobType (local-script).
     * Falls back to the raw output for both the handler and the JobResult.
     *
     * @test
     */
    public function row_b_structured_without_parser_falls_back_to_raw_on_both_paths(): void
    {
        $raw = "custom failure: missing config\nexpected file not found";
        $job = $this->localScriptJobEmitting($raw);

        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);
        $executor->setStructuredFormat(true);

        $result = $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration(false, 1)));

        $this->assertCount(1, $spy->errorJobs);
        $this->assertStringContainsString('custom failure', $spy->errorJobs[0]['output']);
        $this->assertStringContainsString('custom failure', $result->getJobResults()[0]->getOutput());
    }

    /**
     * Row C — structuredFormat=false, parser registered.
     * No humanisation; the handler sees the raw output exactly as the JobResult does.
     *
     * @test
     */
    public function row_c_unstructured_with_parser_does_not_humanise(): void
    {
        // With structuredFormat=false the tool would emit its native human
        // output, not JSON. Simulate that — and confirm the executor does
        // not invoke the formatter (which would otherwise fall back to raw
        // anyway, but we want to guarantee the structuredFormat guard short-
        // circuits BEFORE invoking the formatter).
        $raw = " ------ ------- \n  Line   src/Foo.php\n  42     Class Foo not found.\n";
        $job = $this->phpstanJobEmitting($raw);

        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);
        // structuredFormat stays at its default (false).

        $result = $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration(false, 1)));

        $this->assertCount(1, $spy->errorJobs);
        $this->assertSame(
            $result->getJobResults()[0]->getOutput(),
            $spy->errorJobs[0]['output'],
            'When structuredFormat is off the handler must receive the raw output unchanged'
        );
        $this->assertStringContainsString('Class Foo not found.', $spy->errorJobs[0]['output']);
    }

    /**
     * Row D — structuredFormat=false, no parser for jobType.
     * Same as C: raw on both paths.
     *
     * @test
     */
    public function row_d_unstructured_without_parser_does_not_humanise(): void
    {
        $raw = "plain failure message\n";
        $job = $this->localScriptJobEmitting($raw);

        $spy = new OutputHandlerSpy();
        $executor = new FlowExecutor($spy);

        $result = $executor->execute(new FlowPlan('test', [$job], new OptionsConfiguration(false, 1)));

        $this->assertCount(1, $spy->errorJobs);
        $this->assertSame(
            $result->getJobResults()[0]->getOutput(),
            $spy->errorJobs[0]['output']
        );
        $this->assertStringContainsString('plain failure message', $spy->errorJobs[0]['output']);
    }

    /**
     * Returns a PhpstanJob subclass whose `buildCommand()` produces $raw on
     * stdout and exits 1, irrespective of the real `applyStructuredOutputFormat()`
     * the executor invokes when structuredFormat=true (those args would feed a
     * real phpstan binary; here we short-circuit with sh).
     */
    private function phpstanJobEmitting(string $raw): PhpstanJob
    {
        $job = new class (new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']])) extends PhpstanJob {
            public string $rawPayload = '';
            public function buildCommand(): string
            {
                $b64 = base64_encode($this->rawPayload);
                return "sh -c 'echo {$b64} | base64 -d; exit 1'";
            }
        };
        $job->rawPayload = $raw;
        return $job;
    }

    /**
     * Returns a CustomJob (type=local-script — not in ToolOutputParserRegistry)
     * that emits $raw and exits 1.
     */
    private function localScriptJobEmitting(string $raw): CustomJob
    {
        $b64 = base64_encode($raw);
        return new CustomJob(new JobConfiguration(
            'my_local_script',
            'local-script',
            ['script' => "sh -c 'echo {$b64} | base64 -d; exit 1'"]
        ));
    }
}
