<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ThreadCapability;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PestJob;

class PestJobTest extends UnitTestCase
{
    /** @param array<string, mixed> $config */
    private function pest(array $config): PestJob
    {
        return new PestJob(new JobConfiguration('pest_suite', 'pest', $config));
    }

    /** @test */
    public function pest_is_a_supported_job_type()
    {
        $this->assertTrue((new JobRegistry())->isSupported('pest'));
    }

    /** @test */
    public function binary_runner_uses_vendor_bin_pest_by_default()
    {
        $command = $this->pest(['paths' => ['tests']])->buildCommand();

        $this->assertMatchesRegularExpression('#^(vendor/bin/)?pest #', $command);
        $this->assertStringNotContainsString('artisan', $command);
    }

    /** @test */
    public function artisan_runner_uses_php_artisan_test()
    {
        $command = $this->pest(['runner' => 'artisan'])->buildCommand();

        $this->assertStringStartsWith('php artisan test', $command);
    }

    /** @test */
    public function explicit_executable_path_wins_over_artisan_runner()
    {
        $command = $this->pest([
            'runner'          => 'artisan',
            'executable-path' => '/usr/local/bin/pest',
        ])->buildCommand();

        $this->assertStringStartsWith('/usr/local/bin/pest', $command);
        $this->assertStringNotContainsString('artisan', $command);
    }

    /** @test */
    public function runner_key_never_leaks_into_the_command()
    {
        $command = $this->pest(['executable-path' => 'vendor/bin/pest', 'runner' => 'binary'])->buildCommand();

        $this->assertSame('vendor/bin/pest', $command);
        $this->assertStringNotContainsString('runner', $command);
    }

    /**
     * @test
     * @dataProvider commandScenarios
     *
     * @param array<string, mixed> $config
     */
    public function pest_builds_the_expected_command(array $config, string $expected)
    {
        $config['executable-path'] = 'vendor/bin/pest';

        $this->assertSame($expected, $this->pest($config)->buildCommand());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public function commandScenarios(): array
    {
        return [
            'bare'                       => [[], 'vendor/bin/pest'],
            'parallel + processes'       => [['parallel' => true, 'processes' => 4], 'vendor/bin/pest --parallel --processes=4'],
            'coverage + min'             => [['coverage' => true, 'min' => 90], 'vendor/bin/pest --coverage --min=90'],
            'coverage + only-covered'    => [['coverage' => true, 'only-covered' => true, 'min' => 80], 'vendor/bin/pest --coverage --min=80 --only-covered'],
            'mutate + covered-only'      => [['mutate' => true, 'covered-only' => true, 'min' => 80, 'bail' => true], 'vendor/bin/pest --min=80 --mutate --covered-only --bail'],
            'compact'                    => [['compact' => true], 'vendor/bin/pest --compact'],
            'config file'                => [['config' => 'phpunit.xml'], 'vendor/bin/pest -c phpunit.xml'],
            'filter'                     => [['filter' => 'UserTest'], 'vendor/bin/pest --filter UserTest'],
            'paths appended last'        => [['coverage' => true, 'paths' => ['app/Domain']], 'vendor/bin/pest --coverage app/Domain'],
        ];
    }

    /** @test */
    public function no_thread_capability_when_parallel_is_off()
    {
        $this->assertNull($this->pest(['coverage' => true])->getThreadCapability());
    }

    /** @test */
    public function thread_capability_is_offered_when_parallel_is_on()
    {
        $capability = $this->pest(['parallel' => true, 'processes' => 6])->getThreadCapability();

        $this->assertInstanceOf(ThreadCapability::class, $capability);
        $this->assertSame('processes', $capability->getArgumentKey());
    }

    /** @test */
    public function apply_thread_limit_sets_the_processes_flag()
    {
        $job = $this->pest(['executable-path' => 'vendor/bin/pest', 'parallel' => true]);
        $job->applyThreadLimit(8);

        $this->assertSame('vendor/bin/pest --parallel --processes=8', $job->buildCommand());
    }

    /**
     * `cores: N` reaches applyThreadLimit() through
     * FlowExecutor::applyExplicitCoresOverrides(), which pins the allocation for
     * every job that declares it — including jobs whose capability is null.
     * Without `--parallel` there is nothing to allocate, so emitting
     * `--processes=N` alone builds a command Pest cannot honour.
     *
     * @test
     */
    public function apply_thread_limit_is_a_no_op_when_parallel_is_off()
    {
        $job = $this->pest(['executable-path' => 'vendor/bin/pest']);
        $job->applyThreadLimit(2);

        $this->assertSame('vendor/bin/pest', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_for_both_runners()
    {
        $binary = $this->pest(['executable-path' => 'vendor/bin/pest']);
        $binary->applyExecutablePrefix('docker exec -i app');
        $this->assertStringStartsWith('docker exec -i app vendor/bin/pest', $binary->buildCommand());

        $artisan = $this->pest(['runner' => 'artisan']);
        $artisan->applyExecutablePrefix('docker exec -i app');
        $this->assertStringStartsWith('docker exec -i app php artisan test', $artisan->buildCommand());
    }
}
