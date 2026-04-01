<?php

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Hooks;
use Wtyd\GitHooks\Hooks\HookInstaller;
use Wtyd\GitHooks\Utils\Printer;
use Wtyd\GitHooks\Utils\Storage;

class CleanHookCommand extends Command
{
    protected $signature = 'hook:clean
                            {hook=pre-commit}
                            {--legacy : Clean a single hook from .git/hooks/ instead of cleaning .githooks/}';

    protected $description = 'Remove installed hooks. By default removes .githooks/ directory and unsets core.hooksPath.';

    protected Printer $printer;

    private HookInstaller $installer;

    public function __construct(Printer $printer, HookInstaller $installer)
    {
        parent::__construct();
        $this->printer = $printer;
        $this->installer = $installer;
    }

    public function handle()
    {
        if ($this->option('legacy')) {
            return $this->handleLegacy();
        }

        return $this->handleV3();
    }

    private function handleV3(): int
    {
        $this->installer->clean();
        $this->printer->success("Hooks directory .githooks/ removed and core.hooksPath unset");
        return 0;
    }

    private function handleLegacy(): int
    {
        $hook = strval($this->argument('hook'));

        if (!Hooks::validate($hook)) {
            $this->printer->error("'$hook' is not a valid git hook. Available hooks are:");
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
        }

        $this->printer->error("Could not delete $hook hook");
        return 1;
    }
}
