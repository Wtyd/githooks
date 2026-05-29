<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tests\Unit\Output\RoutingBufferedOutput;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsConditionsHeader;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolution;
use Wtyd\GitHooks\Execution\InputFilesResolution;

/**
 * Test double that exposes the trait's emitConditionsHeader() and captures
 * everything the trait writes to text/structured channels.
 *
 * Phase 2a: the trait delegates to {@see \Wtyd\GitHooks\Output\ConditionsHeaderEmitter},
 * which type-hints `OutputInterface`. The double returns a real
 * {@see SymfonyStyle} wrapping a custom ConsoleOutputInterface that exposes
 * two {@see RoutingBufferedOutput} buffers — one for stdout (`$lines`) and one
 * for stderr (`$errLines` via `getErrorStyle()`). The arrays are bound by
 * reference so test assertions on `$double->lines` / `$double->errLines`
 * keep working.
 *
 * The guardrail flagged in the previous trait — that the new code MUST use
 * getErrorStyle() (public) instead of getErrorOutput() (protected) — is now
 * structurally enforced by typing the parameter as OutputInterface: if the
 * emitter ever regresses to getErrorOutput() it fails at compile time
 * because OutputInterface does not expose that method.
 *
 * @SuppressWarnings(PHPMD)
 */
class EmitsConditionsHeaderCommandDouble
{
    use EmitsConditionsHeader;

    /** @var array<string, mixed> */
    public array $options = [];

    /** @var string[] */
    public array $lines = [];

    /** @var string[] */
    public array $errLines = [];

    private SymfonyStyle $output;

    public function __construct()
    {
        $stdout = new RoutingBufferedOutput();
        $stderr = new RoutingBufferedOutput();
        // Re-using the routing logic but only the `lines` column matters here.
        $unusedW = [];
        $unusedI = [];
        $stdout->bindArrays($this->lines, $unusedW, $unusedI);
        $stderr->bindArrays($this->errLines, $unusedW, $unusedI);

        $consoleLike = new class ($stdout, $stderr) extends RoutingBufferedOutput implements ConsoleOutputInterface {
            private RoutingBufferedOutput $stdout;
            private RoutingBufferedOutput $stderr;

            public function __construct(RoutingBufferedOutput $stdout, RoutingBufferedOutput $stderr)
            {
                parent::__construct();
                $this->stdout = $stdout;
                $this->stderr = $stderr;
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->stderr;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                /* no-op for tests */
            }

            public function section(): \Symfony\Component\Console\Output\ConsoleSectionOutput
            {
                throw new \RuntimeException('section() not supported in test double');
            }

            public function writeln($messages, int $options = self::OUTPUT_NORMAL): void
            {
                $this->stdout->writeln($messages, $options);
            }

            public function write($messages, bool $newline = false, int $options = self::OUTPUT_NORMAL): void
            {
                $this->stdout->writeln($messages, $options);
            }
        };

        $this->output = new SymfonyStyle(new ArrayInput([]), $consoleLike);
    }

    public function option(string $name = null)
    {
        return $this->options[$name] ?? null;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function getOutput(): SymfonyStyle
    {
        return $this->output;
    }

    /**
     * @param string[]|null $expandedFlows
     */
    public function call(
        EffectiveOptionsResolution $resolution,
        ?array $expandedFlows = null,
        ?InputFilesResolution $inputFiles = null
    ): void {
        $this->emitConditionsHeader($resolution, $expandedFlows, $inputFiles);
    }
}
