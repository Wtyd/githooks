<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Inspection;

/**
 * Serialise a {@see ConfigCheckResult} as the `conf:check --format=json`
 * payload. The v3 blocks (options/hooks/flows/jobs) are omitted for a legacy
 * config, which instead carries a `hint` to run `conf:migrate`.
 */
final class ConfigCheckJsonFormatter
{
    public function format(ConfigCheckResult $result): string
    {
        $data = [
            'version' => 1,
            'valid' => $result->isValid(),
            'legacy' => $result->isLegacy(),
            'file' => [
                'path' => $result->getFilePath(),
                'localPath' => $result->getLocalFilePath(),
            ],
        ];

        if (!$result->isLegacy()) {
            $data['options'] = $result->getOptions();
            $data['hooks'] = $result->getHooks();
            $data['flows'] = $result->getFlows();
            $data['jobs'] = $result->getJobs();
        }

        $data['errors'] = $result->getErrors();
        $data['warnings'] = $result->getWarnings();
        $data['deprecations'] = $result->getDeprecations();

        if ($result->getHint() !== null) {
            $data['hint'] = $result->getHint();
        }

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
