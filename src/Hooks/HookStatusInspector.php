<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Hooks;

use Wtyd\Alfil\Configuration\ConfigurationResult;

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

    protected function getGitHooksPath(): string
    {
        $output = [];
        $exitCode = 0;
        exec('git config core.hooksPath ' . \Wtyd\Alfil\Utils\Platform::stderrRedirect(), $output, $exitCode);

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

            $eventStatus = new HookEventStatus($event, $status, $executable, $targets);
            $events[] = $installed ? $this->flagRemovedBinary($eventStatus, $hooksDir) : $eventStatus;
            $processed[] = $event;
        }

        // Orphan events (installed but not in config)
        foreach ($installedEvents as $event) {
            if (!in_array($event, $processed, true)) {
                $executable = is_executable($hooksDir . DIRECTORY_SEPARATOR . $event);
                $events[] = $this->flagRemovedBinary(
                    new HookEventStatus($event, HookEventStatus::STATUS_ORPHAN, $executable),
                    $hooksDir
                );
            }
        }

        return $events;
    }

    /**
     * A script installed by 3.x calls the `githooks` binary, gone in 4.0: it
     * is still "synced" by name, but every trigger fails until `alfil hook`
     * regenerates it.
     */
    private function flagRemovedBinary(HookEventStatus $eventStatus, string $hooksDir): HookEventStatus
    {
        $path = $hooksDir . DIRECTORY_SEPARATOR . $eventStatus->getEvent();
        $script = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        return is_string($script) && RemovedBinary::isCalledBy($script)
            ? $eventStatus->withRemovedBinaryCall()
            : $eventStatus;
    }
}
