<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * Generates a githooks.php configuration string from detected tools and user choices.
 */
class ConfigurationGenerator
{
    /** @var array<string, array<string, mixed>> Default config per tool type */
    private const TOOL_DEFAULTS = [
        'phpstan' => ['level' => 0],
        'phpcs' => ['standard' => 'PSR12'],
        'phpmd' => ['rules' => 'cleancode,codesize,design,naming,unusedcode'],
        'phpunit' => [],
        'psalm' => [],
        'phpcbf' => ['standard' => 'PSR12'],
        'parallel-lint' => [],
        'phpcpd' => [],
    ];

    /**
     * Generate a githooks.php configuration file content.
     *
     * @param string[] $toolTypes  Selected tool types (e.g. ['phpstan', 'phpcs'])
     * @param string[] $paths      Source directories (e.g. ['src', 'app'])
     * @param string[] $hookEvents Hook events to configure (e.g. ['pre-commit'])
     */
    public function generate(array $toolTypes, array $paths, array $hookEvents): string
    {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'return [';

        $flowName = 'qa';
        $jobNames = [];

        // Generate job names
        $firstPath = $paths[0] ?? 'src';
        foreach ($toolTypes as $type) {
            $jobNames[] = $type . '_' . $firstPath;
        }

        // Hooks section
        if (!empty($hookEvents)) {
            $lines[] = "    'hooks' => [";
            foreach ($hookEvents as $event) {
                $lines[] = "        '$event' => ['$flowName'],";
            }
            $lines[] = '    ],';
            $lines[] = '';
        }

        // Flows section
        $lines[] = "    'flows' => [";
        $lines[] = "        'options' => [";
        $lines[] = "            'fail-fast' => false,";
        $lines[] = "            'processes' => 1,";
        $lines[] = '        ],';
        $jobList = implode("', '", $jobNames);
        $lines[] = "        '$flowName' => [";
        $lines[] = "            'jobs' => ['$jobList'],";
        $lines[] = '        ],';
        $lines[] = '    ],';
        $lines[] = '';

        // Jobs section
        $lines[] = "    'jobs' => [";
        foreach ($toolTypes as $type) {
            $jobName = $type . '_' . $firstPath;
            $defaults = self::TOOL_DEFAULTS[$type] ?? [];

            $lines[] = "        '$jobName' => [";
            $lines[] = "            'type' => '$type',";

            // phpunit uses config file, not paths
            if ($type !== 'phpunit') {
                $pathsStr = $this->renderArray($paths);
                $lines[] = "            'paths' => $pathsStr,";
            }

            foreach ($defaults as $key => $value) {
                $rendered = is_int($value) ? (string) $value : "'$value'";
                $lines[] = "            '$key' => $rendered,";
            }

            $lines[] = '        ],';
        }
        $lines[] = '    ],';

        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param string[] $values
     */
    private function renderArray(array $values): string
    {
        if (count($values) === 1) {
            return "['{$values[0]}']";
        }
        $items = implode("', '", $values);
        return "['$items']";
    }
}
