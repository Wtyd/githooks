<?php

namespace GitHooks\Commands;

use GitHooks\GitHooks;
use GitHooks\Utils\Printer;
use Illuminate\Console\Command;
use Illuminate\Container\Container;

class ExecuteAllCommand extends Command
{
    protected $signature = 'tool:execute-all';
    protected $description = 'Ejecuta todas las herramientas con la configuración que se encuentre en el fichero de configuración.';

    public function __construct(ToolCommandExecutor $toolCommandExecutor)
    {
        $this->toolCommandExecutor = $toolCommandExecutor;
        parent::__construct();
    }

    public function handle()
    {
        $rootPath = getcwd();
        $configFile = $rootPath . '/qa/githooks.yml';
        $container = Container::getInstance();
        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $configFile]);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $printer = new Printer();
            $printer->generalFail($th->getMessage());
        }
    }
}
