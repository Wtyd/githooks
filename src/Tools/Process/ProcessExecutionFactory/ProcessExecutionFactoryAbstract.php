<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Process\ProcessExecutionFactory;

use Illuminate\Contracts\Container\Container;
use Wtyd\GitHooks\Tools\Process\Execution\ProcessExecutionAbstract;
use Wtyd\GitHooks\Utils\GitStagerInterface;
use Wtyd\GitHooks\Utils\Printer;

abstract class ProcessExecutionFactoryAbstract
{
    /** @var \Illuminate\Contracts\Container\Container */
    protected $container;

    /** @var \Wtyd\GitHooks\Utils\Printer */
    protected $printer;

    /** @var \Wtyd\GitHooks\Utils\GitStagerInterface */
    protected $gitStager;

    public function __construct(Container $container, Printer $printer, GitStagerInterface $gitStager)
    {
        $this->container = $container;
        $this->printer = $printer;
        $this->gitStager = $gitStager;
    }

    /**
     * @param string $tool
     * @return ProcessExecutionAbstract
     */
    abstract public function create(string $tool): ProcessExecutionAbstract;
}
