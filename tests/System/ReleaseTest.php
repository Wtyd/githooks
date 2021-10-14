<?php

namespace Tests\System;

use Tests\ConsoleTestCase;
use Tests\Utils\PhpFileBuilder;
use Tests\FileSystemTrait;
use Tests\Utils\ConfigurationFileBuilder;

class ReleaseTest extends ConsoleTestCase
{
    protected $githooksExecutable = ConsoleTestCase::TESTS_PATH . '/githooks';

    public static function setUpBeforeClass(): void
    {
        FileSystemTrait::cleanTestsFilesystem();

        self::copyReleaseBinary();

        // At this point I can't mock the path for githooks.yml. How as the first directory where
        // it looks is in the root I put this file in root directory
        $configurationFileBuilder = new ConfigurationFileBuilder(ConsoleTestCase::TESTS_PATH);
        file_put_contents(
            'githooks.yml',
            $configurationFileBuilder->setOptions(['invalidOptionTest' => 1])->buildYalm()
        );

        mkdir(ConsoleTestCase::TESTS_PATH  . '/src');
        $fileBuilder = new PhpFileBuilder('File');
        file_put_contents(
            ConsoleTestCase::TESTS_PATH . '/src/File.php',
            $fileBuilder->build()
        );
    }

    public static function tearDownAfterClass(): void
    {
        unlink('githooks.yml');
        unlink(ConsoleTestCase::TESTS_PATH  . '/githooks');
    }

    protected function setUp(): void
    {
        // do nothing
        // $this->setOutputCallback(function () {
        // });
    }
    protected function tearDown(): void
    {
        // do nothing
    }

    /**
     * Copies de releases candidate to the tests directory. Only copies the version that works in the current php version
     *
     * @return boolean
     */
    protected static function copyReleaseBinary(): bool
    {
        $origin = version_compare(phpversion(), '7.2.0', '<=') ? 'builds/php7.1/githooks' : 'builds/githooks';
        return copy($origin, ConsoleTestCase::TESTS_PATH . '/githooks');
    }


    function it_creates_the_configuration_file()
    {
        mkdir('vendor/wtyd/githooks/qa/', 0777, true);
        $configurationFileBuilder = new ConfigurationFileBuilder(ConsoleTestCase::TESTS_PATH);
        file_put_contents(
            'vendor/wtyd/githooks/qa/githooks.dist.yml',
            $configurationFileBuilder->buildYalm()
        );

        passthru("$this->githooksExecutable conf:init", $exitCode);

        $this->assertStringContainsString('Configuration file githooks.yml has been created in root path', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileExists('githooks.yml');

        $this->deleteDirStructure('vendor/wtyd');
    }



    /**
     * Checks if the $tool has been executed Successfully by regular expression assert. This assert was renamed and is deprecated
     * sinse phpunit 9.
     *
     * @param string $tool
     * @return void
     */
    protected function assertToolHasBeenExecutedSuccessfully(string $tool): void
    {
        //phpcbf - OK. Time: 0.18
        $this->assertMatchesRegularExpression("%$tool - OK\. Time: \d+\.\d{2}%", $this->getActualOutput(), "The tool $tool has not been executed successfully");
    }

    protected function assertToolHasFailed(string $tool): void
    {
        //phpcbf - KO. Time: 0.18
        $this->assertMatchesRegularExpression("%$tool - KO\. Time: \d+\.\d{2}%", $this->getActualOutput(), "The tool $tool has not failed");
    }
}
