<?php

namespace Tests\Unit\ConfigurationFile;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Utils\{
    ConfigurationFileBuilder,
    TestCase\UnitTestCase,
    Traits\VirtualFileSystemTrait
};
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ParseConfigurationFileException;
use Wtyd\GitHooks\ConfigurationFile\FileReaderFake;

/**
 * @group Configuration
 * The @runInSeparateProcess annotation and @preserveGlobalState disabled  must be setted because are needed when the full battery of tests are launched.
 * Otherwise other tests failed when they try to use the original class Symfony\Component\Yaml\Yaml.
 */
class FileReaderTest extends UnitTestCase
{
    use MockeryPHPUnitIntegration;
    use VirtualFileSystemTrait;

    /** \Wtyd\GitHooks\ConfigurationFile\FileReader */
    private $fileReader;

    /** \Tests\Utils\ConfigurationFileBuilder */
    private $configurationFileBuilder;

    protected function setUp(): void
    {
        $this->fileReader = new FileReaderFake($this->getUrl(''));
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

    /**
    * @test
    * @expectedException \Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException
    */
    function it_throws_exception_when_configuration_file_not_found()
    {
        $this->createFileSystem([]);
        $this->expectException(ConfigurationFileNotFoundException::class);
        $this->fileReader->readFile();
    }

    /**
     * @test
     * @expectedException \Wtyd\GitHooks\ConfigurationFile\Exception\ParseConfigurationFileException
     */
    function it_throws_exception_when_yaml_file_is_invalid()
    {
        $invalidYaml = "invalid: yaml: content";
        $fileSystemStructure = ['githooks.yml' => $invalidYaml];
        $this->createFileSystem($fileSystemStructure);

        $this->expectException(ParseConfigurationFileException::class);
        $this->fileReader->readFile();
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    function it_throws_exception_when_file_type_is_not_supported()
    {
        $fileSystemStructure = ['githooks.txt' => 'unsupported content'];
        $this->createFileSystem($fileSystemStructure);

        $this->expectException(ConfigurationFileNotFoundException::class);
        $this->fileReader->readFile();
    }
}
