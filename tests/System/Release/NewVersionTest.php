<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/** @group release */
class NewVersionTest extends ReleaseTestCase
{
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

        $branch = trim(shell_exec('git rev-parse --abbrev-ref HEAD'));
        $newVersion = substr($branch, strlen('rc-'));
        $this->assertStringContainsString("GitHooks $newVersion", $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
    }
}
