<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\CpuDetector;

/**
 * Forces the Windows detection path with a scripted exec response. Tests can
 * exercise the wmic fallback successfully (exitCode=0 + output) — the path
 * `WindowsCpuDetectorNoExecStub` cannot reach because it always returns 127.
 *
 * Records each command executed in $executed for assertion.
 */
class WindowsCpuDetectorScriptedExecStub extends CpuDetector
{
    /** @var array{output: array<int,string>, exit: int} */
    private array $execResponse;

    /** @var string[] */
    public array $executed = [];

    /**
     * @param array{output: array<int,string>, exit: int} $execResponse
     */
    public function __construct(array $execResponse)
    {
        $this->execResponse = $execResponse;
    }

    protected function isWindows(): bool
    {
        return true;
    }

    protected function execCommand(string $command, array &$output, int &$exitCode): void
    {
        $this->executed[] = $command;
        $output = $this->execResponse['output'];
        $exitCode = $this->execResponse['exit'];
    }
}
