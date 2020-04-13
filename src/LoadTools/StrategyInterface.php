<?php
namespace GitHooks\LoadTools;

interface StrategyInterface
{
    public function getTools(): array;
}
