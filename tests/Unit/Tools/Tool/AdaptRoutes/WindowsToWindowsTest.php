<?php

namespace Tests\Unit\Tools\Tool\AdaptRoutes;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\{
    CodeSniffer\PhpcbfFake,
    CodeSniffer\PhpcsFake,
    ParallelLintFake,
    PhpcpdFake,
    PhpmdFake,
    PhpstanFake,
    SecurityCheckerFake
};

/**
 * @group windows
 * This tests only works in Windows
 */
class WindowsToWindowsTest extends TestCase
{
    /** @test */
    function it_adapts_routes_of_phpcbf()
    {
        $configuration = [
            'executablePath' => 'path\tools\phpcbf',
            'paths' => ['path\src', 'path\tests'],
            'standard' => 'path\to\rules.xml',
            'ignore' => ['path\to\vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2'
        ];

        $toolConfiguration = new ToolConfiguration('phpcbf', $configuration);

        $phpcbf = new PhpcbfFake($toolConfiguration);

        $configuration['executablePath'] = 'path\tools\phpcbf';
        $configuration['paths'] = ['path\src', 'path\tests'];
        $configuration['standard'] = 'path\to\rules.xml';
        $configuration['ignore'] =  ['path\to\vendor'];
        $this->assertEquals($configuration, $phpcbf->getArguments());
    }

    /** @test */
    function it_adapts_routes_of_phpcs()
    {
        $configuration = [
            'executablePath' => 'path\tools\phpcs',
            'paths' => ['path\src', 'path\tests'],
            'standard' => 'path\to\rules.xml',
            'ignore' => ['path\to\vendor'],
            'error-severity' => 1,
            'warning-severity' => 6,
            'otherArguments' => '--report=summary --parallel=2'
        ];

        $toolConfiguration = new ToolConfiguration('phpcs', $configuration);

        $phpcbf = new PhpcsFake($toolConfiguration);

        $configuration['executablePath'] = 'path\tools\phpcs';
        $configuration['paths'] = ['path\src', 'path\tests'];
        $configuration['standard'] = 'path\to\rules.xml';
        $configuration['ignore'] =  ['path\to\vendor'];
        $this->assertEquals($configuration, $phpcbf->getArguments());
    }

    /** @test */
    function it_adapts_routes_of_parallelLint()
    {
        $configuration = [
            'executablePath' => 'path\tools\parallel-lint',
            'paths' => ['path\src', 'path\tests'],
            'exclude' => ['path\to\vendor'],
            'otherArguments' => '--colors',
        ];

        $toolConfiguration = new ToolConfiguration('parallel-lint', $configuration);

        $parallelLint = new ParallelLintFake($toolConfiguration);

        $configuration['executablePath'] = 'path\tools\parallel-lint';
        $configuration['paths'] = ['path\src', 'path\tests'];
        $configuration['exclude'] =  ['path\to\vendor'];
        $this->assertEquals($configuration, $parallelLint->getArguments());
    }

    /** @test */
    function it_adapts_routes_of_phpcpd()
    {
        $configuration = [
            'executablePath' => 'path\tools\phpcpd',
            'paths' => ['path\src', 'path\tests'],
            'exclude' => ['path\to\vendor'],
            'otherArguments' => '--min-lines=5',
        ];

        $toolConfiguration = new ToolConfiguration('phpcpd', $configuration);

        $phpcpd = new PhpcpdFake($toolConfiguration);

        $configuration['executablePath'] = 'path\tools\phpcpd';
        $configuration['paths'] = ['path\src', 'path\tests'];
        $configuration['exclude'] =  ['path\to\vendor'];
        $this->assertEquals($configuration, $phpcpd->getArguments());
    }

    /** @test */
    function it_adapts_routes_of_phpmd()
    {
        $configuration = [
            'executablePath' => 'path\tools\phpmd',
            'paths' => ['path\src', 'path\tests'],
            'rules' => 'path\to\rules.xml',
            'exclude' => ['path\to\vendor'],
            'otherArguments' => '--strict',
        ];

        $toolConfiguration = new ToolConfiguration('phpmd', $configuration);

        $phpmd = new PhpmdFake($toolConfiguration);

        $configuration['executablePath'] = 'path\tools\phpmd';
        $configuration['paths'] = ['path\src', 'path\tests'];
        $configuration['rules'] = 'path\to\rules.xml';
        $configuration['exclude'] =  ['path\to\vendor'];
        $this->assertEquals($configuration, $phpmd->getArguments());
    }

    /** @test */
    function set_all_arguments_of_securityChecker_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path\tools\security-checker',
            'otherArguments' => '-format json',
        ];

        $toolConfiguration = new ToolConfiguration('security-checker', $configuration);

        $securityChecker = new SecurityCheckerFake($toolConfiguration);

        $configuration['executablePath'] = 'pat\/tools\security-checker';
        $this->assertEquals($configuration, $securityChecker->getArguments());
    }

    /** @test */
    function it_adapts_routes_of_phpstan()
    {
        $configuration = [
            'executablePath' => 'path\tools\phpstan',
            'paths' => ['path\src', 'path\tests'],
            'config' => 'path\phpstan.neon',
            'memory-limit' => '1G',
            'level' => 5,
            'otherArguments' => '--no-progress',
        ];

        $toolConfiguration = new ToolConfiguration('phpstan', $configuration);

        $phpstan = new PhpstanFake($toolConfiguration);

        $configuration['executablePath'] = 'path\tools\phpstan';
        $configuration['paths'] = ['path\src', 'path\tests'];
        $configuration['config'] =  'path\phpstan.neon';
        $this->assertEquals($configuration, $phpstan->getArguments());
    }
}
