<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\Constants;
use Wtyd\GitHooks\LoadTools\Exception\ToolDoesNotExistException;
use Illuminate\Container\Container;

class ToolsFactoy
{
    /**
     * Recibe un array de herramientas y el fichero de configuración y devuelve un array con las herramientas instanciadas.
     *
     * @param array $tools. Array asociativo donde la clave es el nombre de la herramienta y el valor la clase que la instancia.
     * @param array $configurationFile. Fichero de configuración.
     * @return array asociativo cuya clave es el nombre de la herramienta y el valor su instancia.
     */
    public function __invoke(array $tools): array
    {
        $loadedTools = [];

        $container = Container::getInstance();
        foreach ($tools as $tool) {
            if (!array_key_exists($tool->getTool(), ToolAbstract::SUPPORTED_TOOLS)) {
                //TODO esto en principio ya está validado
                throw ToolDoesNotExistException::forTool($tool);
            }

            //No necesita recibir parametros del fichero de configuracion
            if (ToolAbstract::CHECK_SECURITY === $tool->getTool()) {
                $loadedTools[$tool->getTool()] = $container->make(ToolAbstract::SUPPORTED_TOOLS[$tool->getTool()]);
            } else {
                $loadedTools[$tool->getTool()] = $container->make(ToolAbstract::SUPPORTED_TOOLS[$tool->getTool()], [ToolAbstract::TOOL_CONFIGURATION => $tool]);
            }
        }

        return $loadedTools;
    }
}
