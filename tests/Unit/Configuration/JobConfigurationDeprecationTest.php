<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\Deprecation;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Registry\ToolRegistry;

/**
 * Acceptance criteria for v3.3 step 1 of the camelCase → kebab-case migration
 * for keys inside `jobs.<name>` (spec: spec-design-kebab-case-keys-deprecation).
 */
class JobConfigurationDeprecationTest extends TestCase
{
    private ToolRegistry $toolRegistry;

    private JobRegistry $jobRegistry;

    protected function setUp(): void
    {
        $this->toolRegistry = new ToolRegistry();
        $this->jobRegistry = new JobRegistry();
    }

    /**
     * @test
     * @dataProvider deprecatedKeysProvider
     */
    public function camelcase_key_emits_deprecation(string $oldKey, string $newKey, $value): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan-src', [
            'type'  => 'phpstan',
            'paths' => ['src'],
            $oldKey => $value,
        ], $this->toolRegistry, $result, $this->jobRegistry);

        $this->assertFalse($result->hasErrors(), 'No errors expected for legacy key alone');

        $deprecations = $result->getDeprecations();
        $this->assertCount(1, $deprecations);
        $this->assertSame('phpstan-src', $deprecations[0]->getJob());
        $this->assertSame($oldKey, $deprecations[0]->getOldKey());
        $this->assertSame($newKey, $deprecations[0]->getNewKey());
        $this->assertSame('v4.0', $deprecations[0]->getRemovalVersion());
        $this->assertSame(Deprecation::KIND_CONFIG_KEY_RENAME, $deprecations[0]->getKind());

        $expectedWarning = "Deprecated: '$oldKey' is renamed to '$newKey'. Will be removed in v4.0.";
        $this->assertContains($expectedWarning, $result->getWarnings());
    }

    /**
     * @test
     * @dataProvider deprecatedKeysProvider
     */
    public function kebabcase_canonical_emits_no_deprecation(string $oldKey, string $newKey, $value): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan-src', [
            'type'  => 'phpstan',
            'paths' => ['src'],
            $newKey => $value,
        ], $this->toolRegistry, $result, $this->jobRegistry);

        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getDeprecations());
        $this->assertEmpty($result->getWarnings());
    }

    /** @test */
    public function conflict_emits_error_and_aborts_job(): void
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan-src', [
            'type'             => 'phpstan',
            'paths'            => ['src'],
            'executablePath'   => 'vendor/bin/phpstan',
            'executable-path'  => 'tools/phpstan',
        ], $this->toolRegistry, $result, $this->jobRegistry);

        $this->assertNull($job);
        $this->assertTrue($result->hasErrors());
        $this->assertEmpty($result->getDeprecations());

        $errors = $result->getErrors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(
            "Job 'phpstan-src': conflicting keys 'executablePath' and 'executable-path'.",
            $errors[0]
        );
        $this->assertStringContainsString('kebab-case form is canonical', $errors[0]);
    }

    /** @test */
    public function multiple_legacy_keys_in_same_job_each_produce_a_deprecation(): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('legacy', [
            'type'               => 'phpstan',
            'paths'              => ['src'],
            'executablePath'     => 'vendor/bin/phpstan',
            'otherArguments'     => '--ansi',
            'ignoreErrorsOnExit' => true,
            'failFast'           => false,
        ], $this->toolRegistry, $result, $this->jobRegistry);

        $this->assertCount(4, $result->getDeprecations());
        $oldKeys = array_map(fn (Deprecation $d) => $d->getOldKey(), $result->getDeprecations());
        $this->assertEqualsCanonicalizing(
            ['executablePath', 'otherArguments', 'ignoreErrorsOnExit', 'failFast'],
            $oldKeys
        );
    }

    /** @test */
    public function legacy_camelcase_normalizes_into_kebabcase_keys(): void
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('phpstan-src', [
            'type'           => 'phpstan',
            'paths'          => ['src'],
            'executablePath' => 'vendor/bin/phpstan',
            'otherArguments' => '--ansi',
        ], $this->toolRegistry, $result, $this->jobRegistry);

        $this->assertNotNull($job);
        $config = $job->getConfig();
        $this->assertArrayHasKey('executable-path', $config);
        $this->assertArrayHasKey('other-arguments', $config);
        $this->assertArrayNotHasKey('executablePath', $config);
        $this->assertArrayNotHasKey('otherArguments', $config);
        $this->assertSame('vendor/bin/phpstan', $config['executable-path']);
        $this->assertSame('--ansi', $config['other-arguments']);
    }

    /** @test */
    public function custom_job_accepts_legacy_executablepath_as_script_substitute(): void
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('legacy-script', [
            'type'           => 'custom',
            'executablePath' => 'tools/my-tool.sh',
            'paths'          => ['src'],
        ], $this->toolRegistry, $result, $this->jobRegistry);

        $this->assertNotNull($job);
        $this->assertFalse($result->hasErrors());
        $this->assertCount(1, $result->getDeprecations());
        $this->assertSame('executablePath', $result->getDeprecations()[0]->getOldKey());
    }

    /** @test */
    public function custom_job_kebabcase_executable_path_passes_required_check(): void
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('canonical-script', [
            'type'            => 'custom',
            'executable-path' => 'tools/my-tool.sh',
            'paths'           => ['src'],
        ], $this->toolRegistry, $result, $this->jobRegistry);

        $this->assertNotNull($job);
        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getDeprecations());
    }

    /** @test */
    public function custom_job_without_script_or_executable_path_reports_canonical_error(): void
    {
        $result = new ValidationResult();
        $job = JobConfiguration::fromArray('bad', [
            'type'  => 'custom',
            'paths' => ['src'],
        ], $this->toolRegistry, $result, $this->jobRegistry);

        $this->assertNull($job);
        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString("'script' or 'executable-path' key", $result->getErrors()[0]);
    }

    /** @test */
    public function unknown_camelcase_key_falls_through_to_unknown_key_warning_not_deprecation(): void
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('phpstan-src', [
            'type'        => 'phpstan',
            'paths'       => ['src'],
            'someOldKey'  => 'foo',
        ], $this->toolRegistry, $result, $this->jobRegistry);

        $this->assertEmpty($result->getDeprecations());
        $this->assertNotEmpty($result->getWarnings());
        $combined = implode("\n", $result->getWarnings());
        $this->assertStringContainsString("unknown key 'someOldKey'", $combined);
        $this->assertStringNotContainsString('Deprecated:', $combined);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: mixed}>
     */
    public static function deprecatedKeysProvider(): iterable
    {
        yield 'executablePath'     => ['executablePath', 'executable-path', 'vendor/bin/phpstan'];
        yield 'otherArguments'     => ['otherArguments', 'other-arguments', '--ansi --no-progress'];
        yield 'ignoreErrorsOnExit' => ['ignoreErrorsOnExit', 'ignore-errors-on-exit', true];
        yield 'failFast'           => ['failFast', 'fail-fast', true];
    }
}
