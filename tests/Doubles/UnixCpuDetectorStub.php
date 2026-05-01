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

    /** @var array<string, ?string> path => contents (null when "unreadable") */
    private array $fileResponses;

    /** @var string[] */
    public array $executed = [];

    /**
     * @param array<string, array{output: array<int,string>, exit: int}> $execResponses
     * @param array<string, ?string> $fileResponses path => contents (null = unreadable).
     *        Anything not listed is reported as unreadable.
     */
    public function __construct(array $execResponses, int $procCpuinfoCount = 0, array $fileResponses = [])
    {
        $this->execResponses = $execResponses;
        $this->procCpuinfoCount = $procCpuinfoCount;
        $this->fileResponses = $fileResponses;
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

    protected function readFileContents(string $path): ?string
    {
        if (!array_key_exists($path, $this->fileResponses)) {
            return null;
        }
        return $this->fileResponses[$path];
    }
}
