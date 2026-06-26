<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Inspection;

/**
 * Serialise a {@see SystemInfo} as the `system:info --format=json` payload:
 * `{version, cpus, processes, warning}`. `processes` is null when no usable v3
 * configuration was found; `warning` is null unless processes over-subscribes
 * the available CPUs.
 */
final class SystemInfoJsonFormatter
{
    public function format(SystemInfo $info): string
    {
        $data = [
            'version' => 1,
            'cpus' => $info->getCpus(),
            'processes' => $info->getProcesses(),
            'warning' => $info->warning(),
        ];

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
