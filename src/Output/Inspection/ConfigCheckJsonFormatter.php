<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Inspection;

/**
 * Serialise a {@see ConfigCheckResult} as the `conf:check --format=json`
 * payload (schema version 2: the `legacy` marker and `hint` of version 1
 * left with the v2 configuration format).
 */
final class ConfigCheckJsonFormatter
{
    public function format(ConfigCheckResult $result): string
    {
        $data = [
            'version' => 2,
            'valid' => $result->isValid(),
            'file' => [
                'path' => $result->getFilePath(),
                'localPath' => $result->getLocalFilePath(),
            ],
            'options' => $result->getOptions(),
            'hooks' => $result->getHooks(),
            'flows' => $result->getFlows(),
            'jobs' => $result->getJobs(),
            'errors' => $result->getErrors(),
            'warnings' => $result->getWarnings(),
            'deprecations' => $result->getDeprecations(),
        ];

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
