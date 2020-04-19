<?php

namespace GitHooks\LoadTools;

use GitHooks\Constants;
use GitHooks\Tools\ToolsFactoy;

/**
 * Prepara todas las herramientas que estén configuradas con la etiqueta Tools en el fichero de configuración.
 */
class FullStrategy implements StrategyInterface
{
    /**
     * Todo el fichero de configuración pasado a array. Su formato podria ser algo como lo siguiente:
     * ['Options' => ['smartExecution' => true], 'Tools' => ['parallel-lint', 'phpcs'], 'phpcs' => ['excludes' => ['vendor', 'qa'], 'rules' => 'rules_path.xml']];
     *
     * @var array
    */
    protected $configurationFile;

    /**
     * @var ToolsFactoy
     */
    protected $toolsFactory;

    public function __construct(array $configurationFile, ToolsFactoy $toolsFactory)
    {
        $this->configurationFile = $configurationFile;
        $this->toolsFactory = $toolsFactory;
    }

    /**
     * Se cargan todas las herramientas configuradas
     *
     * @return array Cada elemento es la instancia de un objeto Tool distinto.
     */
    public function getTools(): array
    {
        return $this->toolsFactory->__invoke($this->configurationFile[Constants::TOOLS], $this->configurationFile);
    }
}
