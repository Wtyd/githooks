<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Inspection;

use Wtyd\GitHooks\Hooks\HookStatusReport;

/**
 * Serialise a {@see HookStatusReport} as the `status --format=json` payload.
 *
 * Targets are emitted raw (the text renderer's placeholders — `—` and
 * `(not in configuration)` — are presentation, not data).
 */
final class StatusJsonFormatter
{
    public function format(HookStatusReport $report): string
    {
        $events = [];
        foreach ($report->getEvents() as $event) {
            $events[] = [
                'event' => $event->getEvent(),
                'status' => $event->getStatus(),
                'executable' => $event->isExecutable(),
                'targets' => $event->getTargets(),
            ];
        }

        $data = [
            'version' => 1,
            'hooksPath' => [
                'configured' => $report->isHooksPathConfigured(),
                'value' => $report->getHooksPathValue(),
            ],
            'events' => $events,
        ];

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
