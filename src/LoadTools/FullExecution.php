<?php

namespace Wtyd\GitHooks\LoadTools;

use Wtyd\GitHooks\Constants;
use Wtyd\GitHooks\Tools\ToolsFactoy;

/**
 * Prepara todas las herramientas que estén configuradas con la etiqueta Tools en el fichero de configuración.
 */
class FullExecution implements ExecutionMode
{
    /**
     * Configuration file 'githooks.yml' in array format. It could be like this:
     * ['Options' => ['execution' => 'full'], 'Tools' => ['parallel-lint', 'phpcs'], 'phpcs' => ['excludes' => ['vendor', 'qa'], 'rules' => 'rules_path.xml']];
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
