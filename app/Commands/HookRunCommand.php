<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Hooks\HookRunner;

class HookRunCommand extends Command
{
    protected $signature = 'hook:run
                            {event : The git hook event (e.g. pre-commit, pre-push)}
                            {--config= : Path to configuration file}';

    protected $description = 'Execute all flows/jobs associated with a git hook event. Called by the hook script.';

    private ConfigurationParser $parser;

    private HookRunner $runner;

    public function __construct(ConfigurationParser $parser, HookRunner $runner)
    {
        parent::__construct();
        $this->parser = $parser;
        $this->runner = $runner;
    }

    public function handle(): int
    {
        $event = strval($this->argument('event'));
        $configFile = strval($this->option('config'));

        try {
            $config = $this->parser->parse($configFile);

            if ($config->isLegacy()) {
                $this->error("hook:run requires v3 configuration format (hooks/flows/jobs).");
                return 1;
            }

            if ($config->hasErrors()) {
                foreach ($config->getValidation()->getErrors() as $error) {
                    $this->error($error);
                }
                return 1;
            }

            if ($config->getHooks() === null) {
                $this->warn("No 'hooks' section found in configuration. Nothing to run.");
                return 0;
            }

            $results = $this->runner->run($event, $config);

            if (empty($results)) {
                $this->warn("No flows or jobs configured for event '$event'.");
                return 0;
            }

            return $this->runner->exitCode($results);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
