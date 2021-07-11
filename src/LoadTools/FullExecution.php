<?php

namespace Wtyd\GitHooks\LoadTools;

use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\Tools\ToolsFactoy;

/**
 * Prepara todas las herramientas que estén configuradas con la etiqueta Tools en el fichero de configuración.
 */
class FullExecution implements ExecutionMode
{
    /**
     * @var ToolsFactoy
     */
    protected $toolsFactory;

    public function __construct(ToolsFactoy $toolsFactory)
    {
        $this->toolsFactory = $toolsFactory;
    }

    /**
     * Se cargan todas las herramientas configuradas
     *
     * @return array Cada elemento es la instancia de un objeto Tool distinto.
     */
    public function getTools(ConfigurationFile $configurationFile): array
    {

        return $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());
    }
}
