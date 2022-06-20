<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Execution;

use Illuminate\Contracts\Container\Container;
use Wtyd\GitHooks\Utils\Printer;

abstract class ProcessExecutionFactoryAbstract
{
    public function __construct(Container $container, Printer $printer)
    {
        $this->container = $container;
        $this->printer = $printer;
    }

    /**
     * @param string $tool
     * @param array<ToolAbstract> $tools
     * @param integer $threds
     * @return ProcessExecutionAbstract
     */
    abstract public function create(string $tool, array $tools, int $threds): ProcessExecutionAbstract;
}
