<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Process;

use Wtyd\GitHooks\Execution\Concerns\ReadsProcFiles;

/**
 * Linux process tree backed by /proc/<PID>/task/<PID>/children (Linux 3.5+),
 * which is O(descendants) per walk — much cheaper than scanning /proc.
 * Errors per PID are silently ignored: processes vanish between the
 * children listing and the next read, and that is normal mid-kill.
 */
class LinuxProcessTree implements ProcessTree
{
    use ReadsProcFiles;

    public function descendants(int $rootPid): array
    {
        if ($rootPid <= 0) {
            return [];
        }

        return ProcessTreeWalk::descendants($rootPid, function (int $pid): array {
            return $this->readChildren($pid);
        });
    }

    public function alive(array $pids): array
    {
        $alive = [];
        foreach ($pids as $pid) {
            if ($this->isAlive($pid)) {
                $alive[] = $pid;
            }
        }
        return $alive;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getUnavailableReason(): string
    {
        return '';
    }

    /**
     * Read direct children of a PID via /proc/<PID>/task/<PID>/children.
     * Returns an empty array when the file is unreadable or the process
     * has gone.
     *
     * @return int[]
     */
    private function readChildren(int $pid): array
    {
        $contents = $this->readProcFile("/proc/{$pid}/task/{$pid}/children");
        if ($contents === null || $contents === '') {
            return [];
        }
        $pids = [];
        foreach (preg_split('/\s+/', trim($contents)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (ctype_digit($token)) {
                $pids[] = (int) $token;
            }
        }
        return $pids;
    }

    /**
     * /proc/<PID>/stat reads `pid (comm) S ppid …`. The comm may contain
     * spaces and parentheses, so the state is the field right after the
     * LAST `)`. `Z` (zombie) and `X` (dead) count as gone: they hold no
     * resources and disappear as soon as the parent reaps them.
     */
    private function isAlive(int $pid): bool
    {
        $stat = $this->readProcFile("/proc/{$pid}/stat");
        if ($stat === null) {
            return false;
        }
        $close = strrpos($stat, ')');
        if ($close === false) {
            return false;
        }
        $state = (string) substr($stat, $close + 2, 1);
        return $state !== '' && $state !== 'Z' && $state !== 'X';
    }
}
