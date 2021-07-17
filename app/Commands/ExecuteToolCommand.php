<?php

namespace App\Commands;

use App\Commands\ToolCommand as BaseCommand;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileInterface;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\Tools\Errors;

class ExecuteToolCommand extends BaseCommand
{
    protected $signature = 'tool {tool : Tool will be run} {execution? : Override the execution mode of githooks.yml}';
    protected $description = 'Run the tool passed as argument. The must be a supporte tool by GitHooks. Values: "fast", "full" and "smart"';

    public function handle()
    {
        $errors = new Errors();
        $tool = strval($this->argument('tool'));
        $execution = strval($this->argument('execution'));

        try {
            $tools = $this->toolsPreparer->__invoke($tool, $execution);

            $errors = $this->toolExecutor->__invoke($tools, true);
        } catch (ToolIsNotSupportedException $th) {
            $this->error($th->getMessage());
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
            throw $th;
        }




        return $this->exit($errors);
    }
}
