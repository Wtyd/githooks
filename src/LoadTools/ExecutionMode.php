<?php

namespace Wtyd\GitHooks\LoadTools;

interface ExecutionMode
{
    public const ROOT_PATH = './';

    public function getTools(): array;
}
