<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Tests\Doubles\FileUtilsFake;
use Tests\Unit\Output\RoutingBufferedOutput;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Exception\ExitException;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\FlowRunner;
use Wtyd\GitHooks\Execution\FlowRunRequest;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Output\ConditionsHeaderEmitter;
use Wtyd\GitHooks\Output\ConfigWarningsEmitter;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\RenderOptions;

/**
 * Unit tests for FlowRunner::prepare() and FlowRunner::run() — the
 * pure-orchestration entry point that replaced the FlowCommand::handle()
 * body in Phase 2c.
 */
class FlowRunnerTest extends UnitTestCase
{
    private FileUtilsFake $fileUtils;

    private FlowPreparer $preparer;

    protected function setUp(): void
    {
        $this->fileUtils = new FileUtilsFake();
        $this->preparer = new FlowPreparer(new JobRegistry());
    }

    /** @test */
    public function parser_exception_is_returned_as_failure(): void
    {
        $parser = $this->fakeParser(function () {
            throw new ExitException('boom from parser');
        });

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowName' => 'qa']));

        $this->assertFalse($prep->success);
        $this->assertSame(['boom from parser'], $prep->errors);
    }

    /** @test */
    public function legacy_config_returns_failure_with_help_message(): void
    {
        $legacy = ConfigurationResult::legacy([], '/tmp/githooks.yml', new ValidationResult());
        $parser = $this->fakeParser(fn() => $legacy);

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowName' => 'qa']));

        $this->assertFalse($prep->success);
        $this->assertCount(2, $prep->errors);
        $this->assertStringContainsString('requires v3', $prep->errors[0]);
    }

    /** @test */
    public function validation_errors_are_returned_as_failure(): void
    {
        $validation = new ValidationResult();
        $validation->addError('jobs.foo.paths is required');
        $config = $this->configWith(['qa'], [], $validation);
        $parser = $this->fakeParser(fn() => $config);

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowName' => 'qa']));

        $this->assertFalse($prep->success);
        $this->assertSame(['jobs.foo.paths is required'], $prep->errors);
    }

    /** @test */
    public function unknown_flow_returns_failure_with_available_list(): void
    {
        $config = $this->configWith(['qa', 'lint'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowName' => 'nonexistent']));

        $this->assertFalse($prep->success);
        $this->assertCount(2, $prep->errors);
        $this->assertStringContainsString("'nonexistent' is not defined", $prep->errors[0]);
        $this->assertStringContainsString('qa, lint', $prep->errors[1]);
    }

    /** @test */
    public function exclude_and_only_jobs_together_fails(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req([
                'flowName' => 'qa',
                'excludeJobs' => ['phpcs_src'],
                'onlyJobs' => ['phpstan_src'],
            ]));

        $this->assertFalse($prep->success);
        $this->assertStringContainsString('cannot be used together', $prep->errors[0]);
    }

    /** @test */
    public function happy_path_returns_plan_and_resolution(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);

        $prep = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))
            ->prepare($this->req(['flowName' => 'qa']));

        $this->assertTrue($prep->success);
        $this->assertNotNull($prep->plan);
        $this->assertNotNull($prep->resolution);
        $this->assertSame($config, $prep->config);
    }

    /** @test */
    public function run_returns_zero_when_executor_succeeds(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);
        $executor = $this->createMock(FlowExecutor::class);
        $executor->method('execute')->willReturn($this->successfulResult());

        $exit = $this->makeRunner($parser, $executor)->run(
            $this->req(['flowName' => 'qa']),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );

        $this->assertSame(0, $exit);
    }

    /** @test */
    public function run_returns_one_when_executor_fails(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);
        $executor = $this->createMock(FlowExecutor::class);
        $executor->method('execute')->willReturn($this->failedResult());

        $exit = $this->makeRunner($parser, $executor)->run(
            $this->req(['flowName' => 'qa']),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );

        $this->assertSame(1, $exit);
    }

    /** @test */
    public function run_propagates_dry_run_flag_to_executor(): void
    {
        $config = $this->configWith(['qa'], ['phpcs_src']);
        $parser = $this->fakeParser(fn() => $config);
        $executor = $this->createMock(FlowExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->with($this->anything(), true)
            ->willReturn($this->successfulResult());

        $this->makeRunner($parser, $executor)->run(
            $this->req(['flowName' => 'qa', 'dryRun' => true]),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );
    }

    /** @test */
    public function failed_preparation_emits_errors_to_output(): void
    {
        $parser = $this->fakeParser(function () {
            throw new ExitException('parse boom');
        });
        $output = new RoutingBufferedOutput();

        $exit = $this->makeRunner($parser, $this->createMock(FlowExecutor::class))->run(
            $this->req(['flowName' => 'qa']),
            $output,
            $this->renderOpts()
        );

        $this->assertSame(1, $exit);
        $this->assertNotEmpty($output->lines);
        $this->assertStringContainsString('parse boom', $output->lines[0]);
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

        $runner = new FlowRunner(
            $parser,
            $this->preparer,
            $this->fileUtils,
            $executor,
            $renderer,
            $this->createMock(ConditionsHeaderEmitter::class),
            $this->createMock(ConfigWarningsEmitter::class)
        );

        $runner->run(
            $this->req(['flowName' => 'qa', 'monitor' => true]),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );
    }

    private function makeRunner(ConfigurationParser $parser, FlowExecutor $executor): FlowRunner
    {
        return new FlowRunner(
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
     * @param string[] $flowNames
     * @param string[] $jobNames
     */
    private function configWith(array $flowNames, array $jobNames, ?ValidationResult $validation = null): ConfigurationResult
    {
        $flows = [];
        foreach ($flowNames as $flowName) {
            $flows[$flowName] = new FlowConfiguration($flowName, $jobNames);
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
    private function req(array $overrides = []): FlowRunRequest
    {
        $defaults = [
            'flowName' => 'qa',
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
        return new FlowRunRequest(
            $m['flowName'],
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
