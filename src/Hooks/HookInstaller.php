<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Hooks;

use Wtyd\GitHooks\Hooks as HooksConstants;
use Wtyd\GitHooks\Utils\Platform;

class HookInstaller
{
    private const DEFAULT_COMMAND = 'php vendor/bin/githooks';

    private string $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
    }

    /**
     * Install hooks for the given events using core.hooksPath.
     *
     * @param string[] $events
     * @param string $command The GitHooks command prefix (e.g. 'php7.4 vendor/bin/githooks')
     * @param string $configPath Baked into the scripts as `--config` (see scriptConfigPath());
     *                           empty keeps the default lookup at run time.
     * @return string[] List of created hook file paths
     */
    public function install(array $events, string $command = '', string $configPath = ''): array
    {
        $hooksDir = $this->rootPath . DIRECTORY_SEPARATOR . '.githooks';

        if (!is_dir($hooksDir)) {
            mkdir($hooksDir, 0755, true);
        }

        $script = $this->buildScript($command, $configPath);

        $created = [];
        foreach ($events as $event) {
            if (!HooksConstants::validate($event)) {
                continue;
            }
            $filePath = $hooksDir . DIRECTORY_SEPARATOR . $event;
            file_put_contents($filePath, $script);
            chmod($filePath, 0755);
            $created[] = $filePath;
        }

        $this->configureHooksPath();

        return $created;
    }

    /**
     * Install a single event hook.
     */
    public function installSingle(string $event): ?string
    {
        $result = $this->install([$event]);
        return $result[0] ?? null;
    }

    /**
     * Legacy mode: copy hook script to .git/hooks/ (for Git < 2.9).
     *
     * @param string[] $events
     * @param string $command The GitHooks command prefix
     * @return string[]
     */
    public function installLegacy(array $events, string $command = ''): array
    {
        $gitHooksDir = $this->rootPath . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'hooks';

        if (!is_dir($gitHooksDir)) {
            return [];
        }

        $script = $this->buildScript($command);

        $created = [];
        foreach ($events as $event) {
            if (!HooksConstants::validate($event)) {
                continue;
            }
            $filePath = $gitHooksDir . DIRECTORY_SEPARATOR . $event;
            file_put_contents($filePath, $script);
            chmod($filePath, 0755);
            $created[] = $filePath;
        }

        return $created;
    }

    /**
     * Clean installed hooks: remove .githooks/ dir and unset core.hooksPath.
     */
    public function clean(): void
    {
        $hooksDir = $this->rootPath . DIRECTORY_SEPARATOR . '.githooks';

        if (is_dir($hooksDir)) {
            $files = glob($hooksDir . DIRECTORY_SEPARATOR . '*') ?: [];
            array_map('unlink', $files);
            rmdir($hooksDir);
        }

        $this->unsetHooksPath();
    }

    /**
     * Clean legacy hooks from .git/hooks/.
     *
     * @param string[] $events Events to remove. Empty = remove all known events.
     */
    public function cleanLegacy(array $events = []): void
    {
        $gitHooksDir = $this->rootPath . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'hooks';

        if (empty($events)) {
            $events = HooksConstants::HOOKS;
        }

        foreach ($events as $event) {
            $filePath = $gitHooksDir . DIRECTORY_SEPARATOR . $event;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    /**
     * Path to bake into the scripts as `--config` (BUG-31). Relative to the
     * repository root when the file lives inside it: Git runs hooks from the
     * top level of the working tree, so a committed .githooks/ keeps working
     * for every clone. Absolute otherwise — the caller warns about it. A
     * relative input resolves against the root, like ConfigurationParser does.
     */
    public function scriptConfigPath(string $configFile): string
    {
        $path = Platform::isAbsolutePath($configFile)
            ? $configFile
            : $this->rootPath . DIRECTORY_SEPARATOR . $configFile;
        $absolute = realpath($path) ?: $path;
        $root = rtrim(realpath($this->rootPath) ?: $this->rootPath, '/\\') . DIRECTORY_SEPARATOR;

        if (strpos($absolute, $root) === 0) {
            return str_replace('\\', '/', substr($absolute, strlen($root)));
        }
        return $absolute;
    }

    private function buildScript(string $command, string $configPath = ''): string
    {
        $cmd = !empty($command) ? $command : self::DEFAULT_COMMAND;

        // BUG-31: without `--config` every trigger resolved githooks.php from the
        // CWD, not the file the hooks were installed from. Single-quoted so the
        // shell takes the path literally (spaces included).
        $config = $configPath === ''
            ? ''
            : " --config='" . str_replace("'", "'\\''", $configPath) . "'";

        // Propagate Git's hook arguments ("$@") to the engine (FEAT-16). Git
        // passes the message-file path as $1 to commit-msg (and to
        // prepare-commit-msg / applypatch-msg); without this they were
        // discarded. Transparent for hooks whose jobs don't consume them.
        return "#!/bin/sh\n# Generated by GitHooks — do not edit manually\n"
            . "$cmd hook:run$config \"\$(basename \"\$0\")\" \"\$@\"\n";
    }

    protected function configureHooksPath(): void
    {
        $this->exec('git config core.hooksPath .githooks');
    }

    protected function unsetHooksPath(): void
    {
        $this->exec('git config --unset core.hooksPath');
    }

    private function exec(string $command): void
    {
        $cwd = getcwd();
        chdir($this->rootPath);
        shell_exec($command . ' ' . \Wtyd\GitHooks\Utils\Platform::stderrRedirect());
        if ($cwd !== false) {
            chdir($cwd);
        }
    }
}
