<?php

namespace Tests\System\Release;

use Tests\ConsoleTestCase;
use Tests\ReleaseTestCase;

/**
 * @group release
 */
class NewVersionTest extends ReleaseTestCase
{
    protected $githooks = ConsoleTestCase::TESTS_PATH . '/githooks';


    protected function setUp(): void
    {
        $this->deleteDirStructure();

        $this->hiddenConsoleOutput();

        self::copyReleaseBinary();
    }

    /** @test */
    function it_prints_the_new_version()
    {
        passthru("$this->githooks --version", $exitCode);

        $newVersion = '2.0.0';
        $this->assertStringContainsString("GitHooks $newVersion", $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
    }
}
