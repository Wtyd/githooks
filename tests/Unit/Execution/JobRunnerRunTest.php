<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Tests\Doubles\FileUtilsFake;
use Tests\Unit\Output\RoutingBufferedOutput;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Exception\ExitException;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobRunner;
use Wtyd\GitHooks\Execution\JobRunRequest;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Output\ConditionsHeaderEmitter;
use Wtyd\GitHooks\Output\ConfigWarningsEmitter;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\RenderOptions;

/**
 * Unit tests for `JobRunner::run()` — the Phase 2b entry point that swallows
 * the render ceremony (applyFormat → emit header → execute → emit warnings →
 * render result) and returns the process exit code.
 *
 * These tests verify the pipeline orchestration with mocked collaborators;
 * `prepare()` is exercised at the integration boundary (each case feeds a
 * parser fake so prepare()'s output flows naturally into run()).
 */
class JobRunnerRunTest extends TestCase
{
    private FileUtilsFake $fileUtils;

    private FlowPreparer $preparer;

    protected function setUp(): void
    {
        $this->fileUtils = new FileUtilsFake();
        $this->preparer = new FlowPreparer(new JobRegistry());
    }

    /** @test */
    public function failed_preparation_emits_errors_and_returns_one_without_calling_executor(): void
    {
        $parser = $this->fakeParser(function () {
            throw new ExitException('config file not found');
        });
        $output = new RoutingBufferedOutput();
        $executor = $this->createMock(FlowExecutor::class);
        $executor->expects($this->never())->method('execute');

        $runner = $this->makeRunner($parser, $executor);

        $exit = $runner->run($this->req(['jobName' => 'phpcs_src']), $output, $this->renderOpts());

        $this->assertSame(1, $exit);
        $this->assertNotEmpty($output->lines, 'error message must be emitted');
        $this->assertStringContainsString('config file not found', $output->lines[0]);
    }

