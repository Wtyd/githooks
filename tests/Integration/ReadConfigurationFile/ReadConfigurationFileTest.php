<?php

use GitHooks\Configuration;
use GitHooks\Exception\ParseConfigurationFileException;
use PHPUnit\Framework\TestCase;

class ReadConfigurationFileTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /**
     * @test
     */
    function it_raise_exeption_when_configuration_file_does_not_exist()
    {
        $this->expectException(ParseConfigurationFileException::class);

        $conf = new Configuration();
        $conf->readfile('my_imaginary_file.yml');
    }

    /** @test*/
    function it_read_configuration_file()
    {
        $conf = new Configuration();
        $result = $conf->readfile(__DIR__ . '/githooks.yml');

        $expected = [
            'Options' => [
                ['smartExecution' => false]
            ],
            'Tools' => [
                'phpstan',
                'dependencyVulnerabilities',
                'parallelLint',
                'phpcs',
                'phpmd',
                'phpcpd',
            ],
            'phpstan' => [
                'config' => './qa/phpstan-phpqa.neon',
                'level' => 1,
            ],
            'parallelLint' => [
                'exclude' => ['qa', 'tests', 'vendor'],
            ],
            'phpcs' => [
                'standard' => './qa/phpcs-softruleset.xml',
                'ignore' => ['qa', 'storage', 'vendor', 'tests', 'bootstrap', 'controllers', 'database'],
                'error-severity' => 1,
                'warning-severity' => 6,
            ],
            'phpmd' => [
                'rules' => './qa/md-rulesheet.xml',
                'exclude' => ['app/Http/Controllers', 'app/Console', 'bootstrap', 'qa', 'storage',  'tests', 'vendor'],
            ],
        ];

        $this->assertSame($expected, $result);
    }
}
