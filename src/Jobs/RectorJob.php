<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Jobs\CacheResolver\PhpConfigCacheResolver;

class RectorJob extends JobAbstract
{
    public const SUPPORTS_FAST = true;

    protected const ARGUMENT_MAP = [
        'config'          => ['flag' => '--config', 'type' => 'value'],
        'dry-run'         => ['flag' => '--dry-run', 'type' => 'boolean'],
        'clear-cache'     => ['flag' => '--clear-cache', 'type' => 'boolean'],
        'no-progress-bar' => ['flag' => '--no-progress-bar', 'type' => 'boolean'],
        'paths'           => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'rector';
    }

    protected function getSubcommand(): string
    {
        return 'process';
    }

    /**
     * In non-dry-run mode, exit code 0 means the tool ran successfully and may
     * have applied refactorings. Re-staging is safe (idempotent).
     * In dry-run mode, no files are changed.
     */
    public function isFixApplied(int $exitCode): bool
    {
        if (!empty($this->args['dry-run'])) {
            return false;
        }

        return $exitCode === 0;
    }

    private bool $cacheUnresolvable = false;

    /** @return string[] */
    public function getCachePaths(): array
    {
        $this->cacheUnresolvable = false;

        $metaArg = $this->args['cache-dir'] ?? '';
        if (is_string($metaArg) && trim($metaArg) !== '') {
            return [trim($metaArg)];
        }

        $configFile = $this->locateConfigFile();
        if ($configFile !== null) {
            $resolved = PhpConfigCacheResolver::resolve($configFile, 'cacheDirectory');
            if ($resolved !== null) {
                return [$resolved];
            }
            if (PhpConfigCacheResolver::declaresUnresolvable($configFile, 'cacheDirectory')) {
                $this->cacheUnresolvable = true;
            }
        }

        return [sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rector_cached_files'];
    }

    public function getCacheResolutionWarning(): ?string
    {
        if (!$this->cacheUnresolvable) {
            return null;
        }
        return "could not parse cacheDirectory() in rector.php (uses a variable or helper); "
            . "declare 'cache-dir' on the job to override (last-resort, see docs)";
    }

    private function locateConfigFile(): ?string
    {
        $explicit = $this->args['config'] ?? '';
        if (is_string($explicit) && $explicit !== '' && is_file($explicit) && is_readable($explicit)) {
            return $explicit;
        }
        if (is_file('rector.php') && is_readable('rector.php')) {
            return 'rector.php';
        }
        return null;
    }
}
