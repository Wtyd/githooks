<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Constants;
use LaravelZero\Framework\Commands\Command;

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
