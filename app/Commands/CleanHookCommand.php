<?php

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Hooks;
use Wtyd\GitHooks\Utils\Printer;
use Wtyd\GitHooks\Utils\Storage;

class CleanHookCommand extends Command
{
    protected $signature = 'hook:clean {hook=pre-commit}';
    protected $description = 'Deletes the hook passed as argument (default pre-commit)';

    /**
     * Extra information about the command invoked with the --help flag.
     *
     * @var string
     */
    protected $help = 'Without arguments deletes the pre-commit hook. A optional argument can be the name of another hook. Example: hook:clean pre-push.';

    /**
     * @var Printer
     */
    protected $printer;

    public function __construct(Printer $printer)
    {
        parent::__construct();
        $this->printer = $printer;
    }

    public function handle()
    {
        $hook = strval($this->argument('hook'));

        if (!Hooks::validate($hook)) {
            $this->printer->error("'$hook' is not a valid git hook. Avaliable hooks are:");
            $this->printer->error(implode(', ', Hooks::HOOKS));
            return 1;
        }

        $file = ".git/hooks/$hook";
        if (!Storage::exists($file)) {
            $this->printer->warning("The hook $hook cannot be deleted because it cannot be found");
            return 1;
        }

        if (Storage::delete($file)) {
            $this->printer->success("Hook $hook has been deleted");
            return 0;
        } else {
            $this->printer->error("Could not delete $hook hook");
            return 1;
        }
    }
}
