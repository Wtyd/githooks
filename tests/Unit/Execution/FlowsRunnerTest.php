<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Tests\Doubles\FileUtilsFake;
use Tests\Unit\Output\RoutingBufferedOutput;
use Wtyd\Alfil\Configuration\ConfigurationParser;
use Wtyd\Alfil\Configuration\ConfigurationResult;
use Wtyd\Alfil\Configuration\FlowConfiguration;
use Wtyd\Alfil\Configuration\JobConfiguration;
use Wtyd\Alfil\Configuration\OptionsConfiguration;
use Wtyd\Alfil\Configuration\ValidationResult;
use Wtyd\Alfil\Configuration\Exception\ConfigurationFileNotFoundException;
use Wtyd\Alfil\Execution\ExecutionMode;
use Wtyd\Alfil\Execution\FlowExecutor;
use Wtyd\Alfil\Execution\FlowPreparer;
use Wtyd\Alfil\Execution\FlowResult;
use Wtyd\Alfil\Execution\FlowsRunner;
use Wtyd\Alfil\Execution\FlowsRunRequest;
use Wtyd\Alfil\Jobs\JobRegistry;
use Wtyd\Alfil\Output\ConditionsHeaderEmitter;
use Wtyd\Alfil\Output\ConfigWarningsEmitter;
use Wtyd\Alfil\Output\FlowResultRenderer;
use Wtyd\Alfil\Output\RenderOptions;

/**
 * Unit tests for FlowsRunner::prepare() and FlowsRunner::run() — the
 * multi-flow variant that handles the four invocation modes (single-flow
 * degenerate, declarative meta-flow, ad-hoc, mixed).
 */
class FlowsRunnerTest extends UnitTestCase
{
    private FileUtilsFake $fileUtils;

    private FlowPreparer $preparer;

    /**
     * Env vars BranchResolver inspects before falling back to the FileUtils fake.
     * Cleared around each test so a real CI run (where GITHUB_REF_NAME et al. are
     * set) does not override the branch the test pins via setCurrentBranch().
     */
    private const CI_BRANCH_VARS = [
        'ALFIL_BRANCH',
        'GITHOOKS_BRANCH',
        'CI_COMMIT_REF_NAME',
        'GITHUB_REF_NAME',
        'BUILDKITE_BRANCH',
        'BITBUCKET_BRANCH',
        'CIRCLE_BRANCH',
        'DRONE_COMMIT_BRANCH',
        'TRAVIS_PULL_REQUEST_BRANCH',
        'TRAVIS_BRANCH',
    ];

    protected function setUp(): void
    {
        foreach (self::CI_BRANCH_VARS as $var) {
            putenv($var);
        }
        $this->fileUtils = new FileUtilsFake();
        $this->preparer = new FlowPreparer(new JobRegistry());
    }

    protected function tearDown(): void
    {
        foreach (self::CI_BRANCH_VARS as $var) {
            putenv($var);
        }
    }

    /** @test */
    public function parser_exception_returns_failure(): void
    {
        $parser = $this->fakeParser(function () {
            throw new ConfigurationFileNotFoundException('boom');
        });

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowNames' => ['qa']]), new RoutingBufferedOutput());

        $this->assertFalse($prep->success);
        $this->assertSame(['boom'], $prep->errors);
    }

    /** @test */
    public function unknown_flow_returns_failure_with_available_lists(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src'], ['meta-all']);
        $parser = $this->fakeParser(fn() => $config);

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowNames' => ['nope']]), new RoutingBufferedOutput());

        $this->assertFalse($prep->success);
        $this->assertCount(3, $prep->errors);
        $this->assertStringContainsString("'nope' is not defined", $prep->errors[0]);
        $this->assertStringContainsString('qa', $prep->errors[1]);
        $this->assertStringContainsString('meta-all', $prep->errors[2]);
    }

    /** @test */
    public function single_normal_flow_run_marks_isSingleFlow_true(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowNames' => ['qa']]), new RoutingBufferedOutput());

        $this->assertTrue($prep->success);
        $this->assertTrue($prep->isSingleFlow);
        $this->assertFalse($prep->isDeclarative);
        $this->assertNull($prep->expandedFlows, 'single-flow runs omit the Flows: header line');
    }

