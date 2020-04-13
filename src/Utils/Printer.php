<?php

namespace GitHooks\Utils;

use GitHooks\Tools\ToolAbstract;
use Illuminate\Console\Concerns\InteractsWithIO;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\Output;

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
     * @param int $time. Tiempo de ejecución de la herramienta
     * @return void
     */
    public function success(string $excecutableTool, float $time)
    {
        $time = number_format($time, 2);
        $message = $excecutableTool . ' - OK. Time: ' . $time;
        $this->messageSuccess($message);
    }

    public function fail(ToolAbstract $tool, float $time)
    {
        $tool->printErrors();

        $message = $tool->getExecutable() . ' - KO. Time: ' . number_format($time, 2);
        $this->messageFailure($message);
    }

    public function executionFail(string $excecutableTool, string $exMessage)
    {
        $this->messageFailure("Error en la ejecución de $excecutableTool.");
        echo "\n $exMessage";
    }

    public function generalFail(string $exMessage)
    {
        $this->messageFailure($exMessage);
    }
}
