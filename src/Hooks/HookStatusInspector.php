<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Hooks;

use Wtyd\GitHooks\Configuration\ConfigurationResult;

class HookStatusInspector
{
    private string $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
    }

    public function inspect(ConfigurationResult $config): HookStatusReport
    {
        $hooksDir = $this->rootPath . DIRECTORY_SEPARATOR . '.githooks';
        $hooksPathValue = $this->getGitHooksPath();
        $hooksPathConfigured = ($hooksPathValue === '.githooks');

        $configuredEvents = [];
        $hooks = $config->getHooks();
        if ($hooks !== null) {
            foreach ($hooks->getAll() as $event => $refs) {
                $configuredEvents[$event] = array_map(function ($ref) {
                    return $ref->getTarget();
                }, $refs);
            }
        }

        $installedEvents = $this->getInstalledEvents($hooksDir);

        $events = $this->buildEventStatuses($configuredEvents, $installedEvents, $hooksDir);

        return new HookStatusReport($hooksPathConfigured, $hooksPathValue, $events);
    }

    private function getGitHooksPath(): string
    {
        $output = [];
        $exitCode = 0;
        exec('git config core.hooksPath ' . \Wtyd\GitHooks\Utils\Platform::stderrRedirect(), $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return '';
        }

        return trim($output[0]);
    }

    /** @return string[] */
    private function getInstalledEvents(string $hooksDir): array
    {
        if (!is_dir($hooksDir)) {
            return [];
        }

        $files = scandir($hooksDir);
        if ($files === false) {
            return [];
        }

        return array_values(array_filter($files, function (string $file): bool {
            return $file !== '.' && $file !== '..';
        }));
    }

    /**
     * @param array<string, string[]> $configuredEvents
     * @param string[] $installedEvents
     * @return HookEventStatus[]
     */
    private function buildEventStatuses(array $configuredEvents, array $installedEvents, string $hooksDir): array
    {
        $events = [];
        $processed = [];

        // Configured events
        foreach ($configuredEvents as $event => $targets) {
            $installed = in_array($event, $installedEvents, true);
            $executable = $installed && is_executable($hooksDir . DIRECTORY_SEPARATOR . $event);
            $status = $installed ? HookEventStatus::STATUS_SYNCED : HookEventStatus::STATUS_MISSING;

            $events[] = new HookEventStatus($event, $status, $executable, $targets);
            $processed[] = $event;
        }

        // Orphan events (installed but not in config)
        foreach ($installedEvents as $event) {
            if (!in_array($event, $processed, true)) {
                $executable = is_executable($hooksDir . DIRECTORY_SEPARATOR . $event);
                $events[] = new HookEventStatus($event, HookEventStatus::STATUS_ORPHAN, $executable);
            }
        }

        return $events;
    }
}
