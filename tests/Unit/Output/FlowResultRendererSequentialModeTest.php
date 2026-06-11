<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Illuminate\Container\Container;
use Tests\Utils\TestCase\UnitTestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Output\DashboardOutputHandler;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\OutputHandler;
use Wtyd\GitHooks\Output\RenderOptions;
use Wtyd\GitHooks\Output\StreamingTextOutputHandler;

/**
 * A *multi-job* sequential flow (`processes: 1`) runs one job at a time, so on
 * an interactive terminal it gets the live dashboard (spinner + timer) just
 * like a single-job run — otherwise a long job (composer-update, infection…)
 * leaves the terminal silent for minutes, since most tools suppress their own
 * progress under a pipe. Off-TTY (pipe / CI / `--no-ansi`) keeps the streaming
 * handler so logs receive the tool's incremental output.
 *
 * Factor table (text format, processes=1, jobs>1):
 *
 * | isDecorated | stdout is tty | handler                     |
 * |-------------|---------------|-----------------------------|
 * | true        | true          | DashboardOutputHandler      |
 * | true        | false         | StreamingTextOutputHandler  | (CI / pipe forced-decorated)
 * | false       | true          | StreamingTextOutputHandler  | (--no-ansi / NO_COLOR on a TTY)
 * | false       | false         | StreamingTextOutputHandler  | (plain pipe)
 */
class FlowResultRendererSequentialModeTest extends UnitTestCase
{
    /**
     * @test
     * @dataProvider sequentialModeMatrix
     */
    function sequential_multi_job_uses_dashboard_only_in_an_interactive_terminal(
        bool $isDecorated,
        bool $stdoutIsTty,
        string $expectedHandlerClass
    ): void {
        $handler = $this->resolveSequentialHandler($isDecorated, $stdoutIsTty);

        $this->assertInstanceOf($expectedHandlerClass, $handler);
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: class-string}>
     */
    public function sequentialModeMatrix(): array
    {
        return [
            'decorated TTY → live dashboard'                  => [true, true, DashboardOutputHandler::class],
            'decorated but not a TTY (CI / pipe) → streaming' => [true, false, StreamingTextOutputHandler::class],
            '--no-ansi / NO_COLOR on a TTY → streaming'       => [false, true, StreamingTextOutputHandler::class],
            'plain pipe → streaming'                          => [false, false, StreamingTextOutputHandler::class],
        ];
    }

    private function resolveSequentialHandler(bool $isDecorated, bool $stdoutIsTty): OutputHandler
    {
        $options = new RenderOptions('text', null, true, true, false, []);

        $planOptions = $this->createMock(OptionsConfiguration::class);
        $planOptions->method('getProcesses')->willReturn(1); // sequential

        $plan = $this->createMock(FlowPlan::class);
        $plan->method('getOptions')->willReturn($planOptions);
        $plan->method('getJobs')->willReturn(['a' => [], 'b' => [], 'c' => []]); // multi-job

        $captured = null;
        $executor = $this->createMock(FlowExecutor::class);
        $executor->method('setOutputHandler')->willReturnCallback(function ($handler) use (&$captured) {
            $captured = $handler;
        });

        $output = $this->createMock(OutputInterface::class);
        $output->method('isDecorated')->willReturn($isDecorated);

        $renderer = new class (new Container(), $stdoutIsTty) extends FlowResultRenderer {
            private bool $forcedTty;

            public function __construct(Container $container, bool $forcedTty)
            {
                parent::__construct($container);
                $this->forcedTty = $forcedTty;
            }

            protected function stdoutIsTty(): bool
            {
                return $this->forcedTty;
            }
        };

        $renderer->applyFormat($executor, $plan, $options, $output);

        return $captured;
    }
}
