<?php

namespace GitHooks\Commands;

use GitHooks\Constants;
use Illuminate\Console\Command;

class CheckSecurityCommand extends Command
{
    protected $signature = 'tool:check-security';
    protected $description = 'Run check-security';

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
        $this->toolCommandExecutor->execute(Constants::CHECK_SECURITY);
    }
}
