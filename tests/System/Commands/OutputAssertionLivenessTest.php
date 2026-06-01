<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use PHPUnit\Framework\AssertionFailedError;
use Tests\Utils\TestCase\SystemTestCase;

/**
 * Meta-test (BUG-24): proves the fluent output-assertion path is *live*.
 *
 * The danger this locks down: `$this->containsStringInOutput = [...]` assigned
 * AFTER `$this->artisan(...)->assertExitCode()` is a no-op (the temporary ran and
 * verified at the end of its statement, before the assignment). The fluent form
 * — chained on the command — must instead actually verify the captured output.
 * If a future refactor silently dropped that verification, an absent string would
 * pass and every output assertion in the suite would become a false-green.
 */
class OutputAssertionLivenessTest extends SystemTestCase
{
    /** @test */
    public function fluent_output_assertion_fails_when_the_expected_string_is_absent(): void
    {
        $pending = $this->artisan('list')
            ->containsStringInOutput('XYZ_THIS_STRING_NEVER_APPEARS_IN_THE_OUTPUT_123');

        try {
            $pending->run();
            $this->fail('A fluent output assertion for an absent string must fail, but it passed (dead assertion).');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('was not printed', $e->getMessage());
        } finally {
            // The failing verification threw before flushing this entry; clear it so
            // the ConsoleTestCase tear-down guard does not re-report it as unverified.
            $this->containsStringInOutput = [];
        }
    }
}
