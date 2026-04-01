<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Configuration\JobConfiguration;

/**
 * Executes an arbitrary script verbatim. No argument map — the 'script' config
 * value IS the command.
 */
class CustomJob extends JobAbstract
{
    protected const ARGUMENT_MAP = [];

    private string $script;

    public function __construct(JobConfiguration $config)
    {
        parent::__construct($config);
        $this->script = $config->getConfig()['script'] ?? '';
    }

    public static function getDefaultExecutable(): string
    {
        return '';
    }

    public function buildCommand(): string
    {
        if ($this->context !== null && $this->context->isFastMode()) {
            $files = implode("\n", $this->context->getStagedFiles());
            return 'GITHOOKS_STAGED_FILES=' . escapeshellarg($files) . ' ' . $this->script;
        }

        return $this->script;
    }
}
