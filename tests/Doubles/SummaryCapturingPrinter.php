<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\Printer;

/**
 * Printer double that captures the exact arguments passed to `summary()`.
 * Used when mockery spies are insufficient for deep equality checks on
 * nested arrays.
 */
class SummaryCapturingPrinter extends Printer
{
    public int $summaryCallCount = 0;

    public int $lastPassed = -1;

    public int $lastTotal = -1;

    /** @var array<int, array<string, mixed>> */
    public array $lastFailed = [];

    /** @var array<int, array<string, mixed>> */
    public array $lastSkipped = [];

    public function __construct()
    {
        // Intentionally skip parent constructor — no OutputInterface needed.
    }

    public function summary(int $passed, int $total, array $toolResults, array $skippedResults = []): void
    {
        $this->summaryCallCount++;
        $this->lastPassed = $passed;
        $this->lastTotal = $total;
        $this->lastFailed = $toolResults;
        $this->lastSkipped = $skippedResults;
    }

    public function line(string $message = '', int $verbosity = 0): void
    {
    }

    public function generalInfo(string $message, int $verbosity = 0): void
    {
    }

    public function ttyClean(): void
    {
    }

    public function resultSuccess(string $message): void
    {
    }

    public function resultError(string $message): void
    {
    }

    public function framedErrorBlock(string $title, string $body): void
    {
    }

    public function tool(string $message): void
    {
    }

    public function info(string $message): void
    {
    }

    public function success(string $message): void
    {
    }

    public function error(string $message): void
    {
    }

    public function comment(string $message): void
    {
    }

    public function warning(string $message): void
    {
    }
}
