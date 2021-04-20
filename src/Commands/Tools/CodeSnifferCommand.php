<?php

namespace GitHooks\Commands\Tools;

use GitHooks\Constants;
use GitHooks\Exception\ExitException;
use GitHooks\Tools\Errors;
use GitHooks\Utils\Printer;
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

    protected $printer;

    public function __construct(ToolCommandExecutor $toolCommandExecutor, Printer $printer)
    {
        $this->toolCommandExecutor = $toolCommandExecutor;
        $this->printer = $printer;
        parent::__construct();
    }

    public function handle()
    {
        $errors = $this->toolCommandExecutor->execute(Constants::CODE_SNIFFER);
        return $this->exit($errors);
        // dd($this->exit($errors));
    }

    public function exit(Errors $errors): int
    {
        if (!$errors->isEmpty()) {
            // throw ExitException::forErrors('Se han producido algunos errores');
            $this->printer->resultError($errors->__toString());
            return 1;
        }
        return 0;
    }
}
