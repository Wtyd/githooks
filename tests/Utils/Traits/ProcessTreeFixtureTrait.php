<?php

declare(strict_types=1);

namespace Tests\Utils\Traits;

use Wtyd\GitHooks\Execution\Process\ProcessTreeFactory;

/**
 * Helpers for the real-process regression tests of the process-tree kill
 * (BUG-35). Liveness is checked WITHOUT the SUT — straight from /proc on
 * Linux and `ps` on macOS — and zombies never count as survivors: a dead
 * child its parent has not reaped yet looks alive to `posix_kill($pid, 0)`
 * but holds nothing and vanishes on the next wait(). Likewise, PIDs are
 * checked one by one: `pgrep -f <pattern>` matches the very shell that
 * carries the pattern on its command line and reports ghosts.
 */
trait ProcessTreeFixtureTrait
{
    protected function skipUnlessTreeKillSupported(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_kill')) {
            $this->markTestSkipped('process-tree kill needs a Unix platform with ext-posix');
        }
        if (PHP_OS_FAMILY === 'Linux' && !is_dir('/proc')) {
            $this->markTestSkipped('process-tree kill on Linux needs /proc');
        }
    }

    /**
     * Poll the platform tree until $rootPid has at least $min descendants
     * (the shell needs a moment to fork them) or the timeout elapses.
     *
     * @return int[]
     */
    protected function waitForDescendants(int $rootPid, int $min, float $timeoutSec = 3.0): array
    {
        $tree = (new ProcessTreeFactory())->create();
        $deadline = microtime(true) + $timeoutSec;
        $descendants = $tree->descendants($rootPid);
        while (count($descendants) < $min && microtime(true) < $deadline) {
            usleep(20000);
            $descendants = $tree->descendants($rootPid);
        }
        return $descendants;
    }

    /**
     * Alive and not a zombie, read independently of the production tree.
     *
     * @SuppressWarnings(PHPMD.ErrorControlOperator) /proc entries vanish mid-read by design.
     */
    protected function isProcessAlive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $output = [];
            exec('ps -o stat= -p ' . $pid . ' 2>/dev/null', $output);
            $state = trim(implode('', $output));
            return $state !== '' && $state[0] !== 'Z';
        }

        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return false;
        }
        $close = strrpos($stat, ')');
        $state = $close === false ? '' : (string) substr($stat, $close + 2, 1);
        return $state !== '' && $state !== 'Z' && $state !== 'X';
    }

    /**
     * Give the kernel up to $timeoutSec to reap, then assert the PID is gone.
     */
    protected function assertProcessGone(int $pid, string $what, float $timeoutSec = 2.0): void
    {
        $deadline = microtime(true) + $timeoutSec;
        while ($this->isProcessAlive($pid) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFalse($this->isProcessAlive($pid), "{$what} (pid {$pid}) survived the kill");
    }

    /** Best-effort SIGKILL for tearDown so a red test never leaks a 30 s sleeper. */
    protected function killIfAlive(int $pid): void
    {
        if ($pid > 1 && $this->isProcessAlive($pid) && function_exists('posix_kill')) {
            posix_kill($pid, 9);
        }
    }

    /**
     * A 3-level job for the memory-budget kill path:
     *
     *   Symfony `sh -c` → inner `sh -c` → { `sleep 30`, memory-burner.php }
     *
     * The inner shell writes its own PID, the sleeper's and the burner's to
     * files and waits for both, so every level can be asserted (and cleaned
     * up) by PID. The RSS sum only crosses the budget once the burner has
     * allocated — i.e. once the whole tree exists and there is something
     * to kill.
     */
    protected function sleeperTreeScript(
        string $shellPidFile,
        string $sleeperPidFile,
        string $burnerPidFile,
        string $burnerPath,
        int $megabytes
    ): string {
        return sprintf(
            "sh -c 'echo $$ > %s; sleep 30 & echo $! > %s; %s %s %d 30 & echo $! > %s; wait'",
            $shellPidFile,
            $sleeperPidFile,
            PHP_BINARY,
            $burnerPath,
            $megabytes,
            $burnerPidFile
        );
    }

    protected function readPidFile(string $path): int
    {
        $this->assertFileExists($path, 'the fixture shell never wrote its pid file');
        return (int) trim((string) file_get_contents($path));
    }
}
