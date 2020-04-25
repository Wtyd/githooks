<?php

namespace GitHooks\Commands;

use GitHooks\GitHooks;
use GitHooks\Utils\Printer;
use Illuminate\Console\Command;
use Illuminate\Container\Container;

class ExecuteAllToolsCommand extends Command
{
    protected $signature = 'tool:all';
    protected $description = 'Ejecuta todas las herramientas con la configuración que se encuentre en el fichero de configuración.';

    public function handle(Printer $printer)
    {
        $rootPath = getcwd();
        $configFile = $rootPath . '/qa/githooks.yml';
        $container = Container::getInstance();
        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $configFile]);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $printer->generalFail($th->getMessage());
        }
    }
}
