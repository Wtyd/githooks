<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * Converts a v2 configuration array (Options/Tools) to v3 PHP format (hooks/flows/jobs).
 */
class ConfigurationMigrator
{
    public const SCRIPT_TOOL = 'script';

    /**
     * Legacy camelCase keys and their canonical v3 form. Mirrors
     * JobConfiguration::DEPRECATED_KEY_MAP: migrating them verbatim produces a
     * v3 file that warns about deprecations the moment it is first read.
     */
    private const DEPRECATED_KEY_MAP = [
        'executablePath'     => 'executable-path',
        'otherArguments'     => 'other-arguments',
        'ignoreErrorsOnExit' => 'ignore-errors-on-exit',
        'failFast'           => 'fail-fast',
    ];

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
        $scriptAlias = $this->scriptAlias($legacyConfig);

        foreach ($tools as $toolName) {
            if (!is_string($toolName)) {
                continue;
            }
            $jobName = $this->toJobName($toolName);
            $jobNames[] = $jobName;

            // A named script is listed in `Tools` by its alias while its
            // configuration stays under the `script` key, so the alias has to be
            // mapped back to that section before the entry is built.
            $isScript = $toolName === self::SCRIPT_TOOL || $toolName === $scriptAlias;
            $sourceKey = $isScript ? self::SCRIPT_TOOL : $toolName;
            $toolConfig = $legacyConfig[$sourceKey] ?? [];

            $jobEntries[$jobName] = $this->buildJobEntry($sourceKey, $toolConfig);
        }

        return $this->renderPhp($processes, $failFast, $jobNames, $jobEntries);
    }

    /**
     * Custom name the v2 `script` tool is listed under in `Tools`, or null when
     * the section is absent or unnamed.
     *
     * @param array<string, mixed> $legacyConfig
     */
    private function scriptAlias(array $legacyConfig): ?string
    {
        $name = $legacyConfig[self::SCRIPT_TOOL]['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
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

        // The v2 `script` tool has no v3 counterpart: it becomes a custom job
        // whose command is executablePath + otherArguments. Named or not — an
        // unnamed one is listed in `Tools` as plain `script`.
        if ($toolName === self::SCRIPT_TOOL) {
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
            $entry[self::DEPRECATED_KEY_MAP[$key] ?? $key] = $value;
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
            $items = array_map(function ($item) {
                return var_export($item, true);
            }, $value);
            $rendered = '[' . implode(', ', $items) . ']';
        }

        return "'$key' => $rendered";
    }
}
