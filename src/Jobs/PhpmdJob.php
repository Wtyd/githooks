<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Configuration\JobConfiguration;

/**
 * phpmd has unique positional ordering: executable paths ansi rules [flags]
 * This requires a custom buildCommand().
 */
class PhpmdJob extends JobAbstract
{
    protected const ARGUMENT_MAP = [
        'exclude'        => ['flag' => '--exclude', 'type' => 'csv', 'separator' => ' '],
        'cache'          => ['flag' => '--cache', 'type' => 'boolean'],
        'cache-file'     => ['type' => 'key_value'],
        'cache-strategy' => ['type' => 'key_value'],
        'suffixes'       => ['type' => 'key_value'],
        'baseline-file'  => ['type' => 'key_value'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'phpmd';
    }

    /** @SuppressWarnings(PHPMD.CyclomaticComplexity) PHPMD requires positional args + flag iteration */
    public function buildCommand(): string
    {
        $command = $this->executable;

        // Positional: paths (comma-separated), format, rules
        $paths = $this->args['paths'] ?? [];
        $command .= ' ' . (is_array($paths) ? implode(',', $paths) : $paths);
        $command .= ' ansi';
        $command .= ' ' . ($this->args['rules'] ?? 'cleancode,codesize,design,naming,unusedcode');

        // Flags from ARGUMENT_MAP
        foreach (static::ARGUMENT_MAP as $key => $spec) {
            if (!array_key_exists($key, $this->args) || empty($this->args[$key])) {
                continue;
            }
            $value = $this->args[$key];
            $flag = $spec['flag'] ?? '';
            switch ($spec['type']) {
                case 'csv':
                    $list = is_array($value) ? implode(',', $value) : $value;
                    $sep = $spec['separator'] ?? '=';
                    $command .= " $flag" . $sep . '"' . $list . '"';
                    break;
                case 'boolean':
                    $command .= " $flag";
                    break;
                case 'key_value':
                    $command .= " --$key=$value";
                    break;
            }
        }

        if (!empty($this->args['otherArguments'])) {
            $command .= ' ' . $this->args['otherArguments'];
        }

        return $command;
    }
}
