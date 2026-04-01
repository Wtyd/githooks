<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * Converts a v2 configuration array (Options/Tools) to v3 PHP format (hooks/flows/jobs).
 */
class ConfigurationMigrator
{
    /**
     * @param array<string, mixed> $legacyConfig
     */
    public function migrate(array $legacyConfig): string
    {
        $options = $legacyConfig['Options'] ?? [];
        $tools = $legacyConfig['Tools'] ?? [];

        $processes = $options['processes'] ?? 1;
        $failFast = false;

        $jobEntries = [];
        $jobNames = [];

        foreach ($tools as $toolName) {
            if (!is_string($toolName)) {
                continue;
            }
            $jobName = $this->toJobName($toolName);
            $jobNames[] = $jobName;
            $toolConfig = $legacyConfig[$toolName] ?? [];
            $jobEntries[$jobName] = $this->buildJobEntry($toolName, $toolConfig);
        }

        return $this->renderPhp($processes, $failFast, $jobNames, $jobEntries);
    }

    private function toJobName(string $toolName): string
    {
        return str_replace('-', '_', $toolName);
    }

    /**
     * @param array<string, mixed> $toolConfig
     * @return array<string, mixed>
     */
    private function buildJobEntry(string $toolName, array $toolConfig): array
    {
        $entry = ['type' => $toolName];

        // Special handling for script tool with custom name
        if ($toolName === 'script' && isset($toolConfig['name'])) {
            $entry['type'] = 'custom';
            if (isset($toolConfig['executablePath'])) {
                $script = $toolConfig['executablePath'];
                if (isset($toolConfig['otherArguments'])) {
                    $script .= ' ' . $toolConfig['otherArguments'];
                }
                $entry['script'] = $script;
            }
            return $entry;
        }

        // Copy all config keys except internal ones
        foreach ($toolConfig as $key => $value) {
            if ($key === 'name') {
                continue;
            }
            // usePhpcsConfiguration is not supported in v3
            if ($key === 'usePhpcsConfiguration') {
                continue;
            }
            $entry[$key] = $value;
        }

        return $entry;
    }

    /**
     * @param string[] $jobNames
     * @param array<string, array<string, mixed>> $jobEntries
     */
    private function renderPhp(int $processes, bool $failFast, array $jobNames, array $jobEntries): string
    {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'return [';

        // Hooks
        $lines[] = "    'hooks' => [";
        $lines[] = "        'pre-commit' => ['qa'],";
        $lines[] = '    ],';
        $lines[] = '';

        // Flows
        $lines[] = "    'flows' => [";
        $lines[] = "        'options' => [";
        $lines[] = "            'fail-fast' => " . ($failFast ? 'true' : 'false') . ',';
        $lines[] = "            'processes' => $processes,";
        $lines[] = '        ],';
        $lines[] = "        'qa' => [";
        $lines[] = "            'jobs' => [";
        foreach ($jobNames as $name) {
            $lines[] = "                '$name',";
        }
        $lines[] = '            ],';
        $lines[] = '        ],';
        $lines[] = '    ],';
        $lines[] = '';

        // Jobs
        $lines[] = "    'jobs' => [";
        foreach ($jobEntries as $name => $entry) {
            $lines[] = "        '$name' => [";
            foreach ($entry as $key => $value) {
                $lines[] = '            ' . $this->renderKeyValue($key, $value) . ',';
            }
            $lines[] = '        ],';
        }
        $lines[] = '    ],';

        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param mixed $value
     */
    private function renderKeyValue(string $key, $value): string
    {
        $rendered = var_export($value, true);

        // Clean up array formatting
        if (is_array($value)) {
            $items = array_map(function ($v) {
                return var_export($v, true);
            }, $value);
            $rendered = '[' . implode(', ', $items) . ']';
        }

        return "'$key' => $rendered";
    }
}
