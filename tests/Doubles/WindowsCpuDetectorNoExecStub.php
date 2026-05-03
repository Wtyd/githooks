<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\CpuDetector;

/**
 * Forces the Windows detection path and stubs `execCommand` so the wmic fallback
 * always reports failure (exitCode=127). Tests using this stub exercise the
 * REAL `CpuDetector::detectWindows()` body — so mutations on lines 27, 34, 35
 * and 44 are observable. The previous implementation duplicated detectWindows()
 * here, which masked all those mutants from Infection.
 */
class WindowsCpuDetectorNoExecStub extends CpuDetector
{
    protected function isWindows(): bool
    {
        return true;
    }

    protected function execCommand(string $command, array &$output, int &$exitCode): void
    {
        $output = [];
        $exitCode = 127;
    }
}
