<?php

namespace GitHooks\Commands\Tools;

use GitHooks\Constants;
use GitHooks\Tools\Errors;
use GitHooks\Utils\GitFiles;
use GitHooks\Utils\GitFilesInterface;
use GitHooks\Utils\Printer;
use Illuminate\Console\Command;
use Illuminate\Container\Container;

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
