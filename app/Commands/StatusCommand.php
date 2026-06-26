<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsStderr;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesDiagnosticFormat;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Hooks\HookEventStatus;
use Wtyd\GitHooks\Hooks\HookStatusInspector;
use Wtyd\GitHooks\Hooks\HookStatusReport;
use Wtyd\GitHooks\Output\Inspection\StatusJsonFormatter;

class StatusCommand extends Command
{
    use EmitsStderr;
    use ResolvesDiagnosticFormat;

    protected $signature = 'status
                            {--config= : Path to configuration file}
                            {--format= : Output format (text, json)}';

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
        $format = $this->resolveDiagnosticFormat();
        $configFile = strval($this->option('config'));

        try {
            $config = $this->parser->parse($configFile);

            if ($config->isLegacy()) {
                return $this->fail($format, "The 'status' command requires v3 configuration format (hooks/flows/jobs).");
            }

            $report = $this->inspector->inspect($config);
        } catch (\Throwable $e) {
            return $this->fail($format, $e->getMessage());
        }

        if ($format === 'json') {
            $this->output->writeln((new StatusJsonFormatter())->format($report));
            return 0;
        }

        return $this->renderText($report);
    }

    private function renderText(HookStatusReport $report): int
    {
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
    }

    /**
     * Report a failure honouring the output format: a structured error on
     * stdout for `json` (so the payload stays parseable), the plain message
     * for `text`. Exit code is unchanged across formats.
     */
    private function fail(string $format, string $message): int
    {
        if ($format === 'json') {
            $this->output->writeln(
                (string) json_encode(['version' => 1, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } else {
            $this->error($message);
        }

        return 1;
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
