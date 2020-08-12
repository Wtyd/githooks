<?php

namespace Tests\Unit;

use GitHooks\Configuration;
use GitHooks\Exception\ToolsIsEmptyException;
use GitHooks\Exception\ToolsNotFoundException;
use GitHooks\Tools\CodeSniffer;
use GitHooks\Tools\CopyPasteDetector;
use GitHooks\Tools\DependencyVulnerabilities;
use GitHooks\Tools\MessDetector;
use GitHooks\Tools\ParallelLint;
use GitHooks\Tools\Stan;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @group Configuration
 * The runInSeparateProcess annotation and @preserveGlobalState disabled  must be setted because are neede when the full battery of tests are launched.
 * Otherwise this tests failed when the integration tests try to use the original class Symfony\Component\Yaml\Yaml.
 */
class ConfigurationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected $yamlReaderMock;

    protected function setUp(): void
    {
        $this->yamlReaderMock = Mockery::mock('alias:Symfony\Component\Yaml\Yaml');
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *  */
    public function it_raise_exception_when_configuration_file_is_empty()
    {
        $this->expectException(ToolsNotFoundException::class);

        $this->yamlReaderMock->shouldReceive('parseFile')->andReturn(null);

        $conf = new Configuration();
        $conf->readfile('githooks.yml');
    }

    function fileReadedWithNoToolsTagProvider()
    {
        return [
            'Fichero sin Tools tag' => [
                [
                    'Options' => [
                        'smartExecution' => true,
                        'OtraOpcion' => null,
                    ],
                    'Otro tag' => [],
                ],
            ],
            'Fichero con tools tag en minusculas' => [
                [
                    'Options' => [
                        'smartExecution' => true,
                        'OtraOpcion' => null,
                    ],
                    'tools' => null,
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider fileReadedWithNoToolsTagProvider
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    function it_raise_exception_when_there_is_no_tools_tag($fileReaded)
    {
        $this->expectException(ToolsNotFoundException::class);

        $this->yamlReaderMock->shouldReceive('parseFile')->andReturn($fileReaded);

        $conf = new Configuration();
        $conf->readfile('githooks.yml');
    }

    function fileReadedWithEmptyToolsProvider()
    {
        return [
            'Array Tools vacio' => [
                [
                    'Options' => [
                        'smartExecution' => true,
                        'OtraOpcion' => null,
                    ],
                    'Tools' => [],
                ],
            ],
            'Array Tools es null' => [
                [
                    'Options' => [
                        'smartExecution' => true,
                        'OtraOpcion' => null,
                    ],
                    'Tools' => null,
                ],
            ],
            'Array Tools es cadena vacia' => [
                [
                    'Options' => [
                        'smartExecution' => true,
                        'OtraOpcion' => null,
                    ],
                    'Tools' => '',
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider fileReadedWithEmptyToolsProvider
     * @runInSeparateProcess
     * @preserveGlobalState disabled
    */
    function it_raise_exception_when_the_tools_tag_is_empty($fileReaded)
    {
        $this->expectException(ToolsIsEmptyException::class);

        $this->yamlReaderMock->shouldReceive('parseFile')->andReturn($fileReaded);

        $conf = new Configuration();
        $conf->readfile('githooks.yml');
    }

    function allToolsProvider()
    {
        return [
            'Php Code Sniffer' => [
                [
                    'Tools' => ['phpcs'],
                ],
                CodeSniffer::class,
                'phpcs'
            ],
            'Php Stan' => [
                [
                    'Tools' => ['phpstan'],
                ],
                Stan::class,
                'phpstan'
            ],
            'Php Mess Detector' => [
                [
                    'Tools' => ['phpmd'],
                ],
                MessDetector::class,
                'phpmd'
            ],
            'Php Copy Paste Detector' => [
                [
                    'Tools' => ['phpcpd'],
                ],
                CopyPasteDetector::class,
                'phpcpd'
            ],
            'Parallel-Lint' => [
                [
                    'Tools' => ['parallelLint'],
                ],
                ParallelLint::class,
                'parallelLint'
            ],
            'Composer Check-security' => [
                [
                    'Tools' => ['dependencyVulnerabilities'],
                ],
                DependencyVulnerabilities::class,
                'dependencyVulnerabilities'
            ],
        ];
    }
}
