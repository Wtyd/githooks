<?php

namespace GitHooks\Commands;

use GitHooks\Constants;
use Illuminate\Console\Command;

class CodeSnifferCommand extends Command
{
    protected $signature = 'tool:phpcs';
    protected $description = 'Run phpcs';

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
        $this->toolCommandExecutor->execute(Constants::CODE_SNIFFER);
    }
}