    /** @test */
    public function single_meta_flow_run_marks_isDeclarative_true(): void
    {
        $config = $this->configWith(['qa', 'lint'], ['phpcs_src'], ['ci-validation'], ['ci-validation' => ['qa', 'lint']]);
        $parser = $this->fakeParser(fn() => $config);

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowNames' => ['ci-validation']]), new RoutingBufferedOutput());

        $this->assertTrue($prep->success);
        $this->assertFalse($prep->isSingleFlow);
        $this->assertTrue($prep->isDeclarative);
    }

    /** @test */
    public function ad_hoc_multi_flow_marks_both_false(): void
    {
        $config = $this->configWith(['qa', 'lint'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowNames' => ['qa', 'lint']]), new RoutingBufferedOutput());

        $this->assertTrue($prep->success);
        $this->assertFalse($prep->isSingleFlow);
        $this->assertFalse($prep->isDeclarative);
    }

    /** @test */
    public function exclude_and_only_jobs_together_fails(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare(
                $this->req([
                    'flowNames' => ['qa'],
                    'excludeJobs' => ['x'],
                    'onlyJobs' => ['y'],
                ]),
                new RoutingBufferedOutput()
            );

        $this->assertFalse($prep->success);
        $this->assertStringContainsString('cannot be used together', $prep->errors[0]);
    }

    /** @test */
    public function ignored_options_warning_emitted_for_ad_hoc_runs_with_per_flow_options(): void
    {
        $perFlowOptions = new OptionsConfiguration(true, 4);
        $config = new ConfigurationResult(
            '/tmp/githooks.php',
            new OptionsConfiguration(false, 1),
            ['phpcs_src' => new JobConfiguration('phpcs_src', 'custom', ['script' => 'true'])],
            [
                'qa'   => new FlowConfiguration('qa', ['phpcs_src'], $perFlowOptions),
                'lint' => new FlowConfiguration('lint', ['phpcs_src']),
            ],
            null,
            new ValidationResult()
        );
        $parser = $this->fakeParser(fn() => $config);
        $output = new RoutingBufferedOutput();

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowNames' => ['qa', 'lint']]), $output);

        $this->assertTrue($prep->success);
        // Routed via emitStderr; in RoutingBufferedOutput it ends in $lines.
        $emitted = implode("\n", $output->lines);
        $this->assertStringContainsString('Options declared in', $emitted);
    }

    /** @test */
    public function run_returns_zero_on_executor_success(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);
        $executor = $this->createMock(FlowExecutor::class);
        $executor->method('execute')->willReturn($this->successfulResult());

        $exit = $this->makeRunner($parser, $executor)->run(
            $this->req(['flowNames' => ['qa']]),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );

        $this->assertSame(0, $exit);
    }

    /** @test */
    public function run_returns_one_on_executor_failure(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);
        $executor = $this->createMock(FlowExecutor::class);
        $executor->method('execute')->willReturn($this->failedResult());

        $exit = $this->makeRunner($parser, $executor)->run(
            $this->req(['flowNames' => ['qa']]),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );

        $this->assertSame(1, $exit);
    }

    /** @test */
    public function run_propagates_dry_run_to_executor(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);
        $executor = $this->createMock(FlowExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->with($this->anything(), true)
            ->willReturn($this->successfulResult());

        $this->makeRunner($parser, $executor)->run(
            $this->req(['flowNames' => ['qa'], 'dryRun' => true]),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );
    }

    /** @test */
    public function monitor_flag_triggers_monitor_report(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);
        $executor = $this->createMock(FlowExecutor::class);
        $executor->method('execute')->willReturn($this->successfulResult());
        $renderer = $this->createMock(FlowResultRenderer::class);
        $renderer->expects($this->once())->method('renderMonitorReport');

        $runner = new FlowsRunner(
            $parser,
            $this->preparer,
            $this->fileUtils,
            $executor,
            $renderer,
            $this->createMock(ConditionsHeaderEmitter::class),
            $this->createMock(ConfigWarningsEmitter::class)
        );

