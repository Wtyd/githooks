<?php

namespace GitHooks\Commands;

use GitHooks\Constants;
use Illuminate\Console\Command;

class StanCommand extends Command
{
    protected $signature = 'tool:phpstan';
    protected $description = 'Run phpstan.';

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
        $this->toolCommandExecutor->execute(Constants::PHPSTAN);
    }
}
