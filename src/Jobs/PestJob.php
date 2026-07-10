<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ThreadCapability;

/**
 * Native `pest` job type. Wraps `vendor/bin/pest` (default) or `php artisan test`
 * (with `runner: artisan`), so Pest — Laravel's default test runner — becomes a
 * first-class job instead of a `custom` script.
 *
 * Extends {@see PhpunitJob} (as {@see ParatestJob} does): Pest is PHPUnit-based,
 * so it reuses the phpunit.xml cache resolution ({@see PhpunitJob::getCachePaths()})
 * and the exit-code verdict (isFixApplied stays false — pass/fail comes from the
 * exit code). Parallelism (`--parallel --processes=N`) integrates with the thread
 * budget, but only when `parallel` is active.
 */
class PestJob extends PhpunitJob
{
    protected const ARGUMENT_MAP = [
        'configuration' => ['flag' => '-c', 'type' => 'value', 'separator' => ' '],
        'config'        => ['flag' => '-c', 'type' => 'value', 'separator' => ' '],
        'group'         => ['flag' => '--group', 'type' => 'value', 'separator' => ' '],
        'exclude-group' => ['flag' => '--exclude-group', 'type' => 'value', 'separator' => ' '],
        'filter'        => ['flag' => '--filter', 'type' => 'value', 'separator' => ' '],
        'parallel'      => ['flag' => '--parallel', 'type' => 'boolean'],
        'processes'     => ['flag' => '--processes', 'type' => 'value', 'separator' => '='],
        'coverage'      => ['flag' => '--coverage', 'type' => 'boolean'],
        'min'           => ['flag' => '--min', 'type' => 'value', 'separator' => '='],
        'only-covered'  => ['flag' => '--only-covered', 'type' => 'boolean'],
        'mutate'        => ['flag' => '--mutate', 'type' => 'boolean'],
        'covered-only'  => ['flag' => '--covered-only', 'type' => 'boolean'],
        'bail'          => ['flag' => '--bail', 'type' => 'boolean'],
        'compact'       => ['flag' => '--compact', 'type' => 'boolean'],
        // Consumed in the constructor (selects the runner); declared here only so
        // conf:check accepts it as a known key. Unset before buildCommand runs, so
        // it never emits a flag.
        'runner'        => ['flag' => '', 'type' => 'value'],
        'paths'         => ['type' => 'paths'],
    ];

    private bool $runnerIsArtisan = false;

    public function __construct(JobConfiguration $config)
    {
        parent::__construct($config);

        $runner = $this->args['runner'] ?? 'binary';
        unset($this->args['runner']);

        if ($runner === 'artisan' && ($config->getConfig()['executable-path'] ?? '') === '') {
            $this->executable = 'php artisan';
            $this->runnerIsArtisan = true;
        }
    }

    public static function getDefaultExecutable(): string
    {
        return 'pest';
    }

    protected function getSubcommand(): string
    {
        return $this->runnerIsArtisan ? 'test' : '';
    }

    /**
     * Pest parallelises with `--parallel --processes=N`. The `processes` count is
     * only meaningful alongside `--parallel`, so the capability is offered to the
     * thread budget only when `parallel` is active; otherwise Pest runs
     * single-process and there is nothing to allocate.
     */
    public function getThreadCapability(): ?ThreadCapability
    {
        if (empty($this->args['parallel'])) {
            return null;
        }
        $current = isset($this->args['processes']) ? (int) $this->args['processes'] : 4;
        return new ThreadCapability('processes', $current);
    }

    public function applyThreadLimit(int $threads): void
    {
        $this->args['processes'] = $threads;
    }
}
