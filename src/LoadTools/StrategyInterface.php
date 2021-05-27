<?php

namespace Wtyd\GitHooks\LoadTools;

interface StrategyInterface
{
    public function getTools(): array;
}
