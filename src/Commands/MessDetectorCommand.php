<?php

namespace GitHooks\Commands;

use GitHooks\Constants;
use Illuminate\Console\Command;

class MessDetectorCommand extends Command
{
    protected $signature = 'tool:phpmd';
    protected $description = 'Run phpmd.';

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
        $this->toolCommandExecutor->execute(Constants::MESS_DETECTOR);
    }
}
