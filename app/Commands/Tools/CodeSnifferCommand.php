<?php

namespace App\Commands\Tools;

use Wtyd\GitHooks\Constants;
use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Utils\Printer;
use LaravelZero\Framework\Commands\Command;

/**
 * @SuppressWarnings(PHPMD.ExitExpression)
 */
class CodeSnifferCommand extends Command
{
    protected $signature = 'tool:phpcs {execution?}';
    protected $description = 'Run phpcs';

    /**
     * @var ToolCommandExecutor
     */
    protected $toolCommandExecutor;

    /**
     * @var Printer
     */
    protected $printer;

    public function __construct(ToolCommandExecutor $toolCommandExecutor, Printer $printer)
    {
        $this->toolCommandExecutor = $toolCommandExecutor;
        $this->printer = $printer;
        parent::__construct();
    }

    public function handle()
    {
        $execution = strval($this->argument('execution'));

        $errors = $this->toolCommandExecutor->execute(Constants::CODE_SNIFFER, $execution);
        return $this->exit($errors);
    }

    public function exit(Errors $errors): int
    {
        if (!$errors->isEmpty()) {
            return 1;
        }
        return 0;
    }
}
