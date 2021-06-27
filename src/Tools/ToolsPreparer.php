<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\LoadTools\ExecutionFactory;
use App\Commands\Exception\InvalidArgumentValueException;
use Wtyd\GitHooks\Configuration;
use Wtyd\GitHooks\Constants;
use Wtyd\GitHooks\LoadTools\ExecutionMode;
use Wtyd\GitHooks\Tools\ToolExecutor;

class ToolsPreparer
{
    /**
     * @var Configuration
     */
    protected $config;

    /**
     * @var ExecutionFactory
     */
    protected $chooseStrategy;

    /**
     * @var ToolExecutor
     */
    protected $toolExecutor;

    public function __construct(Configuration $config, ExecutionFactory $chooseStrategy, ToolExecutor $toolExecutor)
    {
        $this->config = $config;
        $this->chooseStrategy = $chooseStrategy;
        $this->toolExecutor = $toolExecutor;
    }

    /**
     * Executes the tool with the githooks.yml arguments.
     * The Option 'execution' can be overriden with the $execution variable.
     *
     * @param string $tool Name of the tool to be executed. 'all' for execute all tools setted in githooks.yml
     * @param string $execution Strategy of execution. Can be 'smart', 'fast' or 'full'. Default from githooks.yml.
     *
     * @return array Tools created and prepared for run.
     */
    public function execute(string $tool = 'all', string $execution = ''): array
    {
        $file = $this->config->readfile();

        $file[Constants::OPTIONS][Constants::EXECUTION] = $this->setExecution($file[Constants::OPTIONS][Constants::EXECUTION], $execution);

        $file[Constants::TOOLS] = $this->setTools($file[Constants::TOOLS], $tool);

        $strategy = $this->chooseStrategy->__invoke($file);

        return $strategy->getTools();
    }

    protected function setExecution(string $defaultExecution, string $execution): string
    {
        if (empty($execution)) {
            return $defaultExecution;
        }

        if (in_array($execution, ExecutionMode::EXECUTION_KEY)) {
            return $execution;
        } else {
            throw InvalidArgumentValueException::forArgument('execution', $execution, ExecutionMode::EXECUTION_KEY);
        }
    }

    protected function setTools(array $defaultTools, string $tool): array
    {
        return ($tool === 'all') ? $defaultTools : [$tool];
    }
}
