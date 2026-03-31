<?php

namespace Wtyd\GitHooks\Container;

use Illuminate\Container\Container;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Hooks\HookInstaller;
use Wtyd\GitHooks\Hooks\HookRunner;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\Process\ProcessExecutionFactory\ProcessExecutionFactory;
use Wtyd\GitHooks\Tools\Process\ProcessExecutionFactory\ProcessExecutionFactoryAbstract;
use Wtyd\GitHooks\Utils\FileUtils;
use Wtyd\GitHooks\Utils\FileUtilsInterface;
use Wtyd\GitHooks\Utils\GitStager;
use Wtyd\GitHooks\Utils\GitStagerInterface;
use Wtyd\GitHooks\Utils\Printer;

class RegisterBindings
{
    /**
     * Makes efectively the bindings
     *
     * @return void
     */
    public function register(): void
    {
        $container = Container::getInstance();

        foreach ($this->binds() as $key => $value) {
            $container->bind($key, $value);
        }

        foreach ($this->singletons() as $key => $value) {
            $container->singleton($key, $value);
        }
    }

    /**
     * Register with bind method
     *
     * @return array
     */
    protected function binds(): array
    {
        return [
            FileUtilsInterface::class => FileUtils::class,
            GitStagerInterface::class => GitStager::class,
        ];
    }

    /**
     * Register singletons
     *
     * @return array
     */
    protected function singletons(): array
    {
        return [
            ProcessExecutionFactoryAbstract::class => ProcessExecutionFactory::class,
            ToolRegistry::class => ToolRegistry::class,
            JobRegistry::class => JobRegistry::class,
            ConfigurationParser::class => function (Container $c) {
                return new ConfigurationParser($c->make(ToolRegistry::class));
            },
            FlowPreparer::class => function (Container $c) {
                return new FlowPreparer($c->make(JobRegistry::class));
            },
            FlowExecutor::class => function (Container $c) {
                return new FlowExecutor($c->make(Printer::class));
            },
            HookRunner::class => function (Container $c) {
                return new HookRunner(
                    $c->make(FlowPreparer::class),
                    $c->make(FlowExecutor::class)
                );
            },
            HookInstaller::class => function () {
                return new HookInstaller(getcwd() ?: '');
            },
        ];
    }
}
