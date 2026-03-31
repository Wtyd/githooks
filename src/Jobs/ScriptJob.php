<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

class ScriptJob extends JobAbstract
{
    protected const ARGUMENT_MAP = [];

    public static function getDefaultExecutable(): string
    {
        return '';
    }

    public function buildCommand(): string
    {
        $command = $this->executable;

        if (!empty($this->args['otherArguments'])) {
            $command .= ' ' . $this->args['otherArguments'];
        }

        return $command;
    }

    public function getDisplayName(): string
    {
        return $this->executable;
    }
}
