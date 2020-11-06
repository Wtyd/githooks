<?php

namespace GitHooks\Commands\Tools;

use GitHooks\Configuration;
use GitHooks\Constants;
use GitHooks\Exception\ConfigurationFileNotFoundException;
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
 * @SuppressWarnings(PHPMD.ExitExpression)
 */
class ToolCommandExecutor
{
    /**
     * @var Configuration
     */
    protected $config;

    /**
     * @var ToolsFactoy
     */
    protected $toolsFactoy;

    /**
     * @var ToolExecutor
     */
    protected $toolExecutor;

    public function __construct(Configuration $config, ToolsFactoy $toolsFactoy, ToolExecutor $toolExecutor)
    {
        $this->config = $config;
        $this->toolsFactoy = $toolsFactoy;
        $this->toolExecutor = $toolExecutor;
    }

    public function execute(string $tool): void
    {
        $file = $this->config->readfile();

        $file[Constants::TOOLS] = [$tool];

        $exitCode = $this->toolExecutor->__invoke($this->toolsFactoy->__invoke($file[Constants::TOOLS], $file), true);

        exit($exitCode);
    }
}
