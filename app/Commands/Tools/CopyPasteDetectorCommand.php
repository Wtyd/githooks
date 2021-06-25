<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Constants;

class CopyPasteDetectorCommand extends ToolCommand
{
    protected $signature = 'tool:phpcpd';
    protected $description = 'Run phpcp.';

    public function handle()
    {
        $tools = $this->toolsPreparer->execute(Constants::COPYPASTE_DETECTOR);
        $errors = $this->toolExecutor->__invoke($tools, true);

        return $this->exit($errors);
    }
}
