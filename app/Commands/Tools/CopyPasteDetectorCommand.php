<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Constants;
use LaravelZero\Framework\Commands\Command;

class CopyPasteDetectorCommand extends Command
{
    protected $signature = 'tool:phpcpd';
    protected $description = 'Run phpcp.';

    /**
     * @var App\Commands\Tools\ToolCommandExecutor
     */
    protected $toolCommandExecutor;

    public function __construct(ToolCommandExecutor $toolCommandExecutor)
    {
        $this->toolCommandExecutor = $toolCommandExecutor;
        parent::__construct();
    }

    public function handle()
    {
        $this->toolCommandExecutor->execute(Constants::COPYPASTE_DETECTOR);
    }
}
