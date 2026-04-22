<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Configuration\ToolDetector;
use Wtyd\GitHooks\Jobs\JobRegistry;

/**
 * Test double for ToolDetector that returns a pre-configured list of
 * detected tools instead of scanning vendor/bin/ on disk.
 *
 * Defaults to an empty list so tests that don't set anything reach
 * the "no QA tools detected" fallback branch deterministically.
 */
class ToolDetectorFake extends ToolDetector
{
    /** @var string[] */
    private array $detected = [];

    public function __construct(JobRegistry $jobRegistry)
    {
        parent::__construct($jobRegistry);
    }

    /** @param string[] $tools */
    public function setDetected(array $tools): void
    {
        $this->detected = $tools;
    }

    public function detect(string $vendorBinPath = 'vendor/bin'): array
    {
        return $this->detected;
    }
}
