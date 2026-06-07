<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Illuminate\Container\Container;
use Symfony\Component\Console\Output\BufferedOutput;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\MemoryBudgetState;
use Wtyd\GitHooks\Execution\TimeBudgetState;
use Wtyd\GitHooks\Output\CI\GitHubActionsDecorator;
use Wtyd\GitHooks\Output\CI\GitLabCIDecorator;
use Wtyd\GitHooks\Output\DashboardOutputHandler;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\ProgressOutputHandler;
use Wtyd\GitHooks\Output\RenderOptions;
use Wtyd\GitHooks\Output\StreamingTextOutputHandler;
use Tests\Utils\TestCase\UnitTestCase;

/**
 * Restored unit coverage for the renderer's orchestration (handler selection,
 * tool-JSON flag, CI decorator wrapping, format dispatch), ported from the
 * deleted `FormatsOutputTraitTest` onto the extracted {@see FlowResultRenderer}.
 * The live-dashboard append/redraw decision is covered separately by
 * {@see FlowResultRendererTtyModeTest}; the formatter serialization by the
 * per-formatter tests (Json/Junit/Sarif/CodeClimate/ClaudeCode).
 *
 * Tabla de factores — applyFormat() (selección de handler):
 *
 * | format family                         | processes | jobs | → handler        | wrap CI |
 * |---------------------------------------|-----------|------|------------------|---------|
 * | clean-stdout (json/junit/sarif/cc/cc-code) | *    | *    | Progress         | nunca   |
 * | text                                  | ≤1        | *    | StreamingText    | sí      |
 * | text                                  | >1        | ≤1   | StreamingText    | sí      |
 * | text                                  | >1        | >1   | Dashboard        | sí      |
 *
 * Flag tool-JSON (needsToolJsonOutput → setStructuredFormat): codeclimate/sarif → true; resto → false.
 * Wrap CI: CIEnvironment GITHUB→GitHubActionsDecorator, GITLAB→GitLabCIDecorator, NONE/noCI→sin wrap.
 */
class FlowResultRendererTest extends UnitTestCase
{
    /** @var string|false */
    private $savedGithub;
    /** @var string|false */
    private $savedGitlab;

    protected function setUp(): void
    {
        // Deterministic CI detection: this suite may itself run under CI, so
        // clear both markers and let each test opt into a specific environment.
        $this->savedGithub = getenv('GITHUB_ACTIONS');
        $this->savedGitlab = getenv('GITLAB_CI');
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('GITHUB_ACTIONS', $this->savedGithub);
        $this->restoreEnv('GITLAB_CI', $this->savedGitlab);
    }

    /** @param string|false $value */
    private function restoreEnv(string $name, $value): void
    {
        if ($value === false) {
            putenv($name);
            return;
        }
        putenv("$name=$value");
    }

    /**
     * Run applyFormat against a mocked executor/plan and return the handler the
     * renderer installed, plus whether structured (tool-JSON) mode was forced.
     *
     * @return array{handler: mixed, structured: bool|null}
     */
    private function applyFormat(string $format, int $processes, int $jobCount, bool $noCI = true): array
    {
        $captured = null;
        $structured = null;

        $executor = $this->createMock(FlowExecutor::class);
        $executor->method('setOutputHandler')->willReturnCallback(function ($h) use (&$captured) {
            $captured = $h;
        });
        $executor->method('setStructuredFormat')->willReturnCallback(function ($b) use (&$structured) {
            $structured = $b;
        });

        $planOptions = $this->createMock(OptionsConfiguration::class);
        $planOptions->method('getProcesses')->willReturn($processes);
        $planOptions->method('getReports')->willReturn([]);

        $plan = $this->createMock(FlowPlan::class);
        $plan->method('getOptions')->willReturn($planOptions);
        $plan->method('getJobs')->willReturn(array_fill(0, $jobCount, 'job'));

        $output = new BufferedOutput();
        $options = new RenderOptions($format, null, true, $noCI, false, []);

        (new FlowResultRenderer(new Container()))->applyFormat($executor, $plan, $options, $output);

        return ['handler' => $captured, 'structured' => $structured];
    }

    // ---- handler selection ----

    /**
     * @test
     * @dataProvider handlerSelectionCases
     */
    public function it_selects_the_handler_per_format_and_concurrency(
        string $format,
        int $processes,
        int $jobCount,
        string $expectedHandler
    ): void {
        $handler = $this->applyFormat($format, $processes, $jobCount)['handler'];

        $this->assertInstanceOf($expectedHandler, $handler);
    }

