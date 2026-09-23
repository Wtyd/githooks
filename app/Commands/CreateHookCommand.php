<?php

namespace Wtyd\GitHooks\App\Commands;

use Exception;
use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Hooks;
use Wtyd\GitHooks\Hooks\HookInstaller;
use Wtyd\GitHooks\Utils\Platform;
use Wtyd\GitHooks\Utils\Printer;
use Wtyd\GitHooks\Utils\Storage;

class CreateHookCommand extends Command
{
    protected $signature = 'hook
                            {hook=pre-commit : The hook to install (default: pre-commit). When using v3 config, installs all hooks defined in the config.}
                            {scriptFile? : Custom script file for legacy mode}
                            {--legacy : Force legacy installation (.git/hooks/ instead of core.hooksPath)}
                            {--config= : Path to configuration file}';

    protected $description = 'Install git hooks. With v3 config, uses core.hooksPath and installs all configured hooks.';

    protected Printer $printer;

    private ConfigurationParser $parser;

    private HookInstaller $installer;

    public function __construct(Printer $printer, ConfigurationParser $parser, HookInstaller $installer)
    {
        $this->printer = $printer;
        $this->parser = $parser;
        $this->installer = $installer;
        parent::__construct();
    }

    public function handle()
    {
        $hook = strval($this->argument('hook'));

        // If a custom script file is provided, always use legacy mode
        if (!empty($this->argument('scriptFile'))) {
            return $this->handleLegacy();
        }

        // If the hook argument is not a valid git hook, report error
        // (catches cases like: hook MyScript.php where scriptFile is missing)
        if (!Hooks::validate($hook)) {
            $this->printer->error("'$hook' is not a valid git hook. Available hooks are:");
            $this->printer->error(implode(', ', Hooks::HOOKS));
            return 1;
        }

        if ($this->option('legacy')) {
            return $this->handleLegacy();
        }

        return $this->handleV3();
    }

    private function handleV3(): int
    {
        $configFile = strval($this->option('config'));

        try {
            $config = $this->parser->parse($configFile);
        } catch (\Throwable $e) {
            // No config found — fall back to legacy single-hook install
            return $this->handleLegacy();
        }

        if ($config->isLegacy()) {
            return $this->handleLegacy(true);
        }

        $hooks = $config->getHooks();

        if ($hooks === null || empty($hooks->getEvents())) {
            $this->printer->warning("No hooks defined in configuration. Nothing to install.");
            return 0;
        }

        $events = $hooks->getEvents();
        $command = $hooks->getCommand();
        $scriptConfig = $this->scriptConfigPath($configFile);
        $created = $this->installer->install($events, $command, $scriptConfig);

        foreach ($created as $path) {
            $event = basename($path);
            $this->printer->success("Hook $event installed");
        }

        $this->info("  hooks path: .githooks (configured via core.hooksPath)");
        if ($scriptConfig !== '') {
            $this->info("  config: $scriptConfig (baked into the hook scripts as --config)");
        }

        return 0;
    }

    /**
     * BUG-31: the scripts must run the configuration they were installed from,
     * not whatever githooks.php the CWD resolves to at each trigger. Empty when
     * no --config was given (default lookup, unchanged). A file outside the
     * repository can only be baked as an absolute path other clones will not
     * have — say so.
     */
    private function scriptConfigPath(string $configFile): string
    {
        if ($configFile === '') {
            return '';
        }

        $scriptConfig = $this->installer->scriptConfigPath($configFile);
        if (Platform::isAbsolutePath($scriptConfig)) {
            $this->printer->warning(
                "--config '$configFile' is outside the repository: the hooks reference the absolute path "
                . "'$scriptConfig' and will not work for other clones."
            );
        }

        return $scriptConfig;
    }

    /**
     * @param bool $legacyConfig The configuration is still in v2 format, so the
     *        script has to drive the v2 entry point. `hook:run` reads
     *        hooks/flows/jobs and rejects a v2 file outright, which would leave
     *        every commit blocked by a hook this command had just reported as
     *        installed.
     */
    private function handleLegacy(bool $legacyConfig = false): int
    {
        $hook = strval($this->argument('hook'));
        $scriptFile = strval($this->argument('scriptFile'));

        if (!Hooks::validate($hook)) {
            $this->printer->error("'$hook' is not a valid git hook. Available hooks are:");
            $this->printer->error(implode(', ', Hooks::HOOKS));
            return 1;
        }

        if (!empty($scriptFile)) {
            return $this->installCustomScript($hook, $scriptFile);
        }

        try {
            $destiny = ".git/hooks/$hook";

            if (Storage::exists($destiny)) {
                Storage::delete($destiny);
            }

            Storage::put($destiny, $this->legacyScript($legacyConfig));
            Storage::chmod($destiny, 0755);

            $this->printer->success("Hook $hook created");
            if ($legacyConfig) {
                $this->printer->warning(
                    "The configuration is still in v2 format, so the hook runs 'tool all'. "
                    . "Run 'githooks conf:migrate' to move to v3 and get one hook per configured event."
                );
            }
            return 0;
        } catch (\Throwable $th) {
            $this->printer->error("Error installing hook $hook");
            return 1;
        }
    }

    /**
     * The command the generated script drives. `hook:run` is the v3 entry point
     * and rejects a v2 configuration; `tool all` is the v2 one, which is what
     * the script generated before v3 called and still the only thing that works
     * against a v2 file. `--config` travels along for the same reason it does
     * in v3 (BUG-31): each trigger must run the configuration the hook was
     * installed from, not whatever the working directory resolves to.
     */
    private function legacyScript(bool $legacyConfig): string
    {
        $config = $this->scriptConfigPath(strval($this->option('config')));
        $configFlag = $config === '' ? '' : " --config='$config'";

        $command = $legacyConfig
            ? "tool all$configFlag"
            : "hook:run$configFlag \"$(basename \"$0\")\"";

        return "#!/bin/sh\n# Generated by GitHooks — do not edit manually\n"
            . "php vendor/bin/githooks $command\n";
    }

    private function installCustomScript(string $hook, string $scriptFile): int
    {
        if (!Storage::exists($scriptFile)) {
            $this->printer->error("$scriptFile file not found");
            return 1;
        }

        try {
            $destiny = ".git/hooks/$hook";

            if (Storage::exists($destiny)) {
                Storage::delete($destiny);
            }

            Storage::copy($scriptFile, $destiny);
            Storage::chmod($destiny, 0755);

            $this->printer->success("Hook $hook created with custom script $scriptFile");
            return 0;
        } catch (\Throwable $th) {
            $this->printer->error("Error copying $scriptFile to $hook");
            return 1;
        }
    }
}
