<?php

namespace App\Commands;

use Wtyd\GitHooks\Utils\Printer;

class ExecuteAllToolsCommand extends ToolCommand
{
    protected $signature = 'tool:all';
    protected $description = 'Runs all the tools setted in githooks.yml';

    public function handle(Printer $printer): int
    {
        try {
            $startTotalTime = microtime(true);

            $tools = $this->toolsPreparer->__invoke('all', '');
            $errors = $this->toolExecutor->__invoke($tools, false);

            $endTotalTime = microtime(true);
            $executionTotalTime = $endTotalTime - $startTotalTime;

            $printer->line("  Total run time = " . number_format($executionTotalTime, 2) . " seconds.");

            return $this->exit($errors);
        } catch (\Throwable $th) {
            $printer->generalFail($th->getMessage());
            return 1;
        }
    }
}
