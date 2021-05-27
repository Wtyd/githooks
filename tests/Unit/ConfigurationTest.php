<?php

namespace Tests\Unit;

use Wtyd\GitHooks\Configuration;
use Wtyd\GitHooks\Exception\ToolsIsEmptyException;
use Wtyd\GitHooks\Exception\ToolsNotFoundException;
use Symfony\Component\Yaml\Yaml;
use Tests\Mock;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\VirtualFileSystemTrait;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use phpmock\MockBuilder;
use phpmock\Mock as PhpmockMock;
use PHPUnit\Framework\TestCase;

/**
 * @group Configuration
 * The @runInSeparateProcess annotation and @preserveGlobalState disabled  must be setted because are needed when the full battery of tests are launched.
 * Otherwise other tests failed when they try to use the original class Symfony\Component\Yaml\Yaml.
 */
class ConfigurationTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use VirtualFileSystemTrait;

    protected $yamlReaderMock;

    protected function setUp(): void
    {
        $this->configuration = Mock::mock(Configuration::class)->shouldAllowMockingProtectedMethods()->makePartial();

        $this->configurationFileBuilder = new ConfigurationFileBuilder($this->getUrl(''));
    }

    /**
     * Helps to use ConfigurationFileBuilder in dataProvider methods.
     *
     * @return ConfigurationFileBuilder
     */
    public function setConfigurationFileBuilder(): ConfigurationFileBuilder
    {
        $builder = new ConfigurationFileBuilder($this->getUrl(''));

        return $builder;
    }

    /**
     * Mock 'getcwd' method used in Configuration.php for return the root path of the file system structure created in memory with vfsStream
     *
     * @return PhpmockMock
     */
    public function getMockRootDirectory(): PhpmockMock
    {
        $builder = new MockBuilder();
        $builder->setNamespace('Wtyd\GitHooks')
            ->setName('getcwd')
            ->setFunction(
                function () {
                    return $this->getUrl('');
                }
            );

        return $builder->build();
    }

    public function validConfigurationFilesDataProvider()
    {
        return [
            'From root directory' => [
                ['githooks.yml' => $this->setConfigurationFileBuilder()->buildYalm()]
            ],
            'From qa/ directory' => [
                'qa' => ['githooks.yml' => $this->setConfigurationFileBuilder()->buildYalm()]
            ]
        ];
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState
     * @dataProvider validConfigurationFilesDataProvider
     */
    function it_can_read_file_configuration_githooksDotYml($fileSystemStructure)
    {
        $mock = $this->getMockRootDirectory();
        $mock->enable();

        $this->createFileSystem($fileSystemStructure);

        $this->assertEquals($this->configurationFileBuilder->buildArray(), $this->configuration->readFile());

        $mock->disable();
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState
     * When there are two valid configuration files it always returns the one in the root directory
     */
    function it_searchs_configuration_file_first_in_the_root_directory()
    {
        $mock = $this->getMockRootDirectory();

        $mock->enable();

        $rootFileYalm = $this->configurationFileBuilder->setTools(['phpcs'])->buildYalm();
        $rootFileArray = $this->configurationFileBuilder->buildArray();

        $qaFileYalm = $this->configurationFileBuilder->setTools(['parrallel-lint'])->buildYalm();
        $qaFileArray = $this->configurationFileBuilder->buildArray();

        $fileSystemStructure = [
            'githooks.yml' => $rootFileYalm,
            'qa' => ['githooks.yml' => $qaFileYalm]
        ];

        $this->createFileSystem($fileSystemStructure);

        $fileReaded = $this->configuration->readFile();

        $this->assertEquals($rootFileArray, $fileReaded);

        $this->assertNotEquals($qaFileArray, $fileReaded);

        $mock->disable();
    }

    /** @test */
    public function it_raise_exception_when_configuration_file_is_empty()
    {
        $mock = $this->getMockRootDirectory();
        $mock->enable();

        $fileSystemStructure = [
            'githooks.yml' => '',
        ];

        $this->createFileSystem($fileSystemStructure);


        $this->expectException(ToolsNotFoundException::class);

        $this->configuration->readFile();

        $mock->disable();
    }

    function fileReadedWithNoToolsTagProvider()
    {
        return [
            'Fichero sin Tools tag' => [
                [
                    'Options' => [
                        'execution' => 'full',
                        'OtraOpcion' => null,
                    ],
                    'Otro tag' => [],
                ],
            ],
            'Fichero con tools tag en minusculas' => [
                [
                    'Options' => [
                        'execution' => 'full',
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
     * The 'runInSeparateProcess' annotation and '@preserveGlobalState disabled' must be setted.
     * Otherwise other tests will fail when they try to use the Symfony\Component\Yaml\Yaml class.
     */
    function it_raise_exception_when_there_is_no_tools_tag($fileReaded)
    {
        $this->configuration->shouldReceive('findConfigurationFile');

        $this->yamlReaderMock = Mock::alias(Yaml::class);

        $this->yamlReaderMock->shouldReceive('parseFile')->andReturn($fileReaded);

        $this->expectException(ToolsNotFoundException::class);

        $this->configuration->readfile();
    }

    function fileReadedWithEmptyToolsProvider()
    {
        return [
            'Array Tools vacio' => [
                [
                    'Options' => [
                        'execution' => 'full',
                        'OtraOpcion' => null,
                    ],
                    'Tools' => [],
                ],
            ],
            'Array Tools es null' => [
                [
                    'Options' => [
                        'execution' => 'full',
                        'OtraOpcion' => null,
                    ],
                    'Tools' => null,
                ],
            ],
            'Array Tools es cadena vacia' => [
                [
                    'Options' => [
                        'execution' => 'full',
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
     * The 'runInSeparateProcess' annotation and '@preserveGlobalState disabled' must be setted.
     * Otherwise other tests will fail when they try to use the Symfony\Component\Yaml\Yaml class.
     */
    function it_raise_exception_when_the_tools_tag_is_empty($fileReaded)
    {
        $this->configuration->shouldReceive('findConfigurationFile');

        $this->yamlReaderMock = Mock::alias(Yaml::class);

        $this->yamlReaderMock->shouldReceive('parseFile')->andReturn($fileReaded);

        $this->expectException(ToolsIsEmptyException::class);

        $this->configuration->readfile();
    }
}
