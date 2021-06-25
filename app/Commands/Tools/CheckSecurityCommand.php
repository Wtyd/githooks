<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Constants;

class CheckSecurityCommand extends ToolCommand
{
    protected $signature = 'tool:check-security';
    protected $description = 'Run check-security';

    public function handle()
    {
        $tools = $this->toolsPreparer->execute(Constants::CHECK_SECURITY);
        $errors = $this->toolExecutor->__invoke($tools, true);

        return $this->exit($errors);
    }
}
