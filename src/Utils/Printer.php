<?php

namespace Wtyd\GitHooks\Utils;

/**
 * Muestra mensajes por la consola
 */
class Printer
{
    public function generalFail(string $exMessage): void
    {
        $this->resultError($exMessage);
    }

    public function resultSuccess(string $message): void
    {
        echo "✔️ ";
        $this->success($message);
    }

    public function resultWarning(string $message): void
    {
        echo "⚠️ ";
        $this->warning($message);
    }
    public function resultError(string $message): void
    {
        echo "❌ ";
        $this->error($message);
    }

    // Standard print
    public function line(string $message): void
    {
        echo "$message\n";
    }

    // Green
    public function info(string $message): void
    {
        $this->success($message);
    }

    public function success(string $message): void
    {
        echo "\e[42m\e[30m$message\033[0m\n";
    }

    // Red
    public function error(string $message): void
    {
        echo "\e[41m\e[30m$message\033[0m\n";
    }

    // Yellow
    public function comment(string $message): void
    {
        $this->warning($message);
    }

    public function warning(string $message): void
    {
        echo "\e[43m\e[30m$message\033[0m\n";
    }
}
