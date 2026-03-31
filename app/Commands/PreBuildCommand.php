<?php

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;

class PreBuildCommand extends Command
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

    protected $signature = 'app:pre-build {phpVersion? : Version of php to use. Default is the current version.}';
    protected $description = 'Prepares the app dependencies for the build processs';

    protected $help = 'Without arguments deletes the pre-commit hook. A optional argument can be the name of another hook. Example: hook:clean pre-push.';

    private string $phpVersion;

    private int $deleteDevDependenciesExitCode = 0;

    private int $updateProdDependenciesExitCode = 0;

    private string $composer = 'tools/composer';

    public function handle()
    {
        $this->phpVersion = $this->argument('phpVersion') ?? 'php7.4';

        $this->title('Delete Dev Dependencies and Update Prod Dependencies');

        $this->task(
            '   <fg=yellow>1. Deleting dev dependencies</>',
            $this->deleteDevDependencies()
        );

        $this->task(
            '   <fg=yellow>2. Updating prod dependencies</>',
            $this->updateProdDependencies()
        );
    }

    private function deleteDevDependencies(): void
    {
        $command = $this->phpVersion . ' ' . $this->composer . ' remove --ansi --dev ' . implode(' ', self::DEV_DEPENDENCIES);
        passthru($command, $this->deleteDevDependenciesExitCode);

        if ($this->deleteDevDependenciesExitCode != 0) {
            exit($this->deleteDevDependenciesExitCode);
        }
    }

    private function updateProdDependencies(): void
    {
        $command = $this->phpVersion . ' ' . $this->composer . ' update --ansi';
        passthru($command, $this->updateProdDependenciesExitCode);

        if ($this->updateProdDependenciesExitCode != 0) {
            exit($this->updateProdDependenciesExitCode);
        }
    }
}
