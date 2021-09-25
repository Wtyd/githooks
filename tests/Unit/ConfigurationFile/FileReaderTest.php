<?php

namespace Tests\Unit\ConfigurationFile;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\VirtualFileSystemTrait;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use phpmock\MockBuilder;
use phpmock\Mock as PhpmockMock;
use Tests\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\FileReader;

/**
 * @group Configuration
 * The @runInSeparateProcess annotation and @preserveGlobalState disabled  must be setted because are needed when the full battery of tests are launched.
 * Otherwise other tests failed when they try to use the original class Symfony\Component\Yaml\Yaml.
 */
class FileReaderTest extends UnitTestCase
{
    use MockeryPHPUnitIntegration;
    use VirtualFileSystemTrait;

    protected function setUp(): void
    {
        $this->fileReader = new FileReader();
        // Mock::mock(FileReader::class)->shouldAllowMockingProtectedMethods()->makePartial();

        $this->configurationFileBuilder = new ConfigurationFileBuilder($this->getUrl(''));

        $this->mockRootDirectory = $this->getMockRootDirectory();

        $this->mockRootDirectory->enable();
    }

    protected function tearDown(): void
    {
        $this->mockRootDirectory->disable();
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
     * Mock 'getcwd' method used in FileReader.php for return the root path of the file system structure created in memory with vfsStream
     *
     * @return PhpmockMock
     */
    public function getMockRootDirectory(): PhpmockMock
    {
        $builder = new MockBuilder();
        $reflection = new \ReflectionClass(FileReader::class);
        $builder->setNamespace($reflection->getNamespaceName())
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
     * @dataProvider validConfigurationFilesDataProvider
     */
    function it_can_read_file_configuration_githooksDotYml($fileSystemStructure)
    {
        $this->createFileSystem($fileSystemStructure);

        $this->assertEquals($this->configurationFileBuilder->buildArray(), $this->fileReader->readFile());
    }

    /**
     * @test
     * When there are two valid configuration files it always returns the one in the root directory
     */
    function it_searchs_configuration_file_first_in_the_root_directory()
    {
        $rootFileYalm = $this->configurationFileBuilder->setTools(['phpcs'])->buildYalm();
        $rootFileArray = $this->configurationFileBuilder->buildArray();

        $qaFileYalm = $this->configurationFileBuilder->setTools(['parrallel-lint'])->buildYalm();
        $qaFileArray = $this->configurationFileBuilder->buildArray();

        $fileSystemStructure = [
            'githooks.yml' => $rootFileYalm,
            'qa' => ['githooks.yml' => $qaFileYalm]
        ];

        $this->createFileSystem($fileSystemStructure);

        $fileReaded = $this->fileReader->readFile();

        $this->assertEquals($rootFileArray, $fileReaded);

        $this->assertNotEquals($qaFileArray, $fileReaded);
    }
}
