<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PhpcbfJob;

/**
 * Direct coverage for the phpcbf job type. mayApplyFixes() is the producer
 * side of the scoped re-stage contract (3.7): FlowExecutor only captures the
 * staged baseline when a job in the plan declares it, so a fixer silently
 * losing this flag would re-stage the whole index (Infection escaped mutant
 * on PhpcbfJob:26).
 */
class PhpcbfJobTest extends UnitTestCase
{
    private function phpcbf(array $config = []): PhpcbfJob
    {
        return new PhpcbfJob(new JobConfiguration('phpcbf_src', 'phpcbf', $config));
    }

    /** @test */
    public function phpcbf_is_a_supported_job_type()
    {
        $this->assertTrue((new JobRegistry())->isSupported('phpcbf'));
    }

    /** @test */
    public function phpcbf_declares_it_may_apply_fixes()
    {
        $this->assertTrue($this->phpcbf()->mayApplyFixes());
    }

    /**
     * phpcbf exit codes: 0 = nothing to fix, 1 = fixes applied, 2 = failure.
     * Only the exact boundary value 1 means a fix.
     *
     * @test
     * @dataProvider fixAppliedExitCodes
     */
    public function fix_applied_only_on_exit_code_one(int $exitCode, bool $expected)
    {
        $this->assertSame($expected, $this->phpcbf()->isFixApplied($exitCode));
    }

    /** @return array<string, array{0: int, 1: bool}> */
    public function fixAppliedExitCodes(): array
    {
        return [
            'exit 0 (nothing to fix)'   => [0, false],
            'exit 1 (fixes applied)'    => [1, true],
            'exit 2 (failure)'          => [2, false],
        ];
    }

    /** @test */
    public function phpcbf_does_not_support_structured_output()
    {
        $this->assertFalse($this->phpcbf()->supportsStructuredOutput());
        $this->assertFalse($this->phpcbf()->applyStructuredOutputFormat());
    }

    /** @test */
    public function default_executable_is_phpcbf()
    {
        $this->assertSame('phpcbf', PhpcbfJob::getDefaultExecutable());
    }
}
