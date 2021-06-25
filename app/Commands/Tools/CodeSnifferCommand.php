<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Constants;

/**
 * @SuppressWarnings(PHPMD.ExitExpression)
 */
class CodeSnifferCommand extends ToolCommand
{
    protected $signature = 'tool:phpcs {execution?}';
    protected $description = 'Run phpcs';

    public function handle()
    {
        $execution = strval($this->argument('execution'));

        $tools = $this->toolsPreparer->execute(Constants::CODE_SNIFFER, $execution);
        $errors = $this->toolExecutor->__invoke($tools, true);

        return $this->exit($errors);
    }
}
