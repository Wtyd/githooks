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
    }

    protected function setUp(): void
    {
        // do nothing
        $this->setOutputCallback(function () {
        });
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


    function it_prints_the_new_version()
    {
        passthru("$this->githooksExecutable --version", $exitCode);

        $newVersion = '2.0.0';
        $this->assertStringContainsString("GitHooks $newVersion", $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
    }


    function it_checks_the_configuration_file()
    {
        passthru("$this->githooksExecutable conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString("The key 'invalidOptionTest' is not a valid option", $this->getActualOutput());
    }

    function it_cleans_a_githook()
    {
        $scriptFile = ConsoleTestCase::TESTS_PATH . '/src/File.php';
        copy($scriptFile, '.git/hooks/pre-push');

        passthru("$this->githooksExecutable hook:clean pre-push", $exitCode);

        $this->assertStringContainsString('Hook pre-push has been deleted', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileDoesNotExist('.git/hooks/pre-push');
    }

    /** @test */
    function it_creates_the_configuration_file()
    {
        passthru("$this->githooksExecutable conf:init", $exitCode);

        // $this->assertStringContainsString('Configuration file githooks.yml has been created in root path', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        dd($exitCode);
        $this->assertFileDoesNotExist('.git/hooks/pre-push');
    }

    function it_sets_a_custom_githook()
    {
        $scriptFile = ConsoleTestCase::TESTS_PATH . '/src/File.php';
        passthru("$this->githooksExecutable hook pre-push $scriptFile", $exitCode);

        $this->assertStringContainsString('Hook pre-push created', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileEquals($scriptFile, '.git/hooks/pre-push');
    }
}
