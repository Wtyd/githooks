<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Hooks\HookEventStatus;
use Wtyd\GitHooks\Hooks\HookStatusInspector;

class StatusCommand extends Command
{
    protected $signature = 'status
                            {--config= : Path to configuration file}';

    protected $description = 'Show the status of installed hooks and their synchronization with the configuration';

    private ConfigurationParser $parser;

    private HookStatusInspector $inspector;

    public function __construct(ConfigurationParser $parser, HookStatusInspector $inspector)
    {
        parent::__construct();
        $this->parser = $parser;
        $this->inspector = $inspector;
    }

    public function handle(): int
    {
        $configFile = strval($this->option('config'));

        try {
            $config = $this->parser->parse($configFile);

            if ($config->isLegacy()) {
                $this->error("The 'status' command requires v3 configuration format (hooks/flows/jobs).");
                return 1;
            }

            $report = $this->inspector->inspect($config);

            $this->line('');
            $this->info('GitHooks Status');
            $this->line('');

            // Hooks path status
            if ($report->isHooksPathConfigured()) {
                $this->line("  hooks path: <fg=green>.githooks</> (configured via core.hooksPath)");
            } elseif ($report->getHooksPathValue() !== '') {
                $this->line("  hooks path: <fg=yellow>" . $report->getHooksPathValue() . "</> (not .githooks — run 'githooks hook' to fix)");
            } else {
                $this->line("  hooks path: <fg=red>not configured</> (run 'githooks hook' to install)");
            }

            $this->line('');

            // Events table
            $events = $report->getEvents();

            if (empty($events)) {
                $this->line("  No hooks configured or installed.");
                return 0;
            }

            $rows = [];
            foreach ($events as $eventStatus) {
                $status = $this->formatStatus($eventStatus->getStatus());
                $targets = $this->formatTargets($eventStatus);
                $rows[] = [$eventStatus->getEvent(), $status, $targets];
            }

            $this->table(['Event', 'Status', 'Targets'], $rows);

            $this->line('');
            $this->line("  Legend: <fg=green>synced</> = installed & configured, <fg=red>missing</> = configured but not installed, <fg=yellow>orphan</> = installed but not configured");

            return 0;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function formatStatus(string $status): string
    {
        switch ($status) {
            case HookEventStatus::STATUS_SYNCED:
                return '<fg=green>synced</>';
            case HookEventStatus::STATUS_MISSING:
                return '<fg=red>missing</>';
            case HookEventStatus::STATUS_ORPHAN:
                return '<fg=yellow>orphan</>';
            default:
                return $status;
        }
    }

    private function formatTargets(HookEventStatus $eventStatus): string
    {
        $targets = $eventStatus->getTargets();

        if (empty($targets)) {
            return $eventStatus->getStatus() === HookEventStatus::STATUS_ORPHAN
                ? '(not in configuration)'
                : '—';
        }

        return implode(', ', $targets);
    }
}
