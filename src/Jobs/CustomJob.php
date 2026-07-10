<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Configuration\JobConfiguration;

/**
 * Executes an arbitrary command. Two modes:
 *
 * 1. Structured (with paths): executablePath + paths + otherArguments
 *    Supports fast mode via path filtering, same as standard tools.
 *
 * 2. Legacy (without paths): script is the full command verbatim.
 */
class CustomJob extends JobAbstract
{
    public const SUPPORTS_FAST = false;

    protected const ARGUMENT_MAP = [];

    private string $script;

    private bool $reStage;

    public function __construct(JobConfiguration $config)
    {
        parent::__construct($config);
        $this->script = $config->getConfig()['script'] ?? '';
        $this->reStage = (bool) ($config->getConfig()['re-stage'] ?? false);
    }

    public static function getDefaultExecutable(): string
    {
        return '';
    }

    /**
     * With `re-stage: true`, a successful run (exit 0) is treated as a fix so the
     * scheduler re-stages the tracked files — the same auto-stage the native fixer
     * types get. A non-zero exit means the script failed: never re-stage, never
     * mask the failure. Opt-in only; a plain custom job (linter/checker) is
     * unaffected because the flag defaults to false.
     */
    public function isFixApplied(int $exitCode): bool
    {
        return $this->reStage && $exitCode === 0;
    }

    public function buildCommand(): string
    {
        $paths = $this->args['paths'] ?? [];

        if (!empty($paths)) {
            if ($this->executable !== '') {
                $base = $this->getEffectiveExecutable();
            } else {
                $base = $this->executablePrefix !== ''
                    ? $this->executablePrefix . ' ' . $this->script
                    : $this->script;
            }
            $parts = [$base];
            $parts[] = is_array($paths) ? implode(' ', $paths) : $paths;

            if (!empty($this->args['other-arguments'])) {
                $parts[] = $this->args['other-arguments'];
            }

            if ($this->cliExtraArguments !== '') {
                $parts[] = $this->cliExtraArguments;
            }

            return implode(' ', $parts);
        }

        return $this->buildLegacyCommand();
    }

    /**
     * Legacy mode (no `paths`): the `script` is the full command, verbatim.
     * Order matches the structured branch: prefix → script → other-arguments
     * → cliExtraArguments.
     */
    private function buildLegacyCommand(): string
    {
        $command = $this->executablePrefix !== ''
            ? $this->executablePrefix . ' ' . $this->script
            : $this->script;

        if (!empty($this->args['other-arguments'])) {
            $command .= ' ' . $this->args['other-arguments'];
        }

        if ($this->cliExtraArguments !== '') {
            $command .= ' ' . $this->cliExtraArguments;
        }

        return $command;
    }
}
