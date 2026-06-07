<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Illuminate\Container\Container;
use Tests\Utils\TestCase\UnitTestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Concerns\CapturesStdout;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Output\DashboardOutputHandler;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\RenderOptions;

/**
 * BUG-27: the parallel dashboard repeated every completed job line once per
 * refresh tick on streams that report as a TTY but do not honour the cursor
 * escapes it emits (`--no-ansi`/`NO_COLOR` on a real terminal, IDE terminals
 * whose `posix_isatty` lies). The renderer used to ignore the Symfony output
 * decoration and decide the dashboard mode from `posix_isatty(STDOUT)` alone,
 * so the only way into the safe append-only renderer was a non-TTY stream.
 *
 * The fix routes the live dashboard exclusively to `isDecorated() && tty`, so
 * decoration turned off (`--no-ansi`, `NO_COLOR`) deterministically forces the
 * append-only renderer even on a TTY, while CI (decoration forced on but
 * `posix_isatty` false) keeps degrading to append-only as before.
 *
 * The factor table this @dataProvider covers:
 *
 * | isDecorated | stdout is tty | live dashboard? |
 * |-------------|---------------|-----------------|
 * | true        | true          | yes             |
 * | true        | false         | no  (CI / pipe forced-decorated) |
 * | false       | true          | no  (--no-ansi / NO_COLOR on a TTY) ← the bug |
 * | false       | false         | no  (plain pipe) |
 *
 * Live mode is the AND of both factors; every other combination is append-only.
 */
class FlowResultRendererTtyModeTest extends UnitTestCase
{
    use CapturesStdout;

    /**
     * @test
     * @dataProvider ttyDecisionMatrix
     */
    function dashboard_mode_is_the_and_of_decoration_and_tty(bool $isDecorated, bool $stdoutIsTty, bool $expectLive): void
    {
        $handler = $this->resolveDashboardHandler($isDecorated, $stdoutIsTty);

        $this->assertInstanceOf(DashboardOutputHandler::class, $handler);

        $emitted = $this->captureStdoutRaw(function () use ($handler) {
            $handler->registerJobs(['a', 'b']);
            $handler->onJobStart('a');
            $handler->onJobStart('b');
            $handler->tick();
            $handler->onJobSuccess('a', '1.00s');
        });

        if ($expectLive) {
            $this->assertMatchesRegularExpression(
                '/\x1b\[\d+A/',
                $emitted,
                'a live dashboard must emit cursor-up escapes to redraw in place'
            );
            return;
        }

        $this->assertDoesNotMatchRegularExpression(
            '/\x1b\[\d+A/',
            $emitted,
            'append-only output must never emit cursor-up escapes (BUG-27: those are no-ops off-ANSI and cause repeated lines)'
        );
        $this->assertStringContainsString('⏳ a', $emitted, 'append-only mode prints one start marker per job');
        $this->assertStringContainsString('a - OK. Time: 1.00s', $emitted, 'append-only mode prints one completion line per job');
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool}>
     */
    public function ttyDecisionMatrix(): array
    {
        return [
            'decorated TTY → live dashboard'                  => [true, true, true],
            'decorated but not a TTY (CI / pipe) → append'    => [true, false, false],
            '--no-ansi / NO_COLOR on a TTY → append (BUG-27)' => [false, true, false],
            'plain pipe → append'                             => [false, false, false],
        ];
    }

    private function resolveDashboardHandler(bool $isDecorated, bool $stdoutIsTty): DashboardOutputHandler
    {
        $options = new RenderOptions('text', null, true, true, false, []);

        $planOptions = $this->createMock(OptionsConfiguration::class);
        $planOptions->method('getProcesses')->willReturn(8);

        $plan = $this->createMock(FlowPlan::class);
        $plan->method('getOptions')->willReturn($planOptions);
        $plan->method('getJobs')->willReturn(['a' => [], 'b' => []]);

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
