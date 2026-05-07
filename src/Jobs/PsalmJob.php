<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Execution\ThreadCapability;

class PsalmJob extends JobAbstract
{
    public const SUPPORTS_FAST = true;

    protected const ARGUMENT_MAP = [
        'config'        => ['type' => 'key_value'],
        'memory-limit'  => ['type' => 'key_value'],
        'threads'       => ['type' => 'key_value'],
        'output-format' => ['type' => 'key_value'],
        'plugin'        => ['type' => 'key_value'],
        'use-baseline'  => ['type' => 'key_value'],
        'report'        => ['type' => 'key_value'],
        'no-diff'       => ['flag' => '--no-diff', 'type' => 'boolean'],
        'paths'         => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'psalm';
    }

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    public function applyStructuredOutputFormat(): bool
    {
        $this->args['output-format'] = 'json';
        return true;
    }

    /** @return string[] */
    public function getCachePaths(): array
    {
        $config = $this->args['config'] ?? '';
        if (!empty($config) && is_file($config) && is_readable($config)) {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($config);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($xml !== false && isset($xml['cacheDirectory'])) {
                $dir = trim((string) $xml['cacheDirectory']);
                if ($dir !== '') {
                    return [$this->resolveRelativeToConfig($dir, $config)];
                }
            }
        }
        return ['.psalm/cache/'];
    }

    private function resolveRelativeToConfig(string $path, string $configFile): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }
        return dirname($configFile) . DIRECTORY_SEPARATOR . $path;
    }

    public function getThreadCapability(): ?ThreadCapability
    {
        $current = isset($this->args['threads']) ? (int) $this->args['threads'] : 1;
        return new ThreadCapability('threads', $current);
    }

    public function applyThreadLimit(int $threads): void
    {
        $this->args['threads'] = (string) $threads;
    }
}
