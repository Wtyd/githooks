<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Execution\ThreadCapability;
use Wtyd\GitHooks\Jobs\CacheResolver\PhpstanCacheResolver;

class PhpstanJob extends JobAbstract
{
    public const SUPPORTS_FAST = true;

    /**
     * PHPStan emits this on stderr when every input file is filtered out by
     * `excludePaths.analyse` of the active config. The wrapper concatenates
     * stderr after stdout in JobExecutor/FlowExecutor before consulting
     * isEmptyInputTolerated(), so a single str_contains over $output is enough.
     */
    private const EMPTY_INPUT_MARKER = 'No files found to analyse';

    protected const ARGUMENT_MAP = [
        'config'             => ['flag' => '-c', 'type' => 'value', 'separator' => ' '],
        'level'              => ['flag' => '-l', 'type' => 'value', 'separator' => ' '],
        'memory-limit'       => ['flag' => '--memory-limit', 'type' => 'value'],
        'error-format'       => ['flag' => '--error-format', 'type' => 'value'],
        'no-progress'        => ['flag' => '--no-progress', 'type' => 'boolean'],
        'clear-result-cache' => ['flag' => '--clear-result-cache', 'type' => 'boolean'],
        'paths'              => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'phpstan';
    }

    protected function getSubcommand(): string
    {
        return 'analyse';
    }

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    public function applyStructuredOutputFormat(): bool
    {
        $this->args['error-format'] = 'json';
        $this->args['no-progress'] = true;
        return true;
    }

    public function getThreadCapability(): ?ThreadCapability
    {
        $workers = $this->detectNeonWorkers();
        return new ThreadCapability('_phpstan_internal', $workers, 1, false);
    }

    public function isEmptyInputTolerated(int $exitCode, string $output): bool
    {
        return $exitCode === 1 && strpos($output, self::EMPTY_INPUT_MARKER) !== false;
    }

    /**
     * Public accessor for the worker count declared in the .neon. Used by
     * cross-flow validators (ConfigurationParser) that must compare the
     * effective phpstan parallelism against each flow's `processes` budget
     * before runtime. Mirrors the private detectNeonWorkers() — same
     * fallback (4) when the .neon is absent or has no
     * `maximumNumberOfProcesses` entry.
     */
    public function getDeclaredNeonWorkers(): int
    {
        return $this->detectNeonWorkers();
    }

    private bool $cacheUnresolvable = false;

    /**
     * @return string[]
     */
    public function getCachePaths(): array
    {
        $this->cacheUnresolvable = false;
        $config = $this->args['config'] ?? '';
        if (!empty($config)) {
            $tmpDir = PhpstanCacheResolver::resolve($config);
            if ($tmpDir !== null) {
                if (strpos($tmpDir, '%') !== false) {
                    // tmpDir contains a NEON placeholder we don't expand
                    // (%env.X%, custom parameters, ...). Falling back to the
                    // default and surfacing a warning is more honest than
                    // returning a literal path that won't exist on disk.
                    $this->cacheUnresolvable = true;
                } else {
                    return [$tmpDir];
                }
            }
        }
        return [sys_get_temp_dir() . '/phpstan'];
    }

    public function getCacheResolutionWarning(): ?string
    {
        if (!$this->cacheUnresolvable) {
            return null;
        }
        return "tmpDir in the .neon contains a placeholder that GitHooks does not expand "
            . "(only %currentWorkingDirectory% and %rootDir% are recognised); "
            . "the cache lives elsewhere — clear it manually";
    }

    /** @SuppressWarnings(PHPMD.UndefinedVariable) preg_match assigns $matches by reference */
    private function detectNeonWorkers(): int
    {
        $config = $this->args['config'] ?? '';
        if (empty($config) || !file_exists($config)) {
            return 4;
        }
        $content = file_get_contents($config);
        if ($content !== false && preg_match('/maximumNumberOfProcesses:\s*(\d+)/', $content, $matches)) {
            return (int) $matches[1];
        }
        return 4;
    }
}
