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

    public function handle(Printer $printer, ToolCommandExecutor $toolCommandExecutor): int
    {
        try {
            $startTotalTime = microtime(true);
            $result = $toolCommandExecutor->execute('all', '', false);
            $endTotalTime = microtime(true);
            $executionTotalTime = $endTotalTime - $startTotalTime;

            $printer->line("  Total run time = " . number_format($executionTotalTime, 2) . " seconds.");

            return $result->isEmpty() ? 0 : 1;
        } catch (\Throwable $th) {
            $printer->generalFail($th->getMessage());
            return 1;
        }
    }
}