    /**
     * @return array<string, array{0:string,1:int,2:int,3:string}>
     */
    public function handlerSelectionCases(): array
    {
        return [
            'clean-stdout json → Progress'                  => ['json', 8, 8, ProgressOutputHandler::class],
            'clean-stdout sarif → Progress'                 => ['sarif', 8, 8, ProgressOutputHandler::class],
            'clean-stdout claude-code → Progress'           => ['claude-code', 8, 8, ProgressOutputHandler::class],
            'text · processes=1 (boundary) → Streaming'     => ['text', 1, 8, StreamingTextOutputHandler::class],
            'text · jobs=1 (boundary) → Streaming'          => ['text', 8, 1, StreamingTextOutputHandler::class],
            'text · parallel (8 proc, 8 jobs) → Dashboard'  => ['text', 8, 8, DashboardOutputHandler::class],
        ];
    }

    // ---- tool-JSON (structured) flag ----

    /**
     * @test
     * @dataProvider structuredFlagCases
     */
    public function it_forces_tool_json_output_only_for_codeclimate_and_sarif(string $format, bool $expected): void
    {
        $structured = $this->applyFormat($format, 8, 8)['structured'];

        // The renderer only calls setStructuredFormat(true) when tool-JSON is needed.
        $this->assertSame($expected, (bool) $structured);
    }

    /**
     * @return array<string, array{0:string,1:bool}>
     */
    public function structuredFlagCases(): array
    {
        return [
            'codeclimate → tool JSON on' => ['codeclimate', true],
            'sarif → tool JSON on'       => ['sarif', true],
            'json → off'                 => ['json', false],
            'text → off'                 => ['text', false],
        ];
    }

    // ---- CI decorator wrapping ----

    /** @test */
    public function text_handler_is_not_wrapped_outside_ci()
    {
        $handler = $this->applyFormat('text', 1, 1, true)['handler'];

        $this->assertNotInstanceOf(GitLabCIDecorator::class, $handler);
        $this->assertNotInstanceOf(GitHubActionsDecorator::class, $handler);
        $this->assertInstanceOf(StreamingTextOutputHandler::class, $handler);
    }

    /** @test */
    public function text_handler_is_wrapped_by_gitlab_decorator_under_gitlab_ci()
    {
        putenv('GITLAB_CI=true');

        $handler = $this->applyFormat('text', 1, 1, false)['handler'];

        $this->assertInstanceOf(GitLabCIDecorator::class, $handler);
    }

    /** @test */
    public function text_handler_is_wrapped_by_github_decorator_under_github_actions()
    {
        putenv('GITHUB_ACTIONS=true');

        $handler = $this->applyFormat('text', 1, 1, false)['handler'];

        $this->assertInstanceOf(GitHubActionsDecorator::class, $handler);
    }

    /** @test */
    public function no_ci_flag_disables_the_decorator_even_under_ci()
    {
        putenv('GITLAB_CI=true');

        $handler = $this->applyFormat('text', 1, 1, true)['handler'];

        $this->assertNotInstanceOf(GitLabCIDecorator::class, $handler);
    }

    /** @test */
    public function clean_stdout_handler_is_never_wrapped_even_under_ci()
    {
        putenv('GITLAB_CI=true');

        $handler = $this->applyFormat('json', 8, 8, false)['handler'];

        $this->assertInstanceOf(ProgressOutputHandler::class, $handler);
    }

    // ---- renderFormattedResult dispatch ----

