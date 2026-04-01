<?php

namespace Wtyd\GitHooks\Container;

use Illuminate\Container\Container;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Hooks\HookInstaller;
use Wtyd\GitHooks\Hooks\HookRunner;
use Wtyd\GitHooks\Hooks\HookStatusInspector;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\Process\ProcessExecutionFactory\ProcessExecutionFactory;
use Wtyd\GitHooks\Tools\Process\ProcessExecutionFactory\ProcessExecutionFactoryAbstract;
use Wtyd\GitHooks\Utils\FileUtils;
use Wtyd\GitHooks\Utils\FileUtilsInterface;
use Wtyd\GitHooks\Utils\GitStager;
use Wtyd\GitHooks\Utils\GitStagerInterface;
use Wtyd\GitHooks\Output\OutputHandler;
use Wtyd\GitHooks\Output\TextOutputHandler;
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
            ConfigurationParser::class => function (Container $app) {
                return new ConfigurationParser(
                    $app->make(ToolRegistry::class),
                    '',
                    $app->make(JobRegistry::class)
                );
            },
            FlowPreparer::class => function (Container $app) {
                return new FlowPreparer($app->make(JobRegistry::class));
            },
            OutputHandler::class => function (Container $app) {
                return new TextOutputHandler($app->make(Printer::class));
            },
            FlowExecutor::class => function (Container $app) {
                return new FlowExecutor($app->make(OutputHandler::class));
            },
            HookRunner::class => function (Container $app) {
                return new HookRunner(
                    $app->make(FlowPreparer::class),
                    $app->make(FlowExecutor::class),
                    $app->make(FileUtilsInterface::class)
                );
            },
            HookInstaller::class => function () {
                return new HookInstaller(getcwd() ?: '');
            },
            HookStatusInspector::class => function () {
                return new HookStatusInspector(getcwd() ?: '');
            },
        ];
    }
}
