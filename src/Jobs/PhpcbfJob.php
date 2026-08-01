<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

class PhpcbfJob extends PhpcsJob
{
    public function supportsStructuredOutput(): bool
    {
        return false;
    }

    public function applyStructuredOutputFormat(): bool
    {
        return false;
    }

    public static function getDefaultExecutable(): string
    {
        return 'phpcbf';
    }

    public function mayApplyFixes(): bool
    {
        return true;
    }

    /**
     * phpcbf exit code 1 means fixes were applied.
     */
    public function isFixApplied(int $exitCode): bool
    {
        return $exitCode === 1;
    }
}
