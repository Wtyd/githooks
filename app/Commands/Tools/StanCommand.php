<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Constants;

class StanCommand extends ToolCommand
{
    protected $signature = 'tool:phpstan';
    protected $description = 'Run phpstan.';

    public function handle()
    {
        $tools = $this->toolsPreparer->execute(Constants::PHPSTAN);
        $errors = $this->toolExecutor->__invoke($tools, true);

        return $this->exit($errors);
    }
}
