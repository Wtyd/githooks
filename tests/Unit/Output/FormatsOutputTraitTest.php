<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Jobs\JobAbstract;
use Wtyd\GitHooks\Output\CI\GitHubActionsDecorator;
use Wtyd\GitHooks\Output\CI\GitLabCIDecorator;
use Wtyd\GitHooks\Output\DashboardOutputHandler;
use Wtyd\GitHooks\Output\NullOutputHandler;
use Wtyd\GitHooks\Output\ProgressOutputHandler;
use Wtyd\GitHooks\Output\StreamingTextOutputHandler;
use Wtyd\GitHooks\Utils\Printer;

/**
 * @SuppressWarnings(PHPMD)
 */
class FormatsOutputTraitTest extends TestCase
{
    private Container $container;

    /** @var array<string,string> backup of $_SERVER CI env vars */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        $this->container->bind(Printer::class, function () {
            return $this->createMock(Printer::class);
        });
        // Bind ProgressOutputHandler to a silenced variant to avoid writing to STDERR.
        $this->container->bind(ProgressOutputHandler::class, function () {
            return new ProgressOutputHandler(fopen('php://temp', 'w'));
        });

        foreach (['GITHUB_ACTIONS', 'GITLAB_CI'] as $var) {
            $this->serverBackup[$var] = getenv($var) === false ? '' : strval(getenv($var));
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $var => $value) {
            if ($value === '') {
                putenv($var);
            } else {
                putenv("$var=$value");
            }
        }
        parent::tearDown();
    }

    private function makeDouble(array $options = []): FormatsOutputCommandDouble
    {
        $double = new FormatsOutputCommandDouble();
        $double->options = $options;
        $double->laravel = $this->container;
        return $double;
    }

    private function makeExecutor(): FlowExecutor
    {
        return new FlowExecutor(new NullOutputHandler());
    }

    private function makePlan(int $processes, int $jobCount): FlowPlan
    {
        $jobs = [];
        for ($i = 0; $i < $jobCount; $i++) {
            $jobs[] = $this->createMock(JobAbstract::class);
        }

        $options = new OptionsConfiguration(false, $processes);

        return new FlowPlan('qa', $jobs, $options);
    }

    /** @test */
    public function it_uses_streaming_text_handler_for_default_text_format_single_job()
    {
        $double = $this->makeDouble(['format' => '']);
        $executor = $this->makeExecutor();
        $plan = $this->makePlan(1, 1);

        $double->callApplyFormat($executor, $plan);

        $this->assertInstanceOf(StreamingTextOutputHandler::class, $executor->getOutputHandler());
    }

    /** @test */
    public function it_uses_streaming_text_handler_for_sequential_text_format()
    {
        $double = $this->makeDouble(['format' => 'text']);
        $executor = $this->makeExecutor();
        $plan = $this->makePlan(1, 5);

        $double->callApplyFormat($executor, $plan);

        $this->assertInstanceOf(StreamingTextOutputHandler::class, $executor->getOutputHandler());
    }

    /** @test */
    public function it_uses_dashboard_handler_for_parallel_text_format_with_multiple_jobs()
    {
        $double = $this->makeDouble(['format' => 'text']);
        $executor = $this->makeExecutor();
        $plan = $this->makePlan(4, 3);

        $double->callApplyFormat($executor, $plan);

        $this->assertInstanceOf(DashboardOutputHandler::class, $executor->getOutputHandler());
    }

    /** @test */
    public function it_uses_progress_handler_for_json_format()
    {
        $double = $this->makeDouble(['format' => 'json']);
        $executor = $this->makeExecutor();
        $plan = $this->makePlan(1, 1);

        $double->callApplyFormat($executor, $plan);

        $this->assertInstanceOf(ProgressOutputHandler::class, $executor->getOutputHandler());
    }

    /** @test */
    public function it_uses_progress_handler_for_junit_format()
    {
        $double = $this->makeDouble(['format' => 'junit']);
        $executor = $this->makeExecutor();
        $plan = $this->makePlan(1, 1);

        $double->callApplyFormat($executor, $plan);

        $this->assertInstanceOf(ProgressOutputHandler::class, $executor->getOutputHandler());
    }

    /** @test */
    public function it_enables_structured_format_on_executor_for_codeclimate()
    {
        $double = $this->makeDouble(['format' => 'codeclimate']);
        $executor = $this->makeExecutor();

        $double->callApplyFormat($executor, $this->makePlan(1, 1));

        $this->assertInstanceOf(ProgressOutputHandler::class, $executor->getOutputHandler());
        $this->assertTrue($this->readStructuredFlag($executor));
    }

    /** @test */
    public function it_enables_structured_format_on_executor_for_sarif()
    {
        $double = $this->makeDouble(['format' => 'sarif']);
        $executor = $this->makeExecutor();

        $double->callApplyFormat($executor, $this->makePlan(1, 1));

        $this->assertTrue($this->readStructuredFlag($executor));
    }

    /** @test */
    public function it_does_not_enable_structured_format_for_json_or_junit()
    {
        $double = $this->makeDouble(['format' => 'json']);
        $executor = $this->makeExecutor();

        $double->callApplyFormat($executor, $this->makePlan(1, 1));

        $this->assertFalse($this->readStructuredFlag($executor));
    }

    /** @test */
    public function it_warns_on_unknown_format_and_falls_back_to_text()
    {
        $double = $this->makeDouble(['format' => 'xml']);
        $executor = $this->makeExecutor();

        $double->callApplyFormat($executor, $this->makePlan(1, 1));

        $this->assertCount(1, $double->warnings);
        $this->assertStringContainsString("Unknown format 'xml'", $double->warnings[0]);
        $this->assertInstanceOf(StreamingTextOutputHandler::class, $executor->getOutputHandler());
    }

    /** @test */
    public function it_wraps_with_github_actions_decorator_when_env_is_detected_and_no_ci_not_set()
    {
        putenv('GITHUB_ACTIONS=true');

        $double = $this->makeDouble(['format' => 'text']);
        $executor = $this->makeExecutor();

        $double->callApplyFormat($executor, $this->makePlan(1, 1));

        $this->assertInstanceOf(GitHubActionsDecorator::class, $executor->getOutputHandler());
    }

    /** @test */
    public function it_wraps_with_gitlab_decorator_when_env_is_detected()
    {
        putenv('GITLAB_CI=true');

        $double = $this->makeDouble(['format' => 'text']);
        $executor = $this->makeExecutor();

        $double->callApplyFormat($executor, $this->makePlan(1, 1));

        $this->assertInstanceOf(GitLabCIDecorator::class, $executor->getOutputHandler());
    }

    /** @test */
    public function no_ci_option_disables_ci_decorator_wrapping()
    {
        putenv('GITHUB_ACTIONS=true');

        $double = $this->makeDouble(['format' => 'text', 'no-ci' => true]);
        $executor = $this->makeExecutor();

        $double->callApplyFormat($executor, $this->makePlan(1, 1));

        $this->assertNotInstanceOf(GitHubActionsDecorator::class, $executor->getOutputHandler());
        $this->assertInstanceOf(StreamingTextOutputHandler::class, $executor->getOutputHandler());
    }

    /** @test */
    public function structured_formats_are_never_wrapped_with_ci_decorator()
    {
        putenv('GITHUB_ACTIONS=true');

        foreach (['json', 'junit', 'codeclimate', 'sarif'] as $format) {
            $double = $this->makeDouble(['format' => $format]);
            $executor = $this->makeExecutor();

            $double->callApplyFormat($executor, $this->makePlan(1, 1));

            $this->assertNotInstanceOf(
                GitHubActionsDecorator::class,
                $executor->getOutputHandler(),
                "Format '$format' must not be wrapped with CI decorator"
            );
        }
    }

    /** @test */
    public function render_formatted_result_writes_json_via_line()
    {
        $double = $this->makeDouble(['format' => 'json']);
        $result = $this->buildResult();

        $double->callRenderFormattedResult($result);

        $this->assertCount(1, $double->lines);
        $decoded = json_decode($double->lines[0], true);
        $this->assertIsArray($decoded);
        $this->assertSame('qa', $decoded['flow']);
    }

    /** @test */
    public function render_formatted_result_writes_junit_via_line()
    {
        $double = $this->makeDouble(['format' => 'junit']);
        $result = $this->buildResult();

        $double->callRenderFormattedResult($result);

        $this->assertCount(1, $double->lines);
        $this->assertStringContainsString('<?xml', $double->lines[0]);
    }

    /** @test */
    public function render_formatted_result_prints_codeclimate_to_stdout_by_default()
    {
        $default = getcwd() . '/gl-code-quality-report.json';
        @unlink($default);

        try {
            $double = $this->makeDouble(['format' => 'codeclimate', 'output' => null]);
            $double->callRenderFormattedResult($this->buildResult());

            $this->assertFileDoesNotExist($default, 'no magic default file should be created');
            $this->assertCount(1, $double->lines);
            $decoded = json_decode($double->lines[0], true);
            $this->assertIsArray($decoded, 'codeclimate payload must be valid JSON');
            $this->assertSame([], $double->infos, 'no "Report written to" info when writing to stdout');
        } finally {
            @unlink($default);
        }
    }

    /** @test */
    public function render_formatted_result_prints_sarif_to_stdout_by_default()
    {
        $default = getcwd() . '/githooks-results.sarif';
        @unlink($default);

        try {
            $double = $this->makeDouble(['format' => 'sarif', 'output' => null]);
            $double->callRenderFormattedResult($this->buildResult());

            $this->assertFileDoesNotExist($default, 'no magic default file should be created');
            $this->assertCount(1, $double->lines);
            $decoded = json_decode($double->lines[0], true);
            $this->assertSame('2.1.0', $decoded['version'] ?? null);
            $this->assertSame([], $double->infos);
        } finally {
            @unlink($default);
        }
    }

    /**
     * @test
     * @dataProvider structuredFormatsProvider
     */
    public function render_formatted_result_writes_to_custom_output_path_when_output_is_set(
        string $format,
        string $extension
    ): void {
        $custom = sys_get_temp_dir() . '/formats-output-custom-' . uniqid() . '.' . $extension;
        @unlink($custom);

        try {
            $double = $this->makeDouble(['format' => $format, 'output' => $custom]);

            $double->callRenderFormattedResult($this->buildResult());

            $this->assertFileExists($custom);
            $this->assertSame([], $double->lines, "format '$format' must not also print to stdout when --output is set");
            $this->assertStringContainsString($custom, $double->infos[0]);
        } finally {
            @unlink($custom);
        }
    }

    /** @return array<string, array{0:string,1:string}> */
    public function structuredFormatsProvider(): array
    {
        return [
            'json'        => ['json', 'json'],
            'junit'       => ['junit', 'xml'],
            'codeclimate' => ['codeclimate', 'json'],
            'sarif'       => ['sarif', 'sarif'],
        ];
    }

    /** @test */
    public function render_formatted_result_falls_back_to_text_summary_for_unknown_format()
    {
        $double = $this->makeDouble(['format' => 'unknown']);

        $double->callRenderFormattedResult($this->buildResult());

        $this->assertCount(1, $double->lines);
        $this->assertStringContainsString('Results:', $double->lines[0]);
        $this->assertStringContainsString('1/2 passed', $double->lines[0]);
    }

    private function readStructuredFlag(FlowExecutor $executor): bool
    {
        $ref = new \ReflectionClass($executor);
        $prop = $ref->getProperty('structuredFormat');
        $prop->setAccessible(true);
        return (bool) $prop->getValue($executor);
    }

    private function buildResult(): FlowResult
    {
        return new FlowResult('qa', [
            new JobResult('phpcs', true, '', '100ms'),
            new JobResult('phpstan', false, 'Line 42: error', '1s'),
        ], '1.10s', 1, 2);
    }
}