    private function successResult(): FlowResult
    {
        return new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s', false, null, 'phpstan', 0, ['src']),
        ], '1s');
    }

    private function failureResult(): FlowResult
    {
        return new FlowResult('qa', [
            new JobResult('phpstan_src', false, 'boom', '1s', false, null, 'phpstan', 1, ['src']),
        ], '1s');
    }

    private function render(FlowResult $result, string $format): RoutingBufferedOutput
    {
        $output = new RoutingBufferedOutput();
        (new FlowResultRenderer(new Container()))->renderFormattedResult(
            $result,
            null,
            new RenderOptions($format, null, true, true, false, []),
            $output
        );
        return $output;
    }

    /** @test */
    public function claude_code_is_silent_on_success()
    {
        $output = $this->render($this->successResult(), 'claude-code');

        $this->assertSame([], $output->lines);
    }

    /** @test */
    public function claude_code_emits_a_block_payload_on_failure()
    {
        $output = $this->render($this->failureResult(), 'claude-code');

        $this->assertNotEmpty($output->lines);
    }

    /** @test */
    public function text_format_renders_the_results_summary_line()
    {
        $output = $this->render($this->successResult(), 'text');

        $this->assertStringStartsWith('Results: 1/1 passed in 1s', $output->lines[0]);
    }

    /** @test */
    public function json_format_writes_a_decodable_structured_payload()
    {
        $output = $this->render($this->successResult(), 'json');

        $payload = implode("\n", $output->lines);
        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded);
        $this->assertSame('qa', $decoded['flow']);
    }

    // ---- collectReportTargets: precedencia CLI vs config vs --no-reports ----

    /**
     * @test
     * @dataProvider reportTargetsCases
     *
     * @param array<string,string> $configReports
     * @param array<string,string> $cliReports
     * @param array<string,string> $expected
     */
    public function it_resolves_report_targets_with_cli_over_config_precedence(
        ?array $configReports,
        bool $noReports,
        array $cliReports,
        array $expected
    ): void {
        $planOptions = $configReports === null
            ? null
            : new OptionsConfiguration(false, 1, null, 'full', '', $configReports);

        $options = new RenderOptions('text', null, $noReports, true, false, $cliReports);

        $targets = (new FlowResultRenderer(new Container()))->collectReportTargets($planOptions, $options);

        $this->assertSame($expected, $targets);
    }

    /**
     * @return array<string, array{0: array<string,string>|null, 1: bool, 2: array<string,string>, 3: array<string,string>}>
     */
    public function reportTargetsCases(): array
    {
        return [
            'config only (reports on)'             => [['json' => 'c.json'], false, [], ['json' => 'c.json']],
            '--no-reports drops config'            => [['json' => 'c.json'], true, [], []],
            'null plan options, no cli'            => [null, false, [], []],
            'cli overrides config same format'     => [['json' => 'c.json'], false, ['json' => 'cli.json'], ['json' => 'cli.json']],
            'cli adds when config empty'           => [[], false, ['sarif' => 's.sarif'], ['sarif' => 's.sarif']],
            'empty cli value is skipped'           => [[], false, ['json' => ''], []],
            '--no-reports keeps cli (no-coverage)' => [['json' => 'c.json'], true, ['sarif' => 's.sarif'], ['sarif' => 's.sarif']],
        ];
    }

    // ---- text summary: threshold / budget notices (exact lines) ----

    /**
     * @param int $threshold JobResult::THRESHOLD_*
     */
    private function jobWithTimeThreshold(
        bool $success,
        ?int $exitCode,
        int $threshold,
        float $duration,
        ?int $warnAfter,
        ?int $failAfter
    ): JobResult {
        return new JobResult(
            'slow',
            $success,
            '',
            '1s',
            false,
            null,
            'phpstan',
            $exitCode,
            [],
            false,
            null,
            null,
            null,
            $duration,
            $threshold,
            null,
            $warnAfter,
            $failAfter
        );
    }

    /** @test */
    public function per_job_time_threshold_warning_renders_a_yellow_notice()
    {
        $job = $this->jobWithTimeThreshold(true, 0, JobResult::THRESHOLD_WARNED, 12.0, 10, null);

        $lines = $this->render(new FlowResult('qa', [$job], '1s'), 'text')->lines;

        $this->assertContains(
            "<fg=yellow>⚠ Job 'slow' exceeded time threshold (took 12.0s, warn-after 10s)</>",
            $lines
        );
    }

    /** @test */
    public function per_job_time_threshold_failure_renders_a_red_notice()
    {
        $job = $this->jobWithTimeThreshold(true, 0, JobResult::THRESHOLD_FAILED, 25.0, 10, 20);

        $lines = $this->render(new FlowResult('qa', [$job], '1s'), 'text')->lines;

        $this->assertContains(
            "<fg=red>✗ Job 'slow' exceeded time threshold (took 25.0s, fail-after 20s)</>",
            $lines
        );
    }

    /** @test */
    public function a_failed_job_that_also_breached_the_threshold_gets_an_indented_secondary_line()
    {
        $job = $this->jobWithTimeThreshold(false, 1, JobResult::THRESHOLD_WARNED, 12.0, 10, null);

        $lines = $this->render(new FlowResult('qa', [$job], '1s'), 'text')->lines;

        $this->assertContains(
            "   <fg=yellow>↳ also exceeded time threshold (took 12.0s, warn-after 10s)</>",
            $lines
        );
    }

    /** @test */
    public function flow_time_budget_warning_notice()
    {
        $job = $this->jobWithTimeThreshold(true, 0, JobResult::THRESHOLD_NONE, 0.0, null, null);
        $tb = new TimeBudgetState(10, 30, 12.0, true, false);

        $lines = $this->render(
            new FlowResult('qa', [$job], '1s', 0, 0, 'full', null, null, null, $tb),
            'text'
        )->lines;

        $this->assertContains(
            '<fg=yellow>⚠ Flow time-budget warning: total job time 12.0s exceeded warn-after (10s)</>',
            $lines
        );
    }

    /** @test */
    public function flow_time_budget_exceeded_notice()
    {
        $job = $this->jobWithTimeThreshold(true, 0, JobResult::THRESHOLD_NONE, 0.0, null, null);
        $tb = new TimeBudgetState(10, 20, 35.0, true, true);

        $lines = $this->render(
            new FlowResult('qa', [$job], '1s', 0, 0, 'full', null, null, null, $tb),
            'text'
        )->lines;

        $this->assertContains(
            '<fg=red>✗ Flow time-budget exceeded: total job time 35.0s, limit 20s</>',
            $lines
        );
    }

    /** @test */
    public function flow_memory_budget_warning_notice()
    {
        $job = $this->jobWithTimeThreshold(true, 0, JobResult::THRESHOLD_NONE, 0.0, null, null);
        $result = new FlowResult('qa', [$job], '1s');
        $result->setMemoryBudgetState(new MemoryBudgetState(3000, 5000, 3500, 1.0, [], true, false));

        $lines = $this->render($result, 'text')->lines;

        $this->assertContains(
            '<fg=yellow>⚠ Flow memory-budget warning: peak 3500 MB exceeded warn-above (3000 MB)</>',
            $lines
        );
    }

    /** @test */
    public function flow_memory_budget_exceeded_notice()
    {
        $job = $this->jobWithTimeThreshold(true, 0, JobResult::THRESHOLD_NONE, 0.0, null, null);
        $result = new FlowResult('qa', [$job], '1s');
        $result->setMemoryBudgetState(new MemoryBudgetState(3000, 5000, 5400, 1.0, [], true, true));

        $lines = $this->render($result, 'text')->lines;

        $this->assertContains(
            '<fg=red>✗ Flow memory-budget exceeded: peak 5400 MB, limit 5000 MB</>',
            $lines
        );
    }

    // ---- report file writing + --output + --no-reports ----

    /** @var string[] */
    private array $tmpFiles = [];

    private function tmpPath(string $ext): string
    {
        $path = sys_get_temp_dir() . '/ghtest_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $this->tmpFiles[] = $path;
        return $path;
    }

    protected function tearDownTmp(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->tmpFiles = [];
    }

    /** @test */
    public function it_writes_a_report_file_for_a_cli_report_target_and_notifies()
    {
        $path = $this->tmpPath('json');
        $output = new RoutingBufferedOutput();

        (new FlowResultRenderer(new Container()))->renderFormattedResult(
            $this->successResult(),
            null,
            new RenderOptions('json', null, false, true, false, ['json' => $path]),
            $output
        );

        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame('qa', $decoded['flow']);
        $this->assertContains("Report written to: $path", $output->infos);

        $this->tearDownTmp();
    }

    /** @test */
    public function output_path_writes_the_structured_payload_to_file_not_stdout()
    {
        $path = $this->tmpPath('json');
        $output = new RoutingBufferedOutput();

        (new FlowResultRenderer(new Container()))->renderFormattedResult(
            $this->successResult(),
            null,
            new RenderOptions('json', $path, false, true, false, []),
            $output
        );

        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame('qa', $decoded['flow']);
        // stdout payload suppressed; only the "Report written to:" notice is emitted.
        $this->assertSame([], $output->lines);
        $this->assertContains("Report written to: $path", $output->infos);

        $this->tearDownTmp();
    }

    /** @test */
    public function no_reports_skips_config_declared_report_files()
    {
        $path = $this->tmpPath('json');
        $planOptions = new OptionsConfiguration(false, 1, null, 'full', '', ['json' => $path]);
        $output = new RoutingBufferedOutput();

        (new FlowResultRenderer(new Container()))->renderFormattedResult(
            $this->successResult(),
            $planOptions,
            new RenderOptions('text', null, true, true, false, []),
            $output
        );

        $this->assertFileDoesNotExist($path);
        $this->assertSame([], $output->infos);
    }
}
