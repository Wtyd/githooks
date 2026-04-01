<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use InvalidArgumentException;
use Wtyd\GitHooks\Configuration\JobConfiguration;

class JobRegistry
{
    private const TYPE_MAP = [
        'phpstan'       => PhpstanJob::class,
        'phpmd'         => PhpmdJob::class,
        'phpcs'         => PhpcsJob::class,
        'phpcbf'        => PhpcbfJob::class,
        'phpunit'       => PhpunitJob::class,
        'psalm'         => PsalmJob::class,
        'parallel-lint' => ParallelLintJob::class,
        'phpcpd'        => PhpcpdJob::class,
        'script'        => ScriptJob::class,
        'custom'        => CustomJob::class,
    ];

    public function isSupported(string $type): bool
    {
        return array_key_exists($type, self::TYPE_MAP);
    }

    public function getClass(string $type): string
    {
        if (!$this->isSupported($type)) {
            throw new InvalidArgumentException("Job type '$type' is not supported.");
        }
        return self::TYPE_MAP[$type];
    }

    public function create(JobConfiguration $config): JobAbstract
    {
        $class = $this->getClass($config->getType());
        /** @var JobAbstract */
        return new $class($config);
    }

    /** @return string[] */
    public function supportedTypes(): array
    {
        return array_keys(self::TYPE_MAP);
    }
}
