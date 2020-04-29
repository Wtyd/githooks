<?php

namespace Tests\Unit;

use GitHooks\Configuration;
use GitHooks\Exception\ToolsIsEmptyException;
use GitHooks\Exception\ToolsNotFoundException;
use GitHooks\Tools\{
    CodeSniffer,
    CopyPasteDetector,
    DependencyVulnerabilities,
    MessDetector,
    ParallelLint,
    Stan,
};
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @group Configuration
 * Tengo que ponerle a los tests la anotacion runInSeparateProcess y @preserveGlobalState disabled para que cuando en los tests de integración use la clase original no me de error tras ejecutar estos tests
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

    //TODO mover estos tests a donde corresponda
    /**
     * @dataProvider allToolsProvider
     */
    function it_can_load_one_tool($fileReaded, $toolClass, $tool)
    {
        $this->yamlReaderMock->shouldReceive('parseFile')->andReturn($fileReaded);

        $conf = new Configuration('githooks.yml');

        $this->assertCount(1, $conf->getTools());

        $this->assertInstanceOf($toolClass, $conf->getTools()[$tool]);
    }

    function it_execute_full_strategy()
    {
        $fileReaded = [
            'Options' => [
                'OtraOpcion' => null,
            ],
            'Tools' => [
                'phpcs',
                'phpstan',
                'phpmd',
                'phpcpd',
                'parallelLint',
                'dependencyVulnerabilities'
            ],
        ];
        $this->yamlReaderMock->shouldReceive('parseFile')->andReturn($fileReaded);

        $conf = new Configuration('githooks.yml');

        $this->assertCount(6, $conf->getTools());
    }

    function it_execute_smart_strategy_without_discard_any_tool()
    {
        $fileReaded = [
            'Options' => [
                'smartExecution' => true,
                'OtraOpcion' => null,
            ],
            'Tools' => [
                'phpcs',
                'phpstan',
                'phpmd',
                'phpcpd',
                'parallelLint',
                'dependencyVulnerabilities'
            ],
        ];
        $this->yamlReaderMock->shouldReceive('parseFile')->andReturn($fileReaded);

        $conf = new Configuration('githooks.yml');

        $this->assertCount(6, $conf->getTools());
    }


    function it_execute_smart_strategy_discarding_phpcs()
    {
        $this->markTestSkipped("Funcionalidad no terminada");
        $fileReaded = [
            'Options' => [
                'smartExecution' => true,
                'OtraOpcion' => null,
            ],
            'Tools' => [
                'phpcs',
                'phpstan',
                'phpmd',
                'phpcpd',
                'parallelLint',
                'dependencyVulnerabilities'
            ],
            'phpcs' => [
                'standard' => './qa/phpcs-softruleset.xml',
                'ignore' => ['qa','storage'],
            ],
        ];
        $this->yamlReaderMock->shouldReceive('parseFile')->andReturn($fileReaded);

        $conf = new Configuration('githooks.yml');

        $this->assertCount(5, $conf->getTools());

        $this->assertArrayNotHasKey('phpcs', $conf->getTools());
    }
}
