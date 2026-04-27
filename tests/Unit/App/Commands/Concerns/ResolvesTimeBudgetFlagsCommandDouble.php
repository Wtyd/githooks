<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Wtyd\GitHooks\App\Commands\Concerns\ResolvesTimeBudgetFlags;

/**
 * Test double that exposes the private resolveTimeBudgetFlags() of the trait
 * and captures everything the trait writes to stderr.
 *
 * The fake `getOutput()` deliberately exposes ONLY `getErrorStyle()` (the
 * public API used by the trait after the bugfix) and DOES NOT expose
 * `getErrorOutput()` (the protected method that triggered the original bug).
 * If the trait ever regresses to calling `getErrorOutput()` again, every test
 * will fail with "Call to undefined method", which is the desired guardrail.
 *
 * @SuppressWarnings(PHPMD)
 */
class ResolvesTimeBudgetFlagsCommandDouble
{
    use ResolvesTimeBudgetFlags;

    /** @var array<string, mixed> */
    public array $options = [];

    /** @var string[] */
    public array $errLines = [];

    /**
     * @param mixed|null $name
     * @return mixed|null
     */
    public function option(string $name = null)
    {
        return $this->options[$name] ?? null;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
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
            // Public API used by ResolvesTimeBudgetFlags after the bugfix.
            public function getErrorStyle(): object
            {
                return $this->err;
            }
        };
    }

    /**
     * @return array{warnAfter: ?int, failAfter: ?int, disabled: bool}
     */
    public function call(): array
    {
        return $this->resolveTimeBudgetFlags();
    }
}
