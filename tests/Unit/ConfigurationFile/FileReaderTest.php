<?php

namespace Tests\Unit\ConfigurationFile;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\{
    FileReaderFake,
    TestCase\UnitTestCase,
    Traits\VirtualFileSystemTrait
};

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

    /** \phpmock\PhpmockMock */
    private $mockRootDirectory;

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
}
