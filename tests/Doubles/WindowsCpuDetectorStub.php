<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\CpuDetector;

/**
 * Stub that forces Windows detection path on any platform.
 */
class WindowsCpuDetectorStub extends CpuDetector
{
    protected function isWindows(): bool
    {
        return true;
    }
}
