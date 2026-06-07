<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Output\CI\GitHubActionsDecorator;
use Wtyd\GitHooks\Output\CI\GitLabCIDecorator;
use Wtyd\GitHooks\Output\DashboardOutputHandler;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\ProgressOutputHandler;
use Wtyd\GitHooks\Output\RenderOptions;
use Wtyd\GitHooks\Output\StreamingTextOutputHandler;

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
class FlowResultRendererTest extends TestCase
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
}
