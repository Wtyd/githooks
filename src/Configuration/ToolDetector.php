<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Jobs\JobRegistry;

/**
 * Detects QA tool binaries in vendor/bin/ to suggest job configuration.
 */
class ToolDetector
{
    private JobRegistry $jobRegistry;

    public function __construct(JobRegistry $jobRegistry)
    {
        $this->jobRegistry = $jobRegistry;
    }

    /**
     * Scan a directory for tool binaries and return matching job types.
     *
     * @return string[] Supported type names found (e.g. ['phpstan', 'phpcs', 'phpmd'])
     */
    public function detect(string $vendorBinPath = 'vendor/bin'): array
    {
        $found = [];
        $skipTypes = ['custom', 'script'];

        foreach ($this->jobRegistry->supportedTypes() as $type) {
            if (in_array($type, $skipTypes, true)) {
                continue;
            }
            $executable = $this->jobRegistry->getDefaultExecutable($type);
            if ($executable === '') {
                continue;
            }
            if (file_exists($vendorBinPath . DIRECTORY_SEPARATOR . $executable)) {
                $found[] = $type;
            }
        }

        return $found;
    }
}
