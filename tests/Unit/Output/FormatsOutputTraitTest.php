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
use Wtyd\GitHooks\Output\CodeClimateResultFormatter;
use Wtyd\GitHooks\Output\DashboardOutputHandler;
use Wtyd\GitHooks\Output\JsonResultFormatter;
use Wtyd\GitHooks\Output\JunitResultFormatter;
use Wtyd\GitHooks\Output\NullOutputHandler;
use Wtyd\GitHooks\Output\OutputFormats;
use Wtyd\GitHooks\Output\ProgressOutputHandler;
use Wtyd\GitHooks\Output\SarifResultFormatter;
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
    public function show_progress_option_forces_progress_handler_enabled_off_tty()
    {
        $double = $this->makeDoubleWithoutProgressBinding(['format' => 'json', 'show-progress' => true]);
        $executor = $this->makeExecutor();

        $double->callApplyFormat($executor, $this->makePlan(1, 1));

        $handler = $executor->getOutputHandler();
        $this->assertInstanceOf(ProgressOutputHandler::class, $handler);
        $this->assertTrue($this->readProgressEnabled($handler), '--show-progress must set enabled=true');
    }

    /** @test */
    public function progress_handler_is_not_forced_when_show_progress_option_is_absent()
    {
        if (stream_isatty(STDERR)) {
            $this->markTestSkipped('stderr is a TTY in this environment; cannot assert non-forced disabled state');
        }

        $double = $this->makeDoubleWithoutProgressBinding(['format' => 'json']);
        $executor = $this->makeExecutor();

        $double->callApplyFormat($executor, $this->makePlan(1, 1));

        $handler = $executor->getOutputHandler();
        $this->assertInstanceOf(ProgressOutputHandler::class, $handler);
        $this->assertFalse(
            $this->readProgressEnabled($handler),
            'without --show-progress, enabled must fall back to stream_isatty(STDERR) which is false here'
        );
    }

    /** @test */
    public function verbose_flag_no_longer_forces_progress_handler_enabled()
    {
        if (stream_isatty(STDERR)) {
            $this->markTestSkipped('stderr is a TTY in this environment; cannot assert non-forced disabled state');
        }

        // Regression: -v / --verbose used to force progress in 3.2 drafts; replaced by --show-progress.
        $double = $this->makeDoubleWithoutProgressBinding(['format' => 'json', 'verbose' => true]);
        $executor = $this->makeExecutor();

        $double->callApplyFormat($executor, $this->makePlan(1, 1));

        $handler = $executor->getOutputHandler();
        $this->assertFalse(
            $this->readProgressEnabled($handler),
            'The verbose option must no longer force progress — only --show-progress does'
        );
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
    public function render_formatted_result_creates_missing_parent_directories_for_output_path()
    {
        $baseDir = sys_get_temp_dir() . '/formats-output-nested-' . uniqid();
        $customPath = $baseDir . '/reports/subdir/qa.sarif';

        try {
            $double = $this->makeDouble(['format' => 'sarif', 'output' => $customPath]);

            $double->callRenderFormattedResult($this->buildResult());

            $this->assertFileExists($customPath, 'parent directories must be created on the fly');
            $decoded = json_decode((string) file_get_contents($customPath), true);
            $this->assertSame('2.1.0', $decoded['version'] ?? null);
        } finally {
            @unlink($customPath);
            @rmdir($baseDir . '/reports/subdir');
            @rmdir($baseDir . '/reports');
            @rmdir($baseDir);
        }
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

    /**
     * Build a double with a container that does NOT pre-bind ProgressOutputHandler,
     * so resolveProgressHandler() goes through the real construction path and
     * reads --show-progress.
     */
    private function makeDoubleWithoutProgressBinding(array $options): FormatsOutputCommandDouble
    {
        $container = new Container();
        $container->bind(Printer::class, function () {
            return $this->createMock(Printer::class);
        });

        $double = new FormatsOutputCommandDouble();
        $double->options = $options;
        $double->laravel = $container;
        return $double;
    }

    private function readProgressEnabled(ProgressOutputHandler $handler): bool
    {
        $ref = new \ReflectionClass($handler);
        $prop = $ref->getProperty('enabled');
        $prop->setAccessible(true);
        return (bool) $prop->getValue($handler);
    }

    private function buildResult(): FlowResult
    {
        return new FlowResult('qa', [
            new JobResult('phpcs', true, '', '100ms'),
            new JobResult('phpstan', false, 'Line 42: error', '1s'),
        ], '1.10s', 1, 2);
    }

    // ========================================================================
    // Multi-report (v3.3 ítem 2):
    //   - collectReportTargets: precedence CLI > config, --no-reports semantics.
    //   - renderFormattedResult: writes N report files alongside --format/--output.
    //   - formatterFor factory.
    //   - OutputFormats constants lock-in.
    // ========================================================================

    /** @test */
    public function output_formats_constants_expose_supported_and_structured_sets(): void
    {
        $this->assertSame(['json', 'junit', 'sarif', 'codeclimate'], OutputFormats::STRUCTURED);
        $this->assertSame(['text', 'json', 'junit', 'sarif', 'codeclimate'], OutputFormats::SUPPORTED);
    }

    /** @test */
    public function formatter_for_returns_correct_instance_per_format(): void
    {
        $double = $this->makeDouble();

        $this->assertInstanceOf(JsonResultFormatter::class, $double->callFormatterFor('json'));
        $this->assertInstanceOf(JunitResultFormatter::class, $double->callFormatterFor('junit'));
        $this->assertInstanceOf(SarifResultFormatter::class, $double->callFormatterFor('sarif'));
        $this->assertInstanceOf(CodeClimateResultFormatter::class, $double->callFormatterFor('codeclimate'));
    }

    /** @test */
    public function formatter_for_throws_for_unsupported_format(): void
    {
        $double = $this->makeDouble();

        $this->expectException(\InvalidArgumentException::class);
        $double->callFormatterFor('xml');
    }

    // ----- collectReportTargets ---------------------------------------------

    /** @test */
    public function collect_report_targets_returns_empty_when_no_cli_no_config(): void
    {
        $double = $this->makeDouble();

        $this->assertSame([], $double->callCollectReportTargets(null));
        $this->assertSame([], $double->callCollectReportTargets(new OptionsConfiguration()));
    }

    /** @test */
    public function collect_report_targets_reads_each_cli_flag_individually(): void
    {
        foreach (OutputFormats::STRUCTURED as $format) {
            $double = $this->makeDouble(["report-$format" => "/tmp/q.$format"]);

            $this->assertSame(
                [$format => "/tmp/q.$format"],
                $double->callCollectReportTargets(null),
                "CLI flag --report-$format must produce a single target"
            );
        }
    }

    /** @test */
    public function collect_report_targets_combines_multiple_cli_flags(): void
    {
        $double = $this->makeDouble([
            'report-sarif' => '/tmp/q.sarif',
            'report-junit' => '/tmp/q.xml',
            'report-json'  => '/tmp/q.json',
        ]);

        // Order follows OutputFormats::STRUCTURED, not the option insertion order.
        $this->assertSame([
            'json'  => '/tmp/q.json',
            'junit' => '/tmp/q.xml',
            'sarif' => '/tmp/q.sarif',
        ], $double->callCollectReportTargets(null));
    }

    /** @test */
    public function collect_report_targets_reads_config_when_no_cli_flags(): void
    {
        $options = new OptionsConfiguration(false, 1, null, 'full', '', [
            'sarif' => 'reports/q.sarif',
            'junit' => 'reports/q.xml',
        ]);
        $double = $this->makeDouble();

        $this->assertSame([
            'sarif' => 'reports/q.sarif',
            'junit' => 'reports/q.xml',
        ], $double->callCollectReportTargets($options));
    }

    /** @test */
    public function collect_report_targets_cli_overrides_config_per_format(): void
    {
        $options = new OptionsConfiguration(false, 1, null, 'full', '', [
            'sarif' => 'config.sarif',
            'junit' => 'config.xml',
        ]);
        $double = $this->makeDouble(['report-sarif' => 'cli.sarif']);

        $this->assertSame([
            'sarif' => 'cli.sarif',
            'junit' => 'config.xml',
        ], $double->callCollectReportTargets($options));
    }

    /** @test */
    public function collect_report_targets_cli_adds_format_not_present_in_config(): void
    {
        $options = new OptionsConfiguration(false, 1, null, 'full', '', ['sarif' => 'cfg.sarif']);
        $double = $this->makeDouble(['report-codeclimate' => 'cli.cc.json']);

        $this->assertSame([
            'sarif'       => 'cfg.sarif',
            'codeclimate' => 'cli.cc.json',
        ], $double->callCollectReportTargets($options));
    }

    /** @test */
    public function collect_report_targets_treats_empty_cli_value_as_unset(): void
    {
        $options = new OptionsConfiguration(false, 1, null, 'full', '', ['sarif' => 'cfg.sarif']);
        $double = $this->makeDouble(['report-sarif' => '']);

        $this->assertSame(['sarif' => 'cfg.sarif'], $double->callCollectReportTargets($options));
    }

    /** @test */
    public function collect_report_targets_no_reports_silences_config(): void
    {
        $options = new OptionsConfiguration(false, 1, null, 'full', '', [
            'sarif' => 'cfg.sarif',
            'junit' => 'cfg.xml',
        ]);
        $double = $this->makeDouble(['no-reports' => true]);

        $this->assertSame([], $double->callCollectReportTargets($options));
    }

    /** @test */
    public function collect_report_targets_no_reports_keeps_cli_flags_active(): void
    {
        $options = new OptionsConfiguration(false, 1, null, 'full', '', [
            'sarif' => 'cfg.sarif',
            'junit' => 'cfg.xml',
        ]);
        $double = $this->makeDouble([
            'no-reports'   => true,
            'report-sarif' => 'cli.sarif',
        ]);

        $this->assertSame(['sarif' => 'cli.sarif'], $double->callCollectReportTargets($options));
    }

    /** @test */
    public function collect_report_targets_no_reports_alone_returns_empty(): void
    {
        $double = $this->makeDouble(['no-reports' => true]);

        $this->assertSame([], $double->callCollectReportTargets(null));
        $this->assertSame([], $double->callCollectReportTargets(new OptionsConfiguration()));
    }

    // ----- renderFormattedResult writes report files ------------------------

    /** @test */
    public function render_formatted_result_writes_no_extra_files_when_no_targets(): void
    {
        $tmp = sys_get_temp_dir() . '/multireport-empty-' . uniqid();
        @mkdir($tmp, 0777, true);

        try {
            $double = $this->makeDouble(['format' => 'json']);
            $double->callRenderFormattedResult($this->buildResult(), null);

            // JSON went to stdout (via line()), no info "Report written" emitted.
            $this->assertCount(1, $double->lines);
            $this->assertSame([], $double->infos);
            $this->assertCount(0, glob($tmp . '/*'), 'no report files should be created');
        } finally {
            @rmdir($tmp);
        }
    }

    /** @test */
    public function render_formatted_result_writes_single_report_file_from_cli(): void
    {
        $path = sys_get_temp_dir() . '/multireport-single-' . uniqid() . '.sarif';
        @unlink($path);

        try {
            $double = $this->makeDouble([
                'format'       => 'text',
                'report-sarif' => $path,
            ]);
            $double->callRenderFormattedResult($this->buildResult(), null);

            $this->assertFileExists($path);
            $decoded = json_decode((string) file_get_contents($path), true);
            $this->assertSame('2.1.0', $decoded['version'] ?? null);

            $this->assertCount(1, $double->infos);
            $this->assertStringContainsString($path, $double->infos[0]);
        } finally {
            @unlink($path);
        }
    }

    /** @test */
    public function render_formatted_result_writes_all_four_report_files_from_cli(): void
    {
        $base = sys_get_temp_dir() . '/multireport-four-' . uniqid();
        @mkdir($base, 0777, true);
        $paths = [
            'json'        => $base . '/q.json',
            'junit'       => $base . '/q.xml',
            'sarif'       => $base . '/q.sarif',
            'codeclimate' => $base . '/q.cc.json',
        ];

        try {
            $double = $this->makeDouble(array_merge(
                ['format' => 'text'],
                array_combine(
                    array_map(fn ($f) => "report-$f", array_keys($paths)),
                    array_values($paths)
                )
            ));
            $double->callRenderFormattedResult($this->buildResult(), null);

            // 1 line for the text summary, 4 infos for the report files.
            $this->assertCount(1, $double->lines);
            $this->assertStringContainsString('Results:', $double->lines[0]);
            $this->assertCount(4, $double->infos);

            foreach ($paths as $format => $path) {
                $this->assertFileExists($path, "report file for '$format' must exist");
            }

            // JSON v2 valid
            $jsonDecoded = json_decode((string) file_get_contents($paths['json']), true);
            $this->assertSame(2, $jsonDecoded['version'] ?? null);

            // JUnit XML valid
            $xml = simplexml_load_string((string) file_get_contents($paths['junit']));
            $this->assertNotFalse($xml, 'JUnit XML must parse');

            // SARIF
            $sarif = json_decode((string) file_get_contents($paths['sarif']), true);
            $this->assertSame('2.1.0', $sarif['version'] ?? null);

            // Code Climate is a JSON array of issues
            $cc = json_decode((string) file_get_contents($paths['codeclimate']), true);
            $this->assertIsArray($cc);
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
            @rmdir($base);
        }
    }

    /** @test */
    public function render_formatted_result_uses_config_when_no_cli_flags(): void
    {
        $path = sys_get_temp_dir() . '/multireport-cfg-' . uniqid() . '.sarif';
        @unlink($path);

        $options = new OptionsConfiguration(false, 1, null, 'full', '', ['sarif' => $path]);

        try {
            $double = $this->makeDouble(['format' => 'text']);
            $double->callRenderFormattedResult($this->buildResult(), $options);

            $this->assertFileExists($path);
            $this->assertCount(1, $double->infos);
        } finally {
            @unlink($path);
        }
    }

    /** @test */
    public function render_formatted_result_emits_double_destination_for_format_and_report_same_format(): void
    {
        $path = sys_get_temp_dir() . '/multireport-double-' . uniqid() . '.sarif';
        @unlink($path);

        try {
            $double = $this->makeDouble([
                'format'       => 'sarif',
                'report-sarif' => $path,
            ]);
            $double->callRenderFormattedResult($this->buildResult(), null);

            // Stdout: SARIF via line() (--format=sarif, no --output)
            $this->assertCount(1, $double->lines);
            $stdoutDecoded = json_decode($double->lines[0], true);
            $this->assertSame('2.1.0', $stdoutDecoded['version'] ?? null);

            // Plus the file
            $this->assertFileExists($path);
            $fileDecoded = json_decode((string) file_get_contents($path), true);
            $this->assertSame('2.1.0', $fileDecoded['version'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    /** @test */
    public function render_formatted_result_emits_format_to_stdout_plus_different_report_file(): void
    {
        $sarifPath = sys_get_temp_dir() . '/multireport-mixed-' . uniqid() . '.sarif';
        @unlink($sarifPath);

        try {
            $double = $this->makeDouble([
                'format'       => 'json',
                'report-sarif' => $sarifPath,
            ]);
            $double->callRenderFormattedResult($this->buildResult(), null);

            // Stdout: JSON via line()
            $this->assertCount(1, $double->lines);
            $jsonDecoded = json_decode($double->lines[0], true);
            $this->assertSame(2, $jsonDecoded['version'] ?? null);

            // Plus the SARIF file
            $this->assertFileExists($sarifPath);
            $sarif = json_decode((string) file_get_contents($sarifPath), true);
            $this->assertSame('2.1.0', $sarif['version'] ?? null);
        } finally {
            @unlink($sarifPath);
        }
    }

    /** @test */
    public function render_formatted_result_no_reports_skips_config_writes_but_keeps_cli(): void
    {
        $cfgPath = sys_get_temp_dir() . '/multireport-cfg-skip-' . uniqid() . '.sarif';
        $cliPath = sys_get_temp_dir() . '/multireport-cli-keep-' . uniqid() . '.xml';
        @unlink($cfgPath);
        @unlink($cliPath);

        $options = new OptionsConfiguration(false, 1, null, 'full', '', ['sarif' => $cfgPath]);

        try {
            $double = $this->makeDouble([
                'format'       => 'json',
                'no-reports'   => true,
                'report-junit' => $cliPath,
            ]);
            $double->callRenderFormattedResult($this->buildResult(), $options);

            // Config target ignored.
            $this->assertFileDoesNotExist($cfgPath, 'config sarif must be skipped under --no-reports');
            // CLI target written.
            $this->assertFileExists($cliPath, 'CLI junit must still be written under --no-reports');
        } finally {
            @unlink($cfgPath);
            @unlink($cliPath);
        }
    }

    /** @test */
    public function render_formatted_result_creates_missing_parent_directories_for_report_path(): void
    {
        $base = sys_get_temp_dir() . '/multireport-nested-' . uniqid();
        $path = $base . '/deep/sub/dir/q.sarif';

        try {
            $double = $this->makeDouble([
                'format'       => 'text',
                'report-sarif' => $path,
            ]);
            $double->callRenderFormattedResult($this->buildResult(), null);

            $this->assertFileExists($path);
        } finally {
            @unlink($path);
            @rmdir($base . '/deep/sub/dir');
            @rmdir($base . '/deep/sub');
            @rmdir($base . '/deep');
            @rmdir($base);
        }
    }
}
