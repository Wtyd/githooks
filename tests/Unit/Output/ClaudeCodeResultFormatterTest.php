<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\TimeBudgetState;
use Wtyd\GitHooks\Output\ClaudeCodeResultFormatter;

class ClaudeCodeResultFormatterTest extends TestCase
{
    private function formatter(): ClaudeCodeResultFormatter
    {
        return new ClaudeCodeResultFormatter();
    }

    /** @test F1 all-pass: silent, empty stdout so the agent is not blocked. */
    function it_returns_empty_string_when_every_job_passes()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, 'no errors', '1s', false, null, 'phpstan', 0),
            new JobResult('phpcs_src', true, '', '1s', false, null, 'phpcs', 0),
        ], '2s');

        $this->assertSame('', $this->formatter()->format($result));
    }

    /** @test F2=1: a single failed job yields a block decision with its output. */
    function it_emits_block_decision_with_single_failed_job_output()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, 'src/User.php:14 method not found', '1s', false, null, 'phpstan', 1),
        ], '1s');

        $data = json_decode($this->formatter()->format($result), true);

        $this->assertSame('block', $data['decision']);
        $this->assertSame("## phpstan_src\nsrc/User.php:14 method not found", $data['reason']);
    }

    /** @test F2>=2 / AC-003: the reason aggregates every failed job, in run order, `\n\n`-separated. */
    function it_aggregates_all_failed_jobs_into_the_reason_in_order()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, 'stan error', '1s', false, null, 'phpstan', 1),
            new JobResult('phpmd_src', false, 'md error', '1s', false, null, 'phpmd', 1),
        ], '2s');

        $data = json_decode($this->formatter()->format($result), true);

        $this->assertSame("## phpstan_src\nstan error\n\n## phpmd_src\nmd error", $data['reason']);
    }

    /** @test Only failed jobs reach the reason; passing jobs are not listed. */
    function it_excludes_passing_jobs_from_the_reason()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, 'all good', '1s', false, null, 'phpstan', 0),
            new JobResult('phpmd_src', false, 'md error', '1s', false, null, 'phpmd', 1),
        ], '2s');

        $data = json_decode($this->formatter()->format($result), true);

        $this->assertSame("## phpmd_src\nmd error", $data['reason']);
    }

    /** @test F4: skipped jobs are excluded from the reason even if flagged unsuccessful. */
    function it_excludes_skipped_jobs_from_the_reason()
    {
        $result = new FlowResult('qa', [
            // skipped=true (10th arg), skipReason set.
            new JobResult('phpunit', false, 'was skipped', '0s', false, null, 'phpunit', null, [], true, 'no files'),
            new JobResult('phpmd_src', false, 'md error', '1s', false, null, 'phpmd', 1),
        ], '1s');

        $data = json_decode($this->formatter()->format($result), true);

        $this->assertSame("## phpmd_src\nmd error", $data['reason']);
    }

    /** @test F3 / AC-004: ANSI is stripped and quotes/newlines/tabs survive a JSON round-trip. */
    function it_strips_ansi_and_escapes_conflicting_characters()
    {
        $rawOutput = "\033[31mError\033[0m: \"quoted\"\n\twith tab";

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, $rawOutput, '1s', false, null, 'phpstan', 1),
        ], '1s');

        $json = $this->formatter()->format($result);

        // The payload is a single line of valid JSON (no raw control bytes).
        $this->assertStringNotContainsString("\033", $json);
        $data = json_decode($json, true);
        $this->assertNotNull($data, 'payload must be parseable JSON');
        $this->assertSame("## phpstan_src\nError: \"quoted\"\n\twith tab", $data['reason']);
    }

    /** @test Budget-fail with all jobs passing still blocks, with the budget reason. */
    function it_reports_the_budget_reason_when_the_flow_fails_on_time_budget_only()
    {
        $timeBudget = new TimeBudgetState(null, 30, 45.0, false, true);

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, 'ok', '45s', false, null, 'phpstan', 0),
        ], '45s', 0, 0, 'full', null, null, null, $timeBudget);

        $data = json_decode($this->formatter()->format($result), true);

        $this->assertSame('block', $data['decision']);
        $this->assertStringContainsString('time-budget exceeded', $data['reason']);
        $this->assertStringContainsString('limit 30s', $data['reason']);
    }

    /** @test The payload is a single compact line (a stop hook reads stdout as one JSON value). */
    function it_emits_a_single_line_payload()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, "line one\nline two", '1s', false, null, 'phpstan', 1),
        ], '1s');

        $json = $this->formatter()->format($result);

        // No literal newline outside the JSON-escaped string.
        $this->assertStringNotContainsString("\n", $json);
    }
}
