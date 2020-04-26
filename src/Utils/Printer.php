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
        $this->resultSuccess($message);
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

    // TODO Pablo: Crear los metodos basicos en el Printer (line, info, comment, error).
    public function info(string $message): void
    {
        echo "$message\n";
    }

    public function error(string $message): void
    {
        echo "\e[41m\e[30m$message\033[0m\n";
    }

    public function warning($message): void
    {
        echo "\e[43m\e[30m$message\033[0m\n";
    }

    public function success2($message) : void
    {
        echo "\e[42m\e[30m$message\033[0m\n";
    }

    public function resultSuccess(string $message): void
    {
        echo "✔️ ";$this->success2($message);
    }

    public function resultWarning(string $message): void
    {
        echo "⚠️ ";$this->warning($message);
    }
    public function resultError(string $message): void
    {
        echo "❌ ";$this->error($message);
    }
}
