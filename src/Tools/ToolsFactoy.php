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
    public function __invoke(array $tools, $configurationFile): array
    {
        $loadedTools = [];

        $container = Container::getInstance();
        foreach ($tools as $tool) {
            if (!array_key_exists($tool, ToolAbstract::SUPPORTED_TOOLS)) {
                throw ToolDoesNotExistException::forTool($tool);
            }

            //No necesita recibir parametros del fichero de configuracion
            if (ToolAbstract::CHECK_SECURITY === $tool) {
                $loadedTools[$tool] = $container->make(ToolAbstract::SUPPORTED_TOOLS[$tool]);
            } else {
                $loadedTools[$tool] = $container->make(ToolAbstract::SUPPORTED_TOOLS[$tool], [Constants::CONFIGURATION_FILE => $configurationFile]);
            }
        }

        return $loadedTools;
    }
}
