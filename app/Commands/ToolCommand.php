<?php

namespace Wtyd\GitHooks\App\Commands;

use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\ToolsPreparer;
use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\ConfigurationFile\ReadConfigurationFileAction;
use Wtyd\GitHooks\Tools\Process\ProcessExecutionFactory\ProcessExecutionFactoryAbstract;

abstract class ToolCommand extends Command
{
    protected ReadConfigurationFileAction $readConfigurationFileAction;

    protected ToolsPreparer $toolsPreparer;

    protected ProcessExecutionFactoryAbstract $processExecutionFactory;

    public function __construct(
        ReadConfigurationFileAction $readConfigurationFileAction,
        ToolsPreparer $toolsPreparer,
        ProcessExecutionFactoryAbstract $processExecutionFactory
    ) {
        $this->readConfigurationFileAction = $readConfigurationFileAction;
        $this->toolsPreparer = $toolsPreparer;
        $this->processExecutionFactory = $processExecutionFactory;
        parent::__construct();
    }

    protected function exit(Errors $errors): int
    {
        return $errors->isEmpty() ? 0 : 1;
    }
}
