<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Jobs\CustomJob;

/**
 * Inline job double (FEAT-16): runs in-process, so its pool entry must carry
 * no Process and its JobResult must come straight from runInline(). The name,
 * verdict and output are configurable so tests can assert the result identity
 * (it was produced here, not by a shell process).
 */
class InlineJobFake extends CustomJob
{
    private bool $inlineSuccess;

    private string $inlineOutput;

    public function __construct(string $name, bool $success = true, string $output = '')
    {
        parent::__construct(new JobConfiguration($name, 'custom', ['script' => 'true']));
        $this->inlineSuccess = $success;
        $this->inlineOutput = $output;
    }

    public function isInline(): bool
    {
        return true;
    }

    public function runInline(): JobResult
    {
        return new JobResult(
            $this->name,
            $this->inlineSuccess,
            $this->inlineOutput,
            '0ms',
            false,
            null,
            'custom',
            $this->inlineSuccess ? 0 : 1
        );
    }
}
