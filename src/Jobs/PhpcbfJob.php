<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

class PhpcbfJob extends PhpcsJob
{
    public static function getDefaultExecutable(): string
    {
        return 'phpcbf';
    }

    /**
     * phpcbf exit code 1 means fixes were applied.
     */
    public function isFixApplied(int $exitCode): bool
    {
        return $exitCode === 1;
    }
}
