<?php

namespace Tests\System;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\PhpFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;

class ExecutableFinderTest extends SystemTestCase
{
    protected $phpFileBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationFileBuilder->setOptions(['execution' => 'full']);

        $this->fileBuilder = new PhpFileBuilder('File');
    }

    public function toolExecutablePathsDataProvider()
    {
        return [
            'Phar' =>  [
                ConfigurationFileBuilder::PHAR_TOOLS_PATH,
            ],
            'Global' =>  [
                ConfigurationFileBuilder::GLOBAL_TOOLS_PATH,
            ],
            'Local' =>  [
                ConfigurationFileBuilder::LOCAL_TOOLS_PATH,
            ],
        ];
    }

    /**
     * @test
     * @dataProvider toolExecutablePathsDataProvider
     */
    function it_runs_all_configured_tools_at_same_time($toolsPath)
    {
        $this->configurationFileBuilder = new ConfigurationFileBuilder($this->path, $toolsPath);
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $this->artisan('tool all')
            ->toolHasBeenExecutedSuccessfully('security-checker')
            ->toolHasBeenExecutedSuccessfully('phpcbf')
            ->toolHasBeenExecutedSuccessfully('phpcpd')
            ->toolHasBeenExecutedSuccessfully('phpmd')
            ->toolHasBeenExecutedSuccessfully('parallel-lint')
            ->toolHasBeenExecutedSuccessfully('phpstan');
    }
}