        $runner->run(
            $this->req(['flowNames' => ['qa'], 'monitor' => true]),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );
    }

    /**
     * BUG-30: a meta-flow that declares `on` and is invoked alone must honour it
     * — the branch is resolved and the matching rule sets the execution mode,
     * exactly like a normal flow. The bug was a stray `isMetaFlow()` guard in
     * resolveBranchForSingleFlow() that dropped the branch, leaving mode at full.
     *
     * @test
     */
    public function meta_flow_on_is_honored_when_invoked_alone(): void
    {
        $validation = new ValidationResult();
        $ci = FlowConfiguration::fromArray('ci', [
            'flows' => ['qa'],
            'on'    => ['feature/*' => ['execution' => 'fast-branch'], '*' => ['execution' => 'full']],
        ], [], $validation);
        $config = new ConfigurationResult(
            '/tmp/githooks.php',
            new OptionsConfiguration(false, 1),
            ['phpcs_src' => new JobConfiguration('phpcs_src', 'custom', ['script' => 'true'])],
            ['qa' => new FlowConfiguration('qa', ['phpcs_src']), 'ci' => $ci],
            null,
            $validation
        );
        $this->fileUtils->setCurrentBranch('feature/x');

        $prep = $this->makeRunner($this->fakeParser(fn() => $config), $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowNames' => ['ci']]), new RoutingBufferedOutput());

        $this->assertTrue($prep->success);
        $this->assertSame(ExecutionMode::FAST_BRANCH, $prep->resolution->getExecutionMode());
        $this->assertSame(ExecutionMode::FAST_BRANCH, $prep->plan->getExecutionMode());
    }

    /**
     * 4.0 rename: a branch taken from the pre-4.0 GITHOOKS_BRANCH still drives
     * `on`, and the run reports an env-var-rename deprecation through the
     * config validation (so text, JSON and SARIF all carry it). ALFIL_BRANCH
     * drives it without one.
     *
     * @test
     * @dataProvider branchEnvVarProvider
     */
    public function the_branch_env_var_drives_on_and_its_old_name_is_deprecated(
        string $envVar,
        array $expectedDeprecations
    ): void {
        $validation = new ValidationResult();
        $ci = FlowConfiguration::fromArray('ci', [
            'jobs' => ['phpcs_src'],
            'on'   => ['feature/*' => ['execution' => 'fast-branch'], '*' => ['execution' => 'full']],
        ], ['phpcs_src'], $validation);
        $config = new ConfigurationResult(
            '/tmp/alfil.php',
            new OptionsConfiguration(false, 1),
            ['phpcs_src' => new JobConfiguration('phpcs_src', 'custom', ['script' => 'true'])],
            ['ci' => $ci],
            null,
            $validation
        );
        $this->fileUtils->setCurrentBranch('main');
        putenv("$envVar=feature/x");

        $prep = $this->makeRunner($this->fakeParser(fn() => $config), $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowNames' => ['ci']]), new RoutingBufferedOutput());

        $this->assertTrue($prep->success);
        $this->assertSame(ExecutionMode::FAST_BRANCH, $prep->resolution->getExecutionMode());
        $this->assertSame($expectedDeprecations, array_map(function ($d) {
            return $d->toArray();
        }, $validation->getDeprecations()));
    }

    public function branchEnvVarProvider(): array
    {
        return [
            'ALFIL_BRANCH' => ['ALFIL_BRANCH', []],
            'GITHOOKS_BRANCH (pre-4.0)' => ['GITHOOKS_BRANCH', [[
                'job' => '',
                'oldKey' => 'GITHOOKS_BRANCH',
                'newKey' => 'ALFIL_BRANCH',
                'removalVersion' => '5.0',
                'kind' => 'env-var-rename',
            ]
            ]
            ],
        ];
    }

    /**
     * AC-003: a meta-flow without `on` resolves no branch and stays full — no
     * regression, no exception, even on a feature branch.
     *
     * @test
     */
    public function meta_flow_without_on_stays_full(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src'], ['ci'], ['ci' => ['qa']]);
        $this->fileUtils->setCurrentBranch('feature/x');

        $prep = $this->makeRunner($this->fakeParser(fn() => $config), $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowNames' => ['ci']]), new RoutingBufferedOutput());

        $this->assertTrue($prep->success);
        $this->assertSame(ExecutionMode::FULL, $prep->resolution->getExecutionMode());
    }

    private function makeRunner(ConfigurationParser $parser, FlowExecutor $executor): FlowsRunner
    {
        return new FlowsRunner(
            $parser,
            $this->preparer,
            $this->fileUtils,
            $executor,
            $this->createMock(FlowResultRenderer::class),
            $this->createMock(ConditionsHeaderEmitter::class),
            $this->createMock(ConfigWarningsEmitter::class)
        );
    }

    private function successfulResult(): FlowResult
    {
        $result = $this->createMock(FlowResult::class);
        $result->method('isSuccess')->willReturn(true);
        $result->method('getJobResults')->willReturn([]);
        return $result;
    }

    private function failedResult(): FlowResult
    {
        $result = $this->createMock(FlowResult::class);
        $result->method('isSuccess')->willReturn(false);
        $result->method('getJobResults')->willReturn([]);
        return $result;
    }

    /**
     * @param string[] $normalFlowNames
     * @param string[] $jobNames
     * @param string[] $metaFlowNames
     * @param array<string, string[]> $metaFlowRefs metaName => [normalFlowName, …]
     */
    private function configWith(
        array $normalFlowNames,
        array $jobNames,
        array $metaFlowNames = [],
        array $metaFlowRefs = [],
        ?ValidationResult $validation = null
    ): ConfigurationResult {
        $flows = [];
        foreach ($normalFlowNames as $flowName) {
            $flows[$flowName] = new FlowConfiguration($flowName, $jobNames);
        }
        foreach ($metaFlowNames as $meta) {
            $refs = $metaFlowRefs[$meta] ?? $normalFlowNames;
            $flows[$meta] = new FlowConfiguration($meta, [], null, null, $refs);
        }
        $jobs = [];
        foreach ($jobNames as $jobName) {
            $jobs[$jobName] = new JobConfiguration($jobName, 'custom', ['script' => 'true']);
        }
        return new ConfigurationResult(
            '/tmp/githooks.php',
            new OptionsConfiguration(false, 1),
            $jobs,
            $flows,
            null,
            $validation ?? new ValidationResult()
        );
    }

    private function fakeParser(callable $resolver): ConfigurationParser
    {
        return new class ($resolver) extends ConfigurationParser {
            /** @var callable */
            private $resolver;

            public function __construct(callable $resolver)
            {
                $this->resolver = $resolver;
            }

            public function parse(string $configFile = ''): ConfigurationResult
            {
                $result = ($this->resolver)();
                if ($result instanceof ConfigurationResult) {
                    return $result;
                }
                throw $result;
            }
        };
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function req(array $overrides = []): FlowsRunRequest
    {
        $defaults = [
            'flowNames' => ['qa'],
            'configFile' => '/tmp/githooks.php',
            'cliFailFast' => null,
            'cliProcesses' => null,
            'excludeJobs' => [],
            'onlyJobs' => [],
            'inputFiles' => null,
            'invocationMode' => null,
            'timeBudgetWarn' => null,
            'timeBudgetFail' => null,
            'timeBudgetDisabled' => false,
            'memoryWarnAbove' => null,
            'memoryFailAbove' => null,
            'memoryBudgetDisabled' => false,
            'cliAllocator' => null,
            'cliStats' => null,
            'cliBranch' => null,
            'dryRun' => false,
            'monitor' => false,
        ];
        $m = array_merge($defaults, $overrides);
        return new FlowsRunRequest(
            $m['flowNames'],
            $m['configFile'],
            $m['cliFailFast'],
            $m['cliProcesses'],
            $m['excludeJobs'],
            $m['onlyJobs'],
            $m['inputFiles'],
            $m['invocationMode'],
            $m['timeBudgetWarn'],
            $m['timeBudgetFail'],
            $m['timeBudgetDisabled'],
            $m['memoryWarnAbove'],
            $m['memoryFailAbove'],
            $m['memoryBudgetDisabled'],
            $m['cliAllocator'],
            $m['cliStats'],
            $m['cliBranch'],
            $m['dryRun'],
            $m['monitor']
        );
    }

    private function renderOpts(): RenderOptions
    {
        return new RenderOptions('', null, false, false, false, []);
    }
}
