<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\GitHooks;
use Wtyd\GitHooks\Utils\Printer;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Container\Container;

class ExecuteAllToolsCommand extends Command
{
    protected $signature = 'tool:all';
    protected $description = 'Runs all the tools setted in githooks.yml';

    public function handle(Printer $printer)
    {
        $container = Container::getInstance();
        $githooks = $container->makeWith(GitHooks::class);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $printer->generalFail($th->getMessage());
        }
    }
}
