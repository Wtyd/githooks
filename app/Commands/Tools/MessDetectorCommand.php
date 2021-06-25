<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Constants;

class MessDetectorCommand extends ToolCommand
{
    protected $signature = 'tool:phpmd';
    protected $description = 'Run phpmd.';

    public function handle()
    {
        $tools = $this->toolsPreparer->execute(Constants::MESS_DETECTOR);
        $errors = $this->toolExecutor->__invoke($tools, true);

        return $this->exit($errors);
    }
}