    /** @test */
    public function happy_path_returns_zero_when_executor_succeeds(): void
    {
        $parser = $this->fakeParser(fn() => $this->configWithJobs(['phpcs_src' => $this->jobConfig('phpcs_src')]));
        $executor = $this->createMock(FlowExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->willReturn($this->successfulResult());

        $exit = $this->makeRunner($parser, $executor)->run(
            $this->req(['jobName' => 'phpcs_src']),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );

        $this->assertSame(0, $exit);
    }

    /** @test */
    public function executor_failure_propagates_as_exit_code_one(): void
    {
        $parser = $this->fakeParser(fn() => $this->configWithJobs(['phpcs_src' => $this->jobConfig('phpcs_src')]));
        $executor = $this->createMock(FlowExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->willReturn($this->failedResult());

        $exit = $this->makeRunner($parser, $executor)->run(
            $this->req(['jobName' => 'phpcs_src']),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );

        $this->assertSame(1, $exit);
    }

    /** @test FEAT-15: claude-code keeps exit 0 even when the job fails (stop-hook contract). */
    public function claude_code_format_returns_zero_even_when_executor_fails(): void
    {
        $parser = $this->fakeParser(fn() => $this->configWithJobs(['phpcs_src' => $this->jobConfig('phpcs_src')]));
        $executor = $this->createMock(FlowExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->willReturn($this->failedResult());

        $exit = $this->makeRunner($parser, $executor)->run(
            $this->req(['jobName' => 'phpcs_src']),
            new RoutingBufferedOutput(),
            new RenderOptions('claude-code', null, false, false, false, [])
        );

        $this->assertSame(0, $exit);
    }

    /** @test */
    public function dry_run_flag_propagates_to_the_executor(): void
    {
        $parser = $this->fakeParser(fn() => $this->configWithJobs(['phpcs_src' => $this->jobConfig('phpcs_src')]));
        $executor = $this->createMock(FlowExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->with($this->isInstanceOf(FlowPlan::class), true)
            ->willReturn($this->successfulResult());

        $this->makeRunner($parser, $executor)->run(
            $this->req(['jobName' => 'phpcs_src', 'dryRun' => true]),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );
    }

    /** @test */
    public function exception_implementing_GitHooksExceptionInterface_is_caught_and_routed_to_stderr(): void
    {
        $parser = $this->fakeParser(function () {
            // ExitException implements GitHooksExceptionInterface
            throw new ExitException('boom from parser');
        });
        $executor = $this->createMock(FlowExecutor::class);
        $output = new RoutingBufferedOutput();

        $exit = $this->makeRunner($parser, $executor)->run(
            $this->req(['jobName' => 'phpcs_src']),
            $output,
            $this->renderOpts()
        );

        $this->assertSame(1, $exit);
        $this->assertNotEmpty($output->lines);
    }

    /** @test */
    public function executor_is_configured_with_threshold_disabled_flags(): void
    {
        $parser = $this->fakeParser(fn() => $this->configWithJobs(['phpcs_src' => $this->jobConfig('phpcs_src')]));
        $executor = $this->createMock(FlowExecutor::class);
        $executor->expects($this->once())->method('setThresholdsDisabled')->with(true);
        $executor->expects($this->once())->method('setMemoryBudgetDisabled')->with(true);
        $executor->method('execute')->willReturn($this->successfulResult());

        $this->makeRunner($parser, $executor)->run(
            $this->req([
                'jobName' => 'phpcs_src',
                'timeBudgetDisabled' => true,
                'memoryBudgetDisabled' => true,
            ]),
            new RoutingBufferedOutput(),
            $this->renderOpts()
        );
    }

    private function makeRunner(ConfigurationParser $parser, FlowExecutor $executor): JobRunner
    {
        return new JobRunner(
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

    private function fakeParser(callable $parse): ConfigurationParser
    {
        return new class ($parse) extends ConfigurationParser {
            /** @var callable */
            private $impl;
            public function __construct(callable $impl)
            {
                $this->impl = $impl;
            }
            public function parse(string $filePath = ''): ConfigurationResult
            {
                return ($this->impl)($filePath);
            }
        };
    }

    /**
     * @param array<string, JobConfiguration> $jobs
     */
    private function configWithJobs(array $jobs, ?ValidationResult $validation = null): ConfigurationResult
    {
        return new ConfigurationResult(
            '/tmp/githooks.php',
            new OptionsConfiguration(false, 1),
            $jobs,
            [],
            null,
            $validation ?? new ValidationResult()
        );
    }

    private function jobConfig(string $name): JobConfiguration
    {
        return new JobConfiguration($name, 'custom', ['script' => 'true']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function req(array $overrides = []): JobRunRequest
    {
        $defaults = [
            'jobName' => 'phpcs_src',
            'configFile' => '/tmp/githooks.php',
            'cliExtraArgs' => '',
            'inputFiles' => null,
            'invocationMode' => null,
            'timeBudgetWarn' => null,
            'timeBudgetFail' => null,
            'timeBudgetDisabled' => false,
            'memoryWarnAbove' => null,
            'memoryFailAbove' => null,
            'memoryBudgetDisabled' => false,
            'statsFlag' => null,
            'cliFailFast' => null,
            'dryRun' => false,
        ];
        $merged = array_merge($defaults, $overrides);
        return new JobRunRequest(
            $merged['jobName'],
            $merged['configFile'],
            $merged['cliExtraArgs'],
            $merged['inputFiles'],
            $merged['invocationMode'],
            $merged['timeBudgetWarn'],
            $merged['timeBudgetFail'],
            $merged['timeBudgetDisabled'],
            $merged['memoryWarnAbove'],
            $merged['memoryFailAbove'],
            $merged['memoryBudgetDisabled'],
            $merged['statsFlag'],
            $merged['cliFailFast'],
            $merged['dryRun']
        );
    }

    private function renderOpts(): RenderOptions
    {
        return new RenderOptions('', null, false, false, false, []);
    }
}
