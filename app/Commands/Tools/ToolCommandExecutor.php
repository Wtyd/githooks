<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\ChooseStrategy;
use App\Commands\Exception\InvalidArgumentValueException;
use Wtyd\GitHooks\Configuration;
use Wtyd\GitHooks\Constants;
use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\ToolExecutor;
use Wtyd\GitHooks\Tools\ToolsFactoy;

class ToolCommandExecutor
{
    /**
     * @var Configuration
     */
    protected $config;

    /**
     * @var ChooseStrategy
     */
    protected $chooseStrategy;

    /**
     * @var ToolsFactoy
     */
    protected $toolsFactoy;

    /**
     * @var ToolExecutor
     */
    protected $toolExecutor;

    public function __construct(Configuration $config, ChooseStrategy $chooseStrategy, ToolExecutor $toolExecutor)
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
     * @param boolean $isLiveOutput True for print the command under the hood and the result of the tool in live. False for only final summary.
     *
     * @return Errors
     */
    public function execute(string $tool = 'all', string $execution = '', bool $isLiveOutput = false): Errors
    {
        $file = $this->config->readfile();

        $file[Constants::OPTIONS][Constants::EXECUTION] = $this->setExecution($file[Constants::OPTIONS][Constants::EXECUTION], $execution);

        $file[Constants::TOOLS] = $this->setTools($file[Constants::TOOLS], $tool);

        $strategy = $this->chooseStrategy->__invoke($file);

        $tools = $strategy->getTools();

        return $this->toolExecutor->__invoke($tools, $isLiveOutput);
    }

    protected function setExecution(string $defaultExecution, string $execution): string
    {
        if (empty($execution)) {
            return $defaultExecution;
        }

        if (in_array($execution, Constants::EXECUTION_KEY)) {
            return $execution;
        } else {
            throw InvalidArgumentValueException::forArgument('execution', $execution, Constants::EXECUTION_KEY);
        }
    }

    protected function setTools(array $defaultTools, string $tool): array
    {
        return ($tool === 'all') ? $defaultTools : [$tool];
    }
}
