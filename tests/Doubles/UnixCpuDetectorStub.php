<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\CpuDetector;

/**
 * Forces the Unix detection path and scripts `exec()` + /proc/cpuinfo responses.
 *
 * Usage:
 *   $stub = new UnixCpuDetectorStub([
 *       'nproc 2>/dev/null' => ['output' => ['12'], 'exit' => 0],
 *   ], $procCount = 0);
 */
class UnixCpuDetectorStub extends CpuDetector
{
    /** @var array<string, array{output: array<int,string>, exit: int}> */
    private array $execResponses;

    private int $procCpuinfoCount;

    /** @var string[] */
    public array $executed = [];

    /**
     * @param array<string, array{output: array<int,string>, exit: int}> $execResponses
     */
    public function __construct(array $execResponses, int $procCpuinfoCount = 0)
    {
        $this->execResponses = $execResponses;
        $this->procCpuinfoCount = $procCpuinfoCount;
    }

    protected function isWindows(): bool
    {
        return false;
    }

    protected function execCommand(string $command, array &$output, int &$exitCode): void
    {
        $this->executed[] = $command;
        if (isset($this->execResponses[$command])) {
            $output = $this->execResponses[$command]['output'];
            $exitCode = $this->execResponses[$command]['exit'];
            return;
        }
        $output = [];
        $exitCode = 127;
    }

    protected function readProcCpuinfoCount(): int
    {
        return $this->procCpuinfoCount;
    }
}
