<?php

namespace Tests\System\Commands;

use Tests\ConsoleTestCase;

class PruebasTest extends ConsoleTestCase
{
    /** @test */
    function prueba123002834()
    {

        $this->markTestIncomplete('Incomplete');
        $this->artisan('conf:check')
            ->containsStringInOutput("Checking the configuration file:\n")
            ->containsStringInOutput('The file githooks.yml has the correct format.');
    }

    /** @test */
    function gasd5536()
    {

        $this->markTestIncomplete('Incomplete');
        $this->artisan('conf:check')
            ->containsStringInOutput("Checking the configuration file:\n")
            ->containsStringInOutput('The file githooks.yml has the correct format.');
    }
}
