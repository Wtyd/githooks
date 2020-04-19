<?php

namespace GitHooks\Utils;

trait ColoredMessagesTrait
{
    public function messageSuccess($cadena): void
    {
        echo "\n✔️ \e[42m\e[30m$cadena\033[0m";
    }

    public function messageFailure($cadena): void
    {
        echo "\n❌ \e[41m\e[30m$cadena\033[0m";
    }

    public function messageWarning($cadena): void
    {
        echo "\n⚠️ \e[43m\e[30m$cadena\033[0m";
    }
}
