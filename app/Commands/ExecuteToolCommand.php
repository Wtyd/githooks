<?php

namespace App\Commands;

use App\Commands\ToolCommand as BaseCommand;
use Wtyd\GitHooks\LoadTools\Exception\ToolDoesNotExistException;
use Wtyd\GitHooks\Tools\ToolAbstract;

class ExecuteToolCommand extends BaseCommand
{
    protected $signature = 'tool {tool : Tool will be run} {execution? : Override the execution mode of githooks.yml}';
    protected $description = 'Run the tool passed as argument. The must be a supporte tool by GitHooks. Values: "fast", "full" and "smart"';

    public function handle()
    {
        $tool = strval($this->argument('tool'));
        $execution = strval($this->argument('execution'));

        if (!ToolAbstract::checkTool($tool)) {
            $this->error("The $tool tool is not supported by GiHooks.");
            return 1;
            // throw ToolDoesNotExistException::forTool($tool);
        }

        $tools = $this->toolsPreparer->__invoke($tool, $execution);

        $errors = $this->toolExecutor->__invoke($tools, true);

        return $this->exit($errors);
    }
}
