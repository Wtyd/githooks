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
 * The fake `getOutput()` deliberately exposes ONLY `getErrorStyle()` (the
 * public SymfonyStyle API used by the trait) and DOES NOT expose
 * `getErrorOutput()` (the protected OutputStyle method that triggered the
 * original bug — it raised "Call to protected method" at runtime). If the
 * trait ever regresses to calling `getErrorOutput()`, every test that
 * exercises the structured + show-progress path will fail loudly with
 * "Call to undefined method", which is the desired guardrail.
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
        $errorStyle = new class ($errLinesRef) {
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
        return new class ($errorStyle) {
            private object $err;
            public function __construct(object $err)
            {
                $this->err = $err;
            }
            // Public API used by EmitsConditionsHeader after the bugfix.
            public function getErrorStyle(): object
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
