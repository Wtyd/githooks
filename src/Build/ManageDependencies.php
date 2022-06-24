<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Build;

/**
 * Only puts in the build the prod dependencies which decreases the binary in half.
 */
class ManageDependencies
{
    public const DEV_DEPENDENCIES = [
        // 'intonate/tinker-zero', // No way to use build command without tinker
        'mikey179/vfsstream',
        'mockery/mockery',
        'php-mock/php-mock',
        'php-parallel-lint/php-parallel-lint',
        'phpmd/phpmd',
        'phpstan/phpstan',
        'phpunit/php-code-coverage',
        'phpunit/phpunit',
        'squizlabs/php_codesniffer',
        'fakerphp/faker',
    ];

    /**  @var integer */
    protected $deleteDevDependenciesExitCode = -1;

    /**  @var integer */
    protected $updateProdDependenciesExitCode = -1;

    public function deleteDevDependencies(): void
    {
        $command = 'composer remove --ansi --dev ' . implode(' ', self::DEV_DEPENDENCIES);

        passthru($command, $this->deleteDevDependenciesExitCode);
    }

    public function updateProdDependencies(): void
    {
        passthru('composer update  --ansi', $this->updateProdDependenciesExitCode);
    }
}
