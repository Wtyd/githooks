<?php

namespace Wtyd\GitHooks\App\Commands;

use Wtyd\GitHooks\App\Commands\ToolCommand as BaseCommand;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileInterface;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\ConfigurationFile\Exception\WrongExecutionValueException;
use Wtyd\GitHooks\Tools\Errors;

class ExecuteToolCommand extends BaseCommand
{
    protected $signature = 'tool {tool : Tool will be run} {execution? : Override the execution mode of githooks.yml. Values: "fast" and "full"}';
    protected $description = 'Run the tool passed as argument. It must be a supported tool by GitHooks. ';

    public function handle()
    {
        $errors = new Errors();
        $tool = strval($this->argument('tool'));
        $execution = strval($this->argument('execution'));

        try {
            $tools = $this->toolsPreparer->__invoke($tool, $execution);

            $withLiveOutput = $tool === 'all' ? false : true;

            $errors = $this->toolExecutor->__invoke($tools, $withLiveOutput);
        } catch (ToolIsNotSupportedException $exception) {
            $this->error($exception->getMessage());
            $errors->setError($tool, $exception->getMessage());
        } catch (WrongExecutionValueException $exception) {
            $this->error($exception->getMessage());
            $errors->setError($tool, $exception->getMessage());
        } catch (ConfigurationFileNotFoundException $exception) {
            $this->error($exception->getMessage());
            $errors->setError($tool, $exception->getMessage());
        } catch (ConfigurationFileInterface $exception) {
            $this->error($exception->getMessage());
            $errors->setError('set error', 'to return 1');

            foreach ($exception->getConfigurationFile()->getErrors() as $error) {
                $this->error($error);
            }

            foreach ($exception->getConfigurationFile()->getWarnings() as $warning) {
                $this->warning($warning);
            }
        }

        return $this->exit($errors);
    }
}
