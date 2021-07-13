<?php

namespace App\Commands;

use App\Commands\ToolCommand as BaseCommand;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileInterface;
use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\ToolAbstract;

class ExecuteToolCommand extends BaseCommand
{
    protected $signature = 'tool {tool : Tool will be run} {execution? : Override the execution mode of githooks.yml}';
    protected $description = 'Run the tool passed as argument. The must be a supporte tool by GitHooks. Values: "fast", "full" and "smart"';

    public function handle()
    {
        $errors = new Errors();
        $tool = strval($this->argument('tool'));
        $execution = strval($this->argument('execution'));

        if (!ToolAbstract::checkTool($tool)) {
            $this->error("The $tool tool is not supported by GiHooks.");
            return 1;
            // throw ToolDoesNotExistException::forTool($tool);
        }

        try {
            $tools = $this->toolsPreparer->__invoke($tool, $execution);

            $errors = $this->toolExecutor->__invoke($tools, true);
        } catch (ConfigurationFileInterface $exception) {
            $this->error($exception->getMessage());
            // TODO mejorar esto
            foreach ($exception->getConfigurationFile()->getToolsErrors() as $error) {
                $this->error($error);
            }
            foreach ($exception->getConfigurationFile()->getToolsErrors() as $error) {
                $this->error($error);
            }
        } catch (\Throwable $th) {
            //throw $th;
        }




        return $this->exit($errors);
    }
}
