<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Execution\ThreadCapability;

class PhpstanJob extends JobAbstract
{
    public const SUPPORTS_FAST = true;

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

    /**
     * @return string[]
     * @SuppressWarnings(PHPMD.UndefinedVariable) preg_match assigns $matches by reference
     */
    public function getCachePaths(): array
    {
        $config = $this->args['config'] ?? '';
        if (!empty($config) && file_exists($config)) {
            $content = file_get_contents($config);
            if ($content !== false && preg_match('/tmpDir:\s*(.+)/', $content, $matches)) {
                return [trim($matches[1])];
            }
        }
        return [sys_get_temp_dir() . '/phpstan'];
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
