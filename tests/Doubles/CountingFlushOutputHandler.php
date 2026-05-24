<?php

declare(strict_types=1);

namespace Tests\Doubles;

/**
 * Inner OutputHandler that counts flush() invocations and emits a fixed
 * payload on each one.
 *
 * Used to test that wrapping decorators DO call inner->flush() exactly the
 * right number of times even when the wrapper captures and discards the
 * inner output (so a plain "assert no output" check is not enough).
 */
class CountingFlushOutputHandler extends NoOpOutputHandler
{
    public int $flushCalls = 0;

    private string $payload;

    public function __construct(string $payload = "inner flush payload\n")
    {
        $this->payload = $payload;
    }

    public function flush(): void
    {
        $this->flushCalls++;
        echo $this->payload;
    }
}
