<?php

namespace Wtyd\Alfil\Container;

use Illuminate\Container\Container;
use Wtyd\Alfil\Configuration\ConfigurationParser;
use Wtyd\Alfil\Execution\FlowExecutor;
use Wtyd\Alfil\Execution\FlowPreparer;
use Wtyd\Alfil\Hooks\HookInstaller;
use Wtyd\Alfil\Hooks\HookRunner;
use Wtyd\Alfil\Hooks\HookStatusInspector;
use Wtyd\Alfil\Jobs\JobRegistry;
use Wtyd\Alfil\Utils\FileUtils;
use Wtyd\Alfil\Utils\FileUtilsInterface;
use Wtyd\Alfil\Utils\GitStager;
use Wtyd\Alfil\Utils\GitStagerInterface;
use Wtyd\Alfil\Output\OutputHandler;
use Wtyd\Alfil\Output\TextOutputHandler;
use Wtyd\Alfil\Utils\Printer;

/** @SuppressWarnings(PHPMD.CouplingBetweenObjects) DI container binds all services by definition */
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
            JobRegistry::class => JobRegistry::class,
            ConfigurationParser::class => function (Container $app) {
                return new ConfigurationParser('', $app->make(JobRegistry::class));
            },
            FlowPreparer::class => function (Container $app) {
                return new FlowPreparer($app->make(JobRegistry::class));
            },
            OutputHandler::class => function (Container $app) {
                return new TextOutputHandler($app->make(Printer::class));
            },
            FlowExecutor::class => function (Container $app) {
                return new FlowExecutor(
                    $app->make(OutputHandler::class),
                    $app->make(GitStagerInterface::class)
                );
            },
            HookRunner::class => function (Container $app) {
                return new HookRunner(
                    $app->make(FlowPreparer::class),
                    $app->make(FlowExecutor::class),
                    $app->make(FileUtilsInterface::class),
                    null,
                    $app->make(ConfigurationParser::class)
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
