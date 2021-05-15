<?php

namespace GitHooks\Commands\Tools;

use GitHooks\ChooseStrategy;
use GitHooks\Commands\Exception\InvalidArgumentValueException;
use GitHooks\Configuration;
use GitHooks\Constants;
use GitHooks\Tools\Errors;
use GitHooks\Tools\ToolExecutor;
use GitHooks\Tools\ToolsFactoy;

/**
 * Pasos para crear un ToolCommand:
 * 1. Crear un command que extienda de Illuminate\Console\Command y tenga una propiedad ToolCommandExecutor.
 * 2. En el método handle() pasar por parámetro el nombre de la herramienta.
 * 3. Registrar el Command en el fichero bin/githooks.
 *
 * Todos los comandos leerán la configuración del fichero qa/githooks.yml
 *
 */
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

    public function __construct(Configuration $config, ChooseStrategy $chooseStrategy, ToolsFactoy $toolsFactoy, ToolExecutor $toolExecutor)
    {
        $this->config = $config;
        $this->chooseStrategy = $chooseStrategy;
        $this->toolsFactoy = $toolsFactoy;
        $this->toolExecutor = $toolExecutor;
    }

    /**
     * Executes the tool with the githooks.yml arguments.
     * The Option 'execution' can be overriden with the $execution variable.
     *
     * @param string $tool Some of GitHooks\Tools\ToolAbstract supported tools
     * @param string $execution Strategy of execution. Can be 'smart', 'fast' or 'full'. Default from githooks.yml.
     * @return Errors
     */
    public function execute(string $tool, string $execution = ''): Errors
    {
        //TODO en lugar de invocar al toolsFActory directamente hay que pasar primero por ChooseStrategy
        //El orden en GitHOoks es:
        // 1. $file = $config->readfile();

        // 2. $strategy = $chooseStrategy->__invoke($file);

        // 3. $this->tools = $strategy->getTools();
        // 4. $errors = $this->toolExecutor->__invoke($this->tools);

        $file = $this->config->readfile();

        //Override execution strategy
        if (!empty($execution)) {
            if (in_array($execution, Constants::EXECUTION_KEY)) {
                $file[Constants::OPTIONS][Constants::EXECUTION] = $execution;
            }
        }

        $file[Constants::OPTIONS][Constants::EXECUTION] = $this->checkExecution($file, $execution);

        $file[Constants::TOOLS] = [$tool];

        $strategy = $this->chooseStrategy->__invoke($file);

        $tools = $strategy->getTools();
        // $tools = $this->toolsFactoy->__invoke($file[Constants::TOOLS], $file);

        return $this->toolExecutor->__invoke($tools, true);
    }
    protected function checkExecution(array $configurationFile, string $execution): string
    {
        if (empty($execution)) {
            return $configurationFile[Constants::OPTIONS][Constants::EXECUTION];
        }

        if (in_array($execution, Constants::EXECUTION_KEY)) {
            return $execution;
        } else {
            throw InvalidArgumentValueException::forArgument('execution', $execution, Constants::EXECUTION_KEY);
        }
    }
}
