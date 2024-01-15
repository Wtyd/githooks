<?php

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Build\ManageDependencies;
use Wtyd\GitHooks\Utils\Printer;

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

    /**
     * Extra information about the command invoked with the --help flag.
     *
     * @var string
     */
    protected $help = 'Without arguments deletes the pre-commit hook. A optional argument can be the name of another hook. Example: hook:clean pre-push.';

    /**  @var string */
    private $phpVersion;

    /**  @var integer */
    private $deleteDevDependenciesExitCode = -1;

    /**  @var integer */
    private $updateProdDependenciesExitCode = -1;

    private $composer = 'tools/composer';

    public function handle()
    {
        $this->phpVersion = $this->argument('phpVersion') ?? 'php7.1';

        $this->title('Deleting dev dependencies');
        $this->deleteDevDependencies();

        $this->title('Updating prod dependencies');
        $this->updateProdDependencies();
    }

    public function deleteDevDependencies(): void
    {
        $command = $this->phpVersion . ' ' . $this->composer . ' remove --ansi --dev ' . implode(' ', self::DEV_DEPENDENCIES);
        passthru($command, $this->deleteDevDependenciesExitCode);
    }

    public function updateProdDependencies(): void
    {
        $command = $this->phpVersion . ' ' . $this->composer . ' update --ansi';
        passthru($command, $this->updateProdDependenciesExitCode);
    }
}
