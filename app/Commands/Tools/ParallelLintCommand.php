<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Constants;

class ParallelLintCommand extends ToolCommand
{
    protected $signature = 'tool:parallel-lint';
    protected $description = 'Run parallel-lint.';

    public function handle()
    {
        $tools = $this->toolsPreparer->execute(Constants::PARALLEL_LINT);
        $errors = $this->toolExecutor->__invoke($tools, true);

        return $this->exit($errors);
    }
}
