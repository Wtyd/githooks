<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Wtyd\GitHooks\App\Commands\Concerns\EmitsConditionsHeader;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolution;
use Wtyd\GitHooks\Execution\InputFilesResolution;

/**
 * Test double that exposes the private emitConditionsHeader() of the trait
 * and captures everything the trait writes to text/structured channels.
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

    public function option(string $name = null)
    {
        return $this->options[$name] ?? null;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function getOutput(): object
    {
        $errLinesRef = &$this->errLines;
        $errOutput = new class ($errLinesRef) {
            /** @var string[] */
            private array $sink;
            public function __construct(array &$sink)
            {
                $this->sink = &$sink;
            }
            public function writeln(string $message): void
            {
                $this->sink[] = $message;
            }
        };
        return new class ($errOutput) {
            private object $err;
            public function __construct(object $err)
            {
                $this->err = $err;
            }
            public function getErrorOutput(): object
            {
                return $this->err;
            }
        };
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
