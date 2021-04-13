<?php

namespace GitHooks\Commands\Tools;

use GitHooks\Constants;
use GitHooks\Tools\Errors;
use Illuminate\Console\Command;

/**
 * @SuppressWarnings(PHPMD.ExitExpression)
 */
class CodeSnifferCommand extends Command
{
    protected $signature = 'tool:phpcs';
    protected $description = 'Run phpcs';

    /**
     * @var ToolCommandExecutor
     */
    protected $toolCommandExecutor;

    public function __construct(ToolCommandExecutor $toolCommandExecutor)
    {
        $this->toolCommandExecutor = $toolCommandExecutor;
        parent::__construct();
    }

    public function handle()
    {
        $errors = $this->toolCommandExecutor->execute(Constants::CODE_SNIFFER);
        dd($this->exit($errors));
    }

    public function exit(Errors $errors)
    {
        if ($errors->isEmpty()) {
            exit(0);
        } else {
            exit(1);
        }
    }
}
