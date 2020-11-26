<?php

namespace GitHooks\Commands;

use Exception;
use GitHooks\Utils\Printer;
use Illuminate\Console\Command;

class CreateHookCommand extends Command
{
    protected $signature = 'hook  {hook=pre-commit} {scriptFile?}';
    protected $description = 'Copies the hook for the GitHooks execution. The default hook is pre-commit. You can pass scriptFile as argument to set custom scripts.';

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
        $scriptFile = $this->argument('scriptFile') ?? '';

        $origin = $this->path2OriginFile($root, strval($scriptFile));
        try {
            $destiny = "$root/.git/hooks/$hook";
            copy($origin, $destiny);
            chmod($destiny, 0755);
            $this->printer->success("Hook $hook created");
        } catch (\Throwable $th) {
            $this->printer->error("Error copying $origin in $hook");
        }
    }

    /**
     * Find the origin of the scripts for the hook
     *
     * @param string $root Path to the root of the project.
     * @param string $scriptFile File with the custom script. It's optional.
     *
     * @return string File to be executed in the hook.
     */
    public function path2OriginFile(string $root, string $scriptFile): string
    {
        $origin = '';
        if (empty($scriptFile)) {
            $origin = $this->defaultPrecommit($root);
        } else {
            if (!file_exists($scriptFile)) {
                throw new Exception("$scriptFile file not found");
            }
            $origin = $scriptFile;
        }
        return $origin;
    }

    /**
     * Returns the path of the file that contains the script to be executed in the hook:
     * 1. When GitHooks will be a library, it returns the path through 'vendor'.
     * 2. To work on developing GitHooks itself, it will return the local path to 'hooks'
     *
     * @param string $root Root path of the project.
     *
     * @return string The default script to run GitHooks in the hook.
     */
    public function defaultPrecommit(string $root): string
    {
        $origin = '';
        if (file_exists($root . '/vendor/wtyd/githooks/hooks/pre-commit.php')) {
            $origin = $root . '/vendor/wtyd/githooks/hooks/pre-commit.php';
        }

        if (file_exists($root . '/hooks/pre-commit.php')) {
            $origin = $root . '/hooks/pre-commit.php';
        }

        if (empty($origin)) {
            throw new Exception("Error: the file pre-commit.php not found");
        }

        return $origin;
    }
}
