<?php

declare(strict_types=1);

namespace Tests\Unit\Tools\Execution;

use Tests\Mock;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Tests\Doubles\GitStagerFake;
use Tests\Doubles\MultiProcessesExecutionFake;
use Tests\Doubles\SummaryCapturingPrinter;
use Wtyd\GitHooks\Tools\ToolsFactory;
use Wtyd\GitHooks\Utils\Printer;
use Wtyd\GitHooks\Registry\ToolRegistry;

/**
 * Applied pairwise testing strategy. See tests cases in the link https://pairwise.teremokgames.com/5fujg/
 */
class MultiProcessesExecutionTest extends UnitTestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    protected const ALL_TOOLS = 'all';

    protected $configurationFileBuilder;

    protected $toolsFactory;

    protected function setUp(): void
    {
        $this->configurationFileBuilder = new ConfigurationFileBuilder('');

        $this->toolsFactory = new ToolsFactory(new ToolRegistry());
    }

    public function oneToolFailsEachTimeDataProvider()
    {
        return [
            'Fails phpcs' => ['phpcs', ['phpcpd', 'phpcbf', 'phpmd', 'parallel-lint', 'phpstan']],
            'Fails phpcbf' => ['phpcbf', ['phpcs', 'phpcpd', 'phpmd', 'parallel-lint', 'phpstan']],
            'Fails phpmd' => ['phpmd', ['phpcs', 'phpcbf', 'phpcpd', 'parallel-lint', 'phpstan']],
            'Fails phpcpd' => ['phpcpd', ['phpcs', 'phpcbf', 'phpmd', 'parallel-lint', 'phpstan']],
            'Fails parallel-lint' => ['parallel-lint', ['phpcs', 'phpcbf', 'phpmd', 'phpcpd', 'phpstan']],
            'Fails phpstan' => ['phpstan', ['phpcs', 'phpcbf', 'phpmd', 'parallel-lint', 'phpcpd']],
        ];
    }

    /**
     * Test added to pairwise testing strategy
     * @test
     */
    function it_returns_empty_errors_when_all_tools_find_NO_errors()
    {
        $configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS, new ToolRegistry());
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, new GitStagerFake());

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertTrue($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpmd')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpcs')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpcbf')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpcpd')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpstan')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('parallel-lint')))->once();
    }

    /**
     * @test
     * @dataProvider oneToolFailsEachTimeDataProvider
     */
    function it_returns_errors_when_a_tool_finds_errors($failedTool, $successTools)
    {
        $configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS, new ToolRegistry());
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, new GitStagerFake());
        $multiProcessExecution->failedToolsByFoundedErrors([$failedTool]);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertFalse($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($failedTool, false)))->once();
        $printerMock->shouldHaveReceived()->framedErrorBlock(\Mockery::any(), \Mockery::pattern("%$failedTool fakes an error%"))->once();

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[0])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[1])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[2])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[3])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[4])))->once();
    }

    /**
     * @test
     * @dataProvider oneToolFailsEachTimeDataProvider
     */
    function it_returns_errors_when_a_tool_raise_an_exception($failedTool, $successTools)
    {
        $configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS, new ToolRegistry());
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, new GitStagerFake());
        $multiProcessExecution->failedToolsByException([$failedTool]);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertFalse($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($failedTool, false)))->once();
        $printerMock->shouldHaveReceived()->framedErrorBlock(\Mockery::any(), \Mockery::pattern("%$failedTool fakes an exception%"))->once();

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[0])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[1])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[2])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[3])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[4])))->once();
    }

     /**
     * @test
     * @dataProvider oneToolFailsEachTimeDataProvider
     * Edge case explained in the finishExecution method in MultiProcessesExecution.php
     */
    function it_returns_errors_when_a_tool_is_not_succesfully_and_has_errors_in_normal_output_instead_of_errorOutput($failedTool, $successTools)
    {
        $configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS, new ToolRegistry());
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, new GitStagerFake());
        $multiProcessExecution->setFailByFoundedErrorsInNormalOutput([$failedTool]);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertFalse($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($failedTool, false)))->once();
        $printerMock->shouldHaveReceived()->framedErrorBlock(\Mockery::any(), \Mockery::pattern("%$failedTool fakes an error in normal output%"))->once();

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[0])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[1])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[2])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[3])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[4])))->once();
    }

    public function twoToolsFailsDataProvider()
    {
        return [
            'Fails phpcs' => [
                'Failed tool' => 'phpcs',
                'Failed tool with ignore erros on exit' => 'phpcbf',
                'Way to fail' => 'failedToolsByFoundedErrors',
                'Expected error message (depends on method to fake fail)' => 'fakes an error\n',
            ],
            'Fails phpcbf' => ['phpcbf', 'phpmd', 'failedToolsByFoundedErrors', 'fakes an error\n'],
            'Fails phpmd' => ['phpmd', 'phpcpd', 'failedToolsByFoundedErrors', 'fakes an error\n'],
            'Fails phpcpd' => ['phpcpd', 'parallel-lint', 'failedToolsByFoundedErrors', 'fakes an error\n'],
            'Fails parallel-lint' => ['parallel-lint', 'phpstan', 'failedToolsByFoundedErrors', 'fakes an error\n'],
            'Fails phpstan' => ['phpstan', 'phpcs', 'failedToolsByFoundedErrors', 'fakes an error\n'],
        ];
    }

    /**
     * @test
     * @dataProvider twoToolsFailsDataProvider
     */
    function it_doesnt_set_errors_when_the_tool_finds_errors_but_ignoreErrorsOnExit_flag_is_setted_to_true(
        $failedTool,
        $failedToolWithIgnoreErrosOnExit,
        $methodToFakeFail,
        $expectedErrorMessage
    ) {
        $configurationFile = new ConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($failedToolWithIgnoreErrosOnExit, ['ignoreErrorsOnExit' => true])
                ->buildArray(),
            self::ALL_TOOLS,
            new ToolRegistry()
        );
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, new GitStagerFake());
        $multiProcessExecution->$methodToFakeFail([$failedTool, $failedToolWithIgnoreErrosOnExit]);
        $multiProcessExecution->setToolsThatMustFail([$failedTool, $failedToolWithIgnoreErrosOnExit]);


        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $regExpOfExpectedErrorMessage = "%$failedTool $expectedErrorMessage%";
        $this->assertCount(1, $errors->getErrors());
        $this->assertArrayHasKey($failedTool, $errors->getErrors());
        $this->assertMatchesRegularExpression($regExpOfExpectedErrorMessage, $errors->getErrors()[$failedTool]);


        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($failedTool, false)))->once();
        $printerMock->shouldHaveReceived()->framedErrorBlock(\Mockery::any(), \Mockery::pattern($regExpOfExpectedErrorMessage))->once();

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern(
            $this->messageRegExp($failedToolWithIgnoreErrosOnExit, false)
        ))->once();
        $regExpOfExpectedErrorMessage = "%$failedToolWithIgnoreErrosOnExit $expectedErrorMessage%";
        $printerMock->shouldHaveReceived()->framedErrorBlock(
            \Mockery::any(),
            \Mockery::pattern($regExpOfExpectedErrorMessage)
        )->once();
    }

    /** @test */
    function it_stages_files_and_reports_success_when_phpcbf_applies_fix()
    {
        $configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS, new ToolRegistry());
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);
        $gitStagerFake = new GitStagerFake();

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, $gitStagerFake);
        $multiProcessExecution->setToolsWithFixApplied(['phpcbf']);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertTrue($errors->isEmpty());
        $this->assertTrue($gitStagerFake->wasCalled());

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpcbf')))->once();
    }

    /** @test */
    function it_stages_files_when_phpcbf_applies_fix_even_if_other_tool_fails()
    {
        $configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS, new ToolRegistry());
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);
        $gitStagerFake = new GitStagerFake();

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, $gitStagerFake);
        $multiProcessExecution->setToolsWithFixApplied(['phpcbf']);
        $multiProcessExecution->failedToolsByFoundedErrors(['phpcs']);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertFalse($errors->isEmpty());
        $this->assertArrayHasKey('phpcs', $errors->getErrors());
        $this->assertArrayNotHasKey('phpcbf', $errors->getErrors());
        $this->assertTrue($gitStagerFake->wasCalled());

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpcbf')))->once();
        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp('phpcs', false)))->once();
    }

    /** @test */
    function it_skips_remaining_tools_when_a_failFast_tool_fails()
    {
        $configurationFile = new ConfigurationFile(
            $this->configurationFileBuilder
                ->setTools(['parallel-lint', 'phpstan', 'phpmd'])
                ->changeToolOption('parallel-lint', ['failFast' => true])
                ->buildArray(),
            self::ALL_TOOLS,
            new ToolRegistry()
        );
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, new GitStagerFake());
        $multiProcessExecution->failedToolsByFoundedErrors(['parallel-lint']);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertFalse($errors->isEmpty());
        $this->assertCount(1, $errors->getErrors());
        $this->assertArrayHasKey('parallel-lint', $errors->getErrors());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp('parallel-lint', false)))->once();
        $printerMock->shouldNotHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpstan')));
        $printerMock->shouldNotHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpmd')));
        $printerMock->shouldNotHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp('phpstan', false)));
        $printerMock->shouldNotHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp('phpmd', false)));
    }

    /** @test */
    function it_runs_all_tools_when_a_failFast_tool_succeeds()
    {
        $configurationFile = new ConfigurationFile(
            $this->configurationFileBuilder
                ->setTools(['parallel-lint', 'phpstan', 'phpmd'])
                ->changeToolOption('parallel-lint', ['failFast' => true])
                ->buildArray(),
            self::ALL_TOOLS,
            new ToolRegistry()
        );
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, new GitStagerFake());

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertTrue($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('parallel-lint')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpstan')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpmd')))->once();
    }

    /** @test */
    function it_does_not_skip_tools_when_a_non_failFast_tool_fails()
    {
        $configurationFile = new ConfigurationFile(
            $this->configurationFileBuilder
                ->setTools(['parallel-lint', 'phpstan', 'phpmd'])
                ->buildArray(),
            self::ALL_TOOLS,
            new ToolRegistry()
        );
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, new GitStagerFake());
        $multiProcessExecution->failedToolsByFoundedErrors(['parallel-lint']);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertFalse($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp('parallel-lint', false)))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpstan')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpmd')))->once();
    }

    /**
     * @test
     * Asserts the exact tuple of arguments passed to `summary()`. Kills the
     * batch of mutants around printSummary (L116 FalseValue, L144 ArrayItem
     * /ArrayItemRemoval, L145 Continue→break): any of them would shift the
     * counters or drop entries from the aggregated result.
     */
    function it_reports_exact_summary_counts_when_failFast_skips_remaining_tools()
    {
        $configurationFile = new ConfigurationFile(
            $this->configurationFileBuilder
                ->setTools(['parallel-lint', 'phpstan', 'phpmd'])
                ->changeToolOption('parallel-lint', ['failFast' => true])
                ->buildArray(),
            self::ALL_TOOLS,
            new ToolRegistry()
        );
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $spy = new SummaryCapturingPrinter();

        $multiProcessExecution = new MultiProcessesExecutionFake($spy, new GitStagerFake());
        $multiProcessExecution->failedToolsByFoundedErrors(['parallel-lint']);

        $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertSame(1, $spy->summaryCallCount, 'summary() must be called exactly once');
        $this->assertSame(0, $spy->lastPassed);
        $this->assertSame(1, $spy->lastTotal);
        $this->assertSame(
            [['displayName' => 'parallel-lint', 'success' => false]],
            $spy->lastFailed
        );
        $this->assertSame(
            [['displayName' => 'phpstan'], ['displayName' => 'phpmd']],
            $spy->lastSkipped
        );
    }

    /**
     * @test
     * Kills L106 TrueValue: when `handleFixApplied` succeeds, the tool must be
     * recorded as passed (`success => true`). A mutation flipping it to `false`
     * would re-classify phpcbf as failed and move it out of `passed` in the
     * summary arguments.
     */
    function it_counts_phpcbf_fix_as_passed_in_summary()
    {
        $configurationFile = new ConfigurationFile(
            $this->configurationFileBuilder
                ->setTools(['phpcbf', 'phpstan'])
                ->buildArray(),
            self::ALL_TOOLS,
            new ToolRegistry()
        );
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $spy = new SummaryCapturingPrinter();
        $multiProcessExecution = new MultiProcessesExecutionFake($spy, new GitStagerFake());
        $multiProcessExecution->setToolsWithFixApplied(['phpcbf']);

        $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertSame(1, $spy->summaryCallCount);
        $this->assertSame(2, $spy->lastPassed);
        $this->assertSame(2, $spy->lastTotal);
        $this->assertSame([], $spy->lastFailed);
        $this->assertSame([], $spy->lastSkipped);
    }

    /** @test */
    function it_reports_exact_summary_arguments_when_all_tools_pass()
    {
        $configurationFile = new ConfigurationFile(
            $this->configurationFileBuilder
                ->setTools(['parallel-lint', 'phpstan'])
                ->buildArray(),
            self::ALL_TOOLS,
            new ToolRegistry()
        );
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $spy = new SummaryCapturingPrinter();
        $multiProcessExecution = new MultiProcessesExecutionFake($spy, new GitStagerFake());

        $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertSame(1, $spy->summaryCallCount);
        $this->assertSame(2, $spy->lastPassed);
        $this->assertSame(2, $spy->lastTotal);
        $this->assertSame([], $spy->lastFailed);
        $this->assertSame([], $spy->lastSkipped);
    }

    /** @test */
    function failFast_takes_priority_over_ignoreErrorsOnExit()
    {
        $configurationFile = new ConfigurationFile(
            $this->configurationFileBuilder
                ->setTools(['parallel-lint', 'phpstan', 'phpmd'])
                ->changeToolOption('parallel-lint', ['failFast' => true, 'ignoreErrorsOnExit' => true])
                ->buildArray(),
            self::ALL_TOOLS,
            new ToolRegistry()
        );
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, new GitStagerFake());
        $multiProcessExecution->failedToolsByFoundedErrors(['parallel-lint']);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        // failFast takes priority: ignoreErrorsOnExit is set to false by ToolConfiguration
        $this->assertFalse($errors->isEmpty());
        $this->assertArrayHasKey('parallel-lint', $errors->getErrors());

        // Remaining tools should be skipped
        $printerMock->shouldNotHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpstan')));
        $printerMock->shouldNotHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpmd')));
    }

    /**
     * Run-loop liveness invariant: under any combination of success / failure /
     * timeout / fail-fast / ignore-errors flags, MultiProcessesExecution::runProcesses()
     * MUST terminate within a bounded number of iterations. None of the previous
     * outcome-asserting tests guard against a divergent loop — a mutation that
     * removes addProcessToQueue, flips hasPendingWork, or breaks the catch
     * blocks lets the do-while spin forever (>120s under Infection, reported
     * as timed-out mutants on lines 38-41 and 131-134 of the production class).
     *
     * The cap lives in MultiProcessesExecutionFake::hasPendingWork() so EVERY
     * test in this file inherits it implicitly. Without that, this single
     * adversarial test would still time out alongside the others when run
     * against a divergent mutant — Infection kills the entire PHPUnit process
     * on the first hang, ignoring any test that would have failed cleanly.
     * With the cap on the Fake, divergent mutants surface as a 'General' error
     * inside the returned Errors object (see the Fake's docblock for why);
     * this test asserts the absence of that key.
     *
     * @test
     * @dataProvider adversarialRunLoopScenarios
     *
     * @param string $scenario Human label for the assertion message
     * @param array<string,mixed> $config How to configure MultiProcessesExecutionFake
     */
    function run_loop_terminates_under_adversarial_inputs(string $scenario, array $config)
    {
        $builder = $this->configurationFileBuilder;
        if (isset($config['failFastTool'])) {
            // Mirror the existing fail-fast test setup: a 3-tool subset where
            // changeToolOption('parallel-lint', ['failFast' => true]) is known
            // to round-trip through the builder. Other tools (e.g. phpcs)
            // require otherArguments to be set explicitly via the changeToolOption
            // path and the builder doesn't fill that default.
            $builder = $builder
                ->setTools(['parallel-lint', 'phpstan', 'phpmd'])
                ->changeToolOption($config['failFastTool'], ['failFast' => true]);
        }
        $configurationFile = new ConfigurationFile(
            $builder->buildArray(),
            self::ALL_TOOLS,
            new ToolRegistry()
        );
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);
        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, new GitStagerFake());

        if (!empty($config['failedTools'])) {
            $multiProcessExecution->failedToolsByFoundedErrors($config['failedTools']);
        }
        if (!empty($config['exceptionTools'])) {
            $multiProcessExecution->failedToolsByException($config['exceptionTools']);
        }
        if (!empty($config['timeoutTools'])) {
            $multiProcessExecution->setToolsWithTimeout($config['timeoutTools']);
        }

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertArrayNotHasKey(
            'General',
            $errors->getErrors(),
            "Case '{$scenario}': runProcesses() loop did not converge within {$multiProcessExecution->iterationCap} iterations. "
                . "The error was caught by the outer Throwable handler on line 55."
        );
        $this->assertLessThanOrEqual(
            $multiProcessExecution->iterationCap,
            $multiProcessExecution->iterations,
            "Case '{$scenario}': loop spent {$multiProcessExecution->iterations} iterations (cap: {$multiProcessExecution->iterationCap})"
        );
    }

    /**
     * Adversarial inputs covering the run-loop's decision surface:
     *  - all-success: control case, the loop must complete with no incidents.
     *  - one-fails: a failed tool puts a process in runnedProcesses but the
     *    other ones must keep being polled until they all finish.
     *  - one-throws: ProcessFailedException reaches the inner catch which
     *    MUST advance numberOfRunnedProcesses (regression candidate for the
     *    CatchBlockRemoval mutation on line 38).
     *  - one-times-out: ProcessTimedOutException must move the offending tool
     *    out of runningProcesses; otherwise hasPendingWork never converges.
     *  - fail-fast-after-failure: failFastTriggered short-circuits queueing
     *    and hasPendingWork must fall through to false once running is empty.
     *  - all-fail-no-fail-fast: the loop must keep flushing every running
     *    process until counts converge even when none of them succeed.
     *
     * @return array<string, array{0: string, 1: array<string,mixed>}>
     */
    public function adversarialRunLoopScenarios(): array
    {
        return [
            'all_succeed'            => ['all_succeed',            []],
            'one_fails'              => ['one_fails',              ['failedTools' => ['phpcs']]],
            'one_throws_exception'   => ['one_throws_exception',   ['exceptionTools' => ['phpcs']]],
            'one_times_out'          => ['one_times_out',          ['timeoutTools' => ['phpcs']]],
            'fail_fast_triggered'    => ['fail_fast_triggered',    ['failedTools' => ['parallel-lint'], 'failFastTool' => 'parallel-lint']],
            'all_fail_no_fail_fast'  => ['all_fail_no_fail_fast',  ['failedTools' => ['phpcs', 'phpcbf', 'phpmd', 'phpcpd', 'parallel-lint', 'phpstan']]],
        ];
    }
}
