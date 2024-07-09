<?php

namespace Wtyd\GitHooks\App\Commands;

use Exception;
use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Hooks;
use Wtyd\GitHooks\Utils\Printer;
use Wtyd\GitHooks\Utils\Storage;

class CreateHookCommand extends Command
{
    protected $signature = 'hook  {hook=pre-commit : The hook to be setted} {scriptFile? : The custom script to be setted as the hook (default:GitHooks)}';
    protected $description = 'Copies the hook for the GitHooks execution. The default hook is pre-commit. You can pass scriptFile as argument to set custom scripts.';

    /**
     * Extra information about the command invoked with the --help flag.
     *
     * @var string
     */
    protected $help = 'The default script is the default GitHooks execution. You can custom your script to execute what ever you want.
Even the default script and after, other tools which GitHooks not support or vice versa';

    /**
     * @var Printer
     */
    protected $printer;

    /**
     * First argument. The hook that will be setted.
     *
     * @var string
     */
    protected $hook;

    /**
     * Second argument. The custom script to be setted as the hook. Per default is the GitHooks script to execute the tools setted in githooks.yml file.
     *
     * @var string
     */
    protected $scriptFile;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
        parent::__construct();
    }

    public function handle()
    {
        $this->hook = strval($this->argument('hook'));
        $this->scriptFile = strval($this->argument('scriptFile')) ?? '';

        if (!Hooks::validate($this->hook)) {
            $this->printer->error("'{$this->hook}' is not a valid git hook. Avaliable hooks are:");
            $this->printer->error(implode(', ', Hooks::HOOKS));
            return 1;
        }

        $origin = $this->path2OriginFile();
        try {
            $destiny = ".git/hooks/{$this->hook}";

            if (Storage::exists($destiny)) {
                Storage::delete($destiny);
            }

            Storage::copy($origin, $destiny);
            Storage::chmod($destiny, 0755);

            $this->printer->success("Hook {$this->hook} created");
        } catch (\Throwable $th) {
            $this->printer->error("Error copying $origin in {$this->hook}");
            return 1;
        }
    }

    /**
     * Find the origin of the scripts for the hook
     *
     * @return string File to be executed in the hook.
     */
    public function path2OriginFile(): string
    {
        $origin = '';
        if (empty($this->scriptFile)) {
            $origin = $this->defaultPrecommit();
        } else {
            if (!Storage::exists($this->scriptFile)) {
                throw new Exception("{$this->scriptFile} file not found");
            }
            $origin = $this->scriptFile;
        }
        return $origin;
    }

    /**
     * Returns the path of the file that contains the script to be executed in the hook:
     * 1. When GitHooks will be a library, it returns the path through 'vendor'.
     * 2. To work on developing GitHooks itself, it will return the local path to 'hooks'
     *
     * @return string The default script to run GitHooks in the hook.
     */
    public function defaultPrecommit(): string
    {
        $origin = '';
        if (Storage::exists('vendor/wtyd/githooks/hooks/default.php')) {
            $origin = 'vendor/wtyd/githooks/hooks/default.php';
        }

        if (Storage::exists('hooks/default.php')) {
            $origin = 'hooks/default.php';
        }

        if (empty($origin)) {
            throw new Exception("The file default.php not found");
        }

        return $origin;
    }
}
