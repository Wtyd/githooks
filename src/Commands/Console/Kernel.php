<?php

namespace GitHooks\Commands\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Tests\Artisan\Application;
use Illuminate\Console\Application as Artisan;

class Kernel extends ConsoleKernel
{
    protected $registerCommands;

    public function __construct(Application $app, Dispatcher $events, RegisterCommands $registerCommands)
    {
        if (!defined('ARTISAN_BINARY')) {
            define('ARTISAN_BINARY', 'bin/githooks');
        }

        $this->app = $app;
        $this->events = $events;
        $this->registerCommands = $registerCommands;

        $this->app->booted(function () {
            $this->defineConsoleSchedule();
        });
    }
    /**
     * The bootstrap classes for the application.
     *
     * @var array
     */
    protected $bootstrappers = [
        // \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        // \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        // \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
        // \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
        RegisterFacades::class,
        // \Illuminate\Foundation\Bootstrap\SetRequestForConsole::class,
        // \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
        // \Illuminate\Foundation\Bootstrap\BootProviders::class,
    ];

    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];


    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands(): void
    {
        $this->load($this->registerCommands->__invoke());
        // $this->load(__DIR__ . '/../Tools');
        // require base_path(__DIR__ . '/console.php');
    }

    /**
     * Run an Artisan console command by name.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @param  \Symfony\Component\Console\Output\OutputInterface  $outputBuffer
     * @return int
     *
     * @throws \Symfony\Component\Console\Exception\CommandNotFoundException
     */
    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        $this->bootstrap();

        $this->app = $this->getArtisan();

        return $this->app->call($command, $parameters, $outputBuffer);
        // return $this->getArtisan()->call($command, $parameters, $outputBuffer);
    }

    /**
     * Bootstrap the application for artisan commands.
     *
     * @return void
     */
    public function bootstrap()
    {
        if (!$this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers());
        }

        $this->app->loadDeferredProviders();

        if (!$this->commandsLoaded) {
            $this->commands();

            $this->commandsLoaded = true;
        }
    }

    /**
     * Register all of the commands in the given directory.
     *
     * @param  array|string  $paths
     * @return void
     */
    protected function load($commands)
    {
        // $container = Container::getInstance();
        // Bind Tools Commands
        // $commands = [
        //     ParallelLintCommand::class,
        //     CodeSnifferCommand::class,
        //     CopyPasteDetectorCommand::class,
        //     MessDetectorCommand::class,
        //     StanCommand::class,
        //     CheckSecurityCommand::class,
        //     CreateConfigurationFileCommand::class,
        //     CheckConfigurationFileCommand::class
        // ];

        foreach ($commands as $command) {
            Artisan::starting(function ($artisan) use ($command) {
                $artisan->resolve($command);
            });
        }

        // Other Commands
        // $this->app->resolve(CreateConfigurationFileCommand::class);
        // $this->app->resolve(CheckConfigurationFileCommand::class);
        // $this->app->resolve(ExecuteAllToolsCommand::class);
        // $this->app->resolve(HookCommand::class);
        // $this->app->resolve(CleanHookCommand::class);
    }
}
