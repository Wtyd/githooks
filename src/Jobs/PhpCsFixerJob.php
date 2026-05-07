<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Jobs\CacheResolver\PhpConfigCacheResolver;

class PhpCsFixerJob extends JobAbstract
{
    public const SUPPORTS_FAST = true;

    protected const ARGUMENT_MAP = [
        'config'      => ['flag' => '--config', 'type' => 'value'],
        'rules'       => ['flag' => '--rules', 'type' => 'value'],
        'dry-run'     => ['flag' => '--dry-run', 'type' => 'boolean'],
        'diff'        => ['flag' => '--show-diff', 'type' => 'boolean'],
        'allow-risky' => ['flag' => '--allow-risky', 'type' => 'value'],
        'using-cache' => ['flag' => '--using-cache', 'type' => 'value'],
        'cache-file'  => ['flag' => '--cache-file', 'type' => 'value'],
        'paths'       => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'php-cs-fixer';
    }

    protected function getSubcommand(): string
    {
        return 'fix';
    }

    /**
     * In non-dry-run mode, exit code 0 means the tool ran successfully and may
     * have applied fixes. Re-staging is safe (idempotent).
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

        $cacheFile = $this->args['cache-file'] ?? '';
        if (is_string($cacheFile) && trim($cacheFile) !== '') {
            return [trim($cacheFile)];
        }

        $configFile = $this->locateConfigFile();
        if ($configFile !== null) {
            $resolved = PhpConfigCacheResolver::resolve($configFile, 'setCacheFile');
            if ($resolved !== null) {
                return [$resolved];
            }
            if (PhpConfigCacheResolver::declaresUnresolvable($configFile, 'setCacheFile')) {
                $this->cacheUnresolvable = true;
            }
        }

        return ['.php-cs-fixer.cache'];
    }

    public function getCacheResolutionWarning(): ?string
    {
        if (!$this->cacheUnresolvable) {
            return null;
        }
        return "could not parse setCacheFile() in .php-cs-fixer.php (uses a variable or helper); "
            . "declare 'cache-file' on the job to override (php-cs-fixer respects --cache-file over the config)";
    }

    private function locateConfigFile(): ?string
    {
        $explicit = $this->args['config'] ?? '';
        if (is_string($explicit) && $explicit !== '' && is_file($explicit) && is_readable($explicit)) {
            return $explicit;
        }
        foreach (['.php-cs-fixer.php', '.php-cs-fixer.dist.php'] as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
