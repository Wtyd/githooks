<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Wtyd\GitHooks\App\Commands\Concerns\ResolvesAllocatorFlag;

/**
 * @SuppressWarnings(PHPMD)
 */
class ResolvesAllocatorFlagCommandDouble
{
    use ResolvesAllocatorFlag;

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
            public function getErrorStyle(): object
            {
                return $this->err;
            }
        };
    }

    public function call(): ?string
    {
        return $this->resolveAllocatorFlag();
    }
}
