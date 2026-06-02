<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\MemoryDetector;

/**
 * Forces a platform path and scripts file (`/proc`, `/sys`) + `exec()` responses
 * for {@see MemoryDetector}.
 */
class MemoryDetectorStub extends MemoryDetector
{
    private string $platform;

    /** @var array<string, ?string> */
    private array $files;

    /** @var array<string, array{output: array<int,string>, exit: int}> */
    private array $exec;

    /** @param array<string, ?string> $files path => contents (null = unreadable)
     *  @param array<string, array{output: array<int,string>, exit: int}> $exec */
    public function __construct(string $platform, array $files = [], array $exec = [])
    {
        $this->platform = $platform;
        $this->files = $files;
        $this->exec = $exec;
    }

    protected function isWindows(): bool
    {
        return $this->platform === 'windows';
    }

    protected function isMacOS(): bool
    {
        return $this->platform === 'macos';
    }

    protected function readFileContents(string $path): ?string
    {
        return array_key_exists($path, $this->files) ? $this->files[$path] : null;
    }

    protected function execCommand(string $command, array &$output, int &$exitCode): void
    {
        if (isset($this->exec[$command])) {
            $output = $this->exec[$command]['output'];
            $exitCode = $this->exec[$command]['exit'];
            return;
        }
        $output = [];
        $exitCode = 127;
    }
}
