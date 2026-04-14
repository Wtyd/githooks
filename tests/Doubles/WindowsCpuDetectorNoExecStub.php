<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\CpuDetector;

/**
 * Stub that forces Windows path and only checks env (no exec calls).
 */
class WindowsCpuDetectorNoExecStub extends CpuDetector
{
    protected function isWindows(): bool
    {
        return true;
    }

    protected function detectWindows(): int
    {
        $env = getenv('NUMBER_OF_PROCESSORS');
        if ($env !== false && (int) $env > 0) {
            return (int) $env;
        }

        return 1;
    }
}
