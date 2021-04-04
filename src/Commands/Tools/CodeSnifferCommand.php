<?php

namespace GitHooks\Commands\Tools;

use GitHooks\Constants;
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
        dd($errors->isEmpty());
    }
}
