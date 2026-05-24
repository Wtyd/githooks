<?php

declare(strict_types=1);

namespace Tests\Doubles;

/**
 * Inner OutputHandler that throws on onJobStart, used to drive
 * exception-safety contracts (e.g. that a wrapping decorator's output
 * buffer is restored in a finally block when the inner explodes).
 */
class ThrowsOnJobStartOutputHandler extends NoOpOutputHandler
{
    private string $message;

    public function __construct(string $message = 'inner explodes during onJobStart')
    {
        $this->message = $message;
    }

    public function onJobStart(string $jobName): void
    {
        throw new \RuntimeException($this->message);
    }

    public function getExpectedMessage(): string
    {
        return $this->message;
    }
}
