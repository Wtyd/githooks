<?php

namespace GitHooks\Commands\Tools;

use GitHooks\Constants;
use Illuminate\Console\Command;

class CopyPasteDetectorCommand extends Command
{
    protected $signature = 'tool:phpcpd';
    protected $description = 'Run phpcp.';

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
        $this->toolCommandExecutor->execute(Constants::COPYPASTE_DETECTOR);
    }
}
