<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Symfony\Component\Console\Output\OutputInterface;
use Wtyd\GitHooks\App\Commands\Concerns\FormatsOutput;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Output\FlowResultRenderer;
use Wtyd\GitHooks\Output\ResultFormatter;

/**
 * Test double that exposes the (now-thin) FormatsOutput trait. After Phase 2a
 * the trait delegates to {@see FlowResultRenderer}; this double captures the
 * renderer's writeln output via {@see RoutingBufferedOutput} bound to the
 * public arrays below, so existing assertions
 * (`$double->lines`, `$double->warnings`, `$double->infos`) keep working.
 *
 * Tests that need to assert on Symfony decoration assign their own
 * {@see \Symfony\Component\Console\Output\BufferedOutput} to $symfonyOutput;
 * in that case getOutput() returns the BufferedOutput and writes go there.
 *
 * @SuppressWarnings(PHPMD)
 */
class FormatsOutputCommandDouble
{
    use FormatsOutput;

    /** @var array<string, mixed> */
    public array $options = [];

    /** @var string[] */
    public array $warnings = [];

    /** @var string[] */
    public array $lines = [];

    /** @var string[] */
    public array $infos = [];

    public $laravel;

    public ?OutputInterface $symfonyOutput = null;

    private RoutingBufferedOutput $routingOutput;

    public function __construct()
    {
        $this->routingOutput = new RoutingBufferedOutput();
        $this->routingOutput->bindArrays($this->lines, $this->warnings, $this->infos);
    }

    public function getOutput(): OutputInterface
    {
        return $this->symfonyOutput ?? $this->routingOutput;
    }

    public function option(string $name = null)
    {
        return $this->options[$name] ?? null;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function getLaravel()
    {
        return $this->laravel;
    }

    public function callApplyFormat(FlowExecutor $executor, ?FlowPlan $plan = null): void
    {
        $this->applyFormat($executor, $plan);
    }

    public function callRenderFormattedResult(FlowResult $result, ?OptionsConfiguration $options = null): void
    {
        $this->renderFormattedResult($result, $options);
    }

    /**
     * @return array<string, string>
     */
    public function callCollectReportTargets(?OptionsConfiguration $options): array
    {
        return $this->collectReportTargets($options);
    }

    public function callFormatterFor(string $format): ResultFormatter
    {
        return (new FlowResultRenderer($this->laravel))->formatterFor($format);
    }
}
