<?php

namespace Wtyd\GitHooks\App\Commands;

use Wtyd\GitHooks\App\Commands\ToolCommand as BaseCommand;
use Wtyd\GitHooks\ConfigurationFile\CliArguments;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileInterface;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\ConfigurationFile\Exception\WrongOptionsValueException;
use Wtyd\GitHooks\Tools\Errors;

class ExecuteToolCommand extends BaseCommand
{
    protected $signature = 'tool 
                            {tool : Tool will be run}
                            {execution? : Override the execution mode of githooks.yml. Values: "fast" and "full"}
                            {--ignoreErrorsOnExit= : Avoids exit error even if the tool finds some trobule. When tool is \'all\' applies for all tools}
                            {--otherArguments= : Other tool options not supported by GitHooks}
                            {--executablePath= : Path to executable}
                            {--paths= :  Paths or files against the tool will be executed}
                            {--processes= : Number of parallel processes in which the tools will be executed}';

    protected $description = 'Run the tool passed as argument. It must be a supported tool by GitHooks. the available options depend on the tool passed as parameter';

    public function handle()
    {
        $errors = new Errors();
        $tool = strval($this->argument('tool'));
        $execution = strval($this->argument('execution'));

        try {
            $configurationFile = $this->readConfigurationFileAction
                ->__invoke(new CliArguments(
                    $tool,
                    $execution,
                    $this->option('ignoreErrorsOnExit'),
                    strval($this->option('otherArguments')),
                    strval($this->option('executablePath')),
                    strval($this->option('paths')),
                    intval($this->option('processes'))
                ));

            $tools = $this->toolsPreparer->__invoke($configurationFile);

            $processesExecution = $this->processExecutionFactory->create($tool);

            $errors = $processesExecution->execute($tools, $configurationFile->getProcesses());
        } catch (ToolIsNotSupportedException $exception) {
            $this->error($exception->getMessage());
            $errors->setError($tool, $exception->getMessage());
        } catch (WrongOptionsValueException $exception) {
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
                $this->warn($warning);
            }
        }

        foreach ($this->toolsPreparer->getConfigurationFileWarnings() as $warning) {
            $this->warn($warning);
        }

        return $this->exit($errors);
    }
}
