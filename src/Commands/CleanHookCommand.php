<?php

namespace GitHooks\Commands;

use GitHooks\Utils\Printer;
use Illuminate\Console\Command;

class CleanHookCommand extends Command
{
    protected $signature = 'hook:clean  {hook=pre-commit}';
    protected $description = 'Deletes the hook passed as argument (default pre-commit)';

    /**
     * @var Printer
     */
    protected $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
        parent::__construct();
    }

    public function handle()
    {
        $root = getcwd();
        $hook = strval($this->argument('hook'));

        if (unlink("$root/.git/hooks/$hook")) {
            $this->printer->success("Hook $hook has been deleted");
        } else {
            $this->printer->error("Could not delete hook $hook");
        }
    }
}
