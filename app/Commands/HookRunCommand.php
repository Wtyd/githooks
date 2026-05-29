<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Hooks\HookRunner;

/**
 * Thin CLI adapter for `githooks hook:run <event>`. Phase 3 reduces handle()
 * to a single delegate call: parse + validate + run live in {@see HookRunner::runEvent()}.
 */
class HookRunCommand extends Command
{
    protected $signature = 'hook:run
                            {event : The git hook event (e.g. pre-commit, pre-push)}
                            {--config= : Path to configuration file}';

    protected $description = 'Execute all flows/jobs associated with a git hook event. Called by the hook script.';

    private HookRunner $runner;

    public function __construct(HookRunner $runner)
    {
        parent::__construct();
        $this->runner = $runner;
    }

    public function handle(): int
    {
        return $this->runner->runEvent(
            strval($this->argument('event')),
            strval($this->option('config')),
            $this->output
        );
    }
}
