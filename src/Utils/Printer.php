<?php

namespace GitHooks\Utils;

use GitHooks\Tools\ToolAbstract;

/**
 * Muestra mensajes por la consola
 */
class Printer
{
    use ColoredMessagesTrait;

    /**
     * Mensaje cuando la herramienta se ejecuta correctamente y valida el código.
     *
     * @param string $excecutableTool. Nombre del ejecutable de la consola
     * @param float $time. Tiempo de ejecución de la herramienta
     * @return void
     */
    public function success(string $excecutableTool, float $time): void
    {
        $time = number_format($time, 2);
        $message = $excecutableTool . ' - OK. Time: ' . $time;
        $this->messageSuccess($message);
    }

    public function fail(ToolAbstract $tool, float $time): void
    {
        $tool->printErrors();

        $message = $tool->getExecutable() . ' - KO. Time: ' . number_format($time, 2);
        $this->messageFailure($message);
    }

    public function executionFail(string $excecutableTool, string $exMessage): void
    {
        $this->messageFailure("Error en la ejecución de $excecutableTool.");
        echo "\n $exMessage";
    }

    public function generalFail(string $exMessage): void
    {
        $this->messageFailure($exMessage);
    }
}
