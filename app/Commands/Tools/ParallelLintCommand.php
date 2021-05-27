<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Constants;
use LaravelZero\Framework\Commands\Command;

class ParallelLintCommand extends Command
{
    protected $signature = 'tool:parallel-lint';
    protected $description = 'Run parallel-lint.';

    /**
     * @var ToolCommandExecutor
     */
    protected $toolCommandExecutor;

    public function __construct(ToolCommandExecutor $toolCommandExecutor)
    {
        $this->toolCommandExecutor = $toolCommandExecutor;
        parent::__construct();
    }

    public function handle()
    {
        $this->toolCommandExecutor->execute(Constants::PARALLEL_LINT);
    }
}
