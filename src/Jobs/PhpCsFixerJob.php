<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

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

    /** @return string[] */
    public function getCachePaths(): array
    {
        return ['.php-cs-fixer.cache'];
    }
}
