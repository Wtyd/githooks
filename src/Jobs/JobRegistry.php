<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use InvalidArgumentException;
use ReflectionClass;
use Wtyd\GitHooks\Configuration\JobConfiguration;

class JobRegistry
{
    private const TYPE_MAP = [
        'phpstan'        => PhpstanJob::class,
        'phpmd'          => PhpmdJob::class,
        'phpcs'          => PhpcsJob::class,
        'phpcbf'         => PhpcbfJob::class,
        'phpunit'        => PhpunitJob::class,
        'paratest'       => ParatestJob::class,
        'psalm'          => PsalmJob::class,
        'parallel-lint'  => ParallelLintJob::class,
        'phpcpd'         => PhpcpdJob::class,
        'php-cs-fixer'   => PhpCsFixerJob::class,
        'rector'         => RectorJob::class,
        'script'         => ScriptJob::class,
        'custom'         => CustomJob::class,
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

    /**
     * Get the default executable name for a job type without instantiation.
     */
    public function getDefaultExecutable(string $type): string
    {
        if (!$this->isSupported($type)) {
            return '';
        }
        /** @var class-string<JobAbstract> */
        $class = self::TYPE_MAP[$type];
        return $class::getDefaultExecutable();
    }

    /**
     * Whether a job type supports fast execution (path filtering) by default.
     */
    public function isAccelerable(string $type): bool
    {
        if (!$this->isSupported($type)) {
            return false;
        }
        $class = self::TYPE_MAP[$type];
        return defined("$class::SUPPORTS_FAST") && $class::SUPPORTS_FAST;
    }

    /**
     * Get the ARGUMENT_MAP for a job type (for validation without instantiation).
     *
     * @return array<string, array<string, string>>
     */
    public function getArgumentMap(string $type): array
    {
        if (!$this->isSupported($type)) {
            return [];
        }
        $class = self::TYPE_MAP[$type];
        $reflection = new ReflectionClass($class);
        if ($reflection->hasConstant('ARGUMENT_MAP')) {
            /** @var array<string, array<string, string>> */
            return $reflection->getConstant('ARGUMENT_MAP');
        }
        return [];
    }
}
