<?php

namespace Wtyd\GitHooks\App\Commands;

use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\ToolsPreparer;
use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\ConfigurationFile\ReadConfigurationFileAction;
use Wtyd\GitHooks\Tools\Execution\ProcessExecutionFactory;
use Wtyd\GitHooks\Tools\Execution\ProcessExecutionFactoryAbstract;

abstract class ToolCommand extends Command
{
    /**  @var  ReadConfigurationFileAction */
    protected $readConfigurationFileAction;

    /** @var  ToolsPreparer */
    protected $toolsPreparer;

    /** @var ProcessExecutionFactoryAbstract */
    protected $processExecutionFactory;

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
