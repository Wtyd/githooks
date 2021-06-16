<?php

namespace Wtyd\GitHooks\LoadTools;

interface StrategyInterface
{
    public const ROOT_PATH = './';

    public function getTools(): array;
}
